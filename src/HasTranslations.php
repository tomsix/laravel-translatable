<?php

namespace Spatie\Translatable;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Str;
use Spatie\Translatable\Events\TranslationHasBeenSetEvent;
use Spatie\Translatable\Exceptions\AttributeIsNotTranslatable;

trait HasTranslations
{
    protected string $mainLocale;

    protected ?string $translationLocale = null;

    public function initializeHasTranslations(): void
    {
        $this->mainLocale = app(Translatable::class)->mainLocale;
    }

    public static function usingLocale(string $locale): self
    {
        return (new self())->setLocale($locale);
    }

    public function useFallbackLocale(): bool
    {
        if (property_exists($this, 'useFallbackLocale')) {
            return $this->useFallbackLocale;
        }

        return true;
    }

    public function getAttributeValue($key): mixed
    {
        if (! $this->isTranslatableAttribute($key)) {
            return parent::getAttributeValue($key);
        }

        return $this->getTranslation($key, $this->getLocale(), $this->useFallbackLocale());
    }

    public function setAttribute($key, $value)
    {
        if ($this->isTranslatableAttribute($key) && is_array($value)) {
            return $this->setTranslations($key, $value);
        }

        // Pass arrays and untranslatable attributes to the parent method.
        if (! $this->isTranslatableAttribute($key) || is_array($value)) {
            return parent::setAttribute($key, $value);
        }

        // If the attribute is translatable and not already translated, set a
        // translation for the current app locale.
        return $this->setTranslation($key, $this->getLocale(), $value);
    }

    public function translate(string $key, string $locale = '', bool $useFallbackLocale = true): mixed
    {
        return $this->getTranslation($key, $locale, $useFallbackLocale);
    }

    public function getTranslation(string $key, string $locale, bool $useFallbackLocale = true): mixed
    {
        $normalizedLocale = $this->normalizeLocale($key, $locale, $useFallbackLocale);

        $isKeyMissingFromLocale = ($locale !== $normalizedLocale);

        $translatableConfig = app(Translatable::class);

        $translation = $this->getTranslations($key)[$normalizedLocale] ?? '';

        if ($isKeyMissingFromLocale && $translatableConfig->missingKeyCallback) {
            try {
                $callbackReturnValue = (app(Translatable::class)->missingKeyCallback)($this, $key, $locale, $translation, $normalizedLocale);
                if (is_string($callbackReturnValue)) {
                    $translation = $callbackReturnValue;
                }
            } catch (Exception) {
                //prevent the fallback to crash
            }
        }

        if ($this->hasGetMutator($key)) {
            return $this->mutateAttribute($key, $translation);
        }

        if($this->hasAttributeMutator($key)){
            return $this->mutateAttributeMarkedAttribute($key, $translation);
        }

        return $translation;
    }

    public function getTranslationWithFallback(string $key, string $locale): mixed
    {
        return $this->getTranslation($key, $locale, true);
    }

    public function getTranslationWithoutFallback(string $key, string $locale): mixed
    {
        return $this->getTranslation($key, $locale, false);
    }

    public function getTranslations(string $key = null, array $allowedLocales = null): array
    {
        if ($key !== null) {
            $this->guardAgainstNonTranslatableAttribute($key);

            // Combine the main locale value with the translated values
            $translations = [
                $this->mainLocale => $this->getAttributes()[$key] ?? '',
                ...$this->getTranslationsFromString($this->getAttributes()[$this->getTranslationKey($key)] ?? ''),
            ];

            return array_filter(
                $translations,
                fn ($value, $locale) => $this->filterTranslations($value, $locale, $allowedLocales),
                ARRAY_FILTER_USE_BOTH,
            );
        }

        return array_reduce($this->getTranslatableAttributes(), function ($result, $item) use ($allowedLocales) {
            $result[$item] = $this->getTranslations($item, $allowedLocales);

            return $result;
        });
    }

    protected function getTranslationsFromString(string $value): array
    {
        preg_match_all(
            "/<(?'locale'[^>]+)>(?'text'(.*))<\/\g{locale}>|i/",
            $value,
            $pieces, PREG_UNMATCHED_AS_NULL | PREG_SET_ORDER
        );

        $translations = [];

        foreach ($pieces as ['locale' => $locale, 'text' => $text]) {
            $translations[$locale] = $text;
        }

        return $translations;
    }

    /**
     * @param array<string, string> $translations
     */
    protected function asTranslationsString(array $translations): string
    {
        return collect($translations)
            ->filter(fn ($value, string $locale) => $locale !== $this->mainLocale)
            ->map(fn ($value, string $locale) => "<$locale>$value</$locale>")
            ->implode('');
    }

    public function setTranslation(string $key, string $locale, $value): self
    {
        $this->guardAgainstNonTranslatableAttribute($key);

        $translations = $this->getTranslations($key);

        $oldValue = $translations[$locale] ?? '';

        if ($this->hasSetMutator($key)) {
            $method = 'set'.Str::studly($key).'Attribute';

            $this->{$method}($value, $locale);

            $value = $this->attributes[$key];
        }
        elseif($this->hasAttributeSetMutator($key)) { // handle new attribute mutator
            $this->setAttributeMarkedMutatedAttributeValue($key, $value);

            $value = $this->attributes[$key];
        }

        $translations[$locale] = $value;

        $this->attributes[$key] = array_key_exists($this->mainLocale, $translations)
            ? $translations[$this->mainLocale]
            : '';

        $this->attributes[$this->getTranslationKey($key)] = $this->asTranslationsString($translations);

        event(new TranslationHasBeenSetEvent($this, $key, $locale, $oldValue, $value));

        return $this;
    }

    public function setTranslations(string $key, array $translations): static
    {
        $this->guardAgainstNonTranslatableAttribute($key);

        if (count($translations) === 0) {
            $this->attributes[$key] = null;
            $this->attributes[$this->getTranslationKey($key)] = null;

            return $this;
        }

        foreach ($translations as $locale => $translation) {
            $this->setTranslation($key, $locale, $translation);
        }

        return $this;
    }

    public function forgetTranslation(string $key, string $locale): static
    {
        $translations = $this->getTranslations($key);

        $translations[$locale] = null;

        $this->setTranslations($key, $translations);

        return $this;
    }

    public function forgetTranslations(string $key): static
    {
        $this->guardAgainstNonTranslatableAttribute($key);

        collect($this->getTranslatedLocales($key))->each(function (string $locale) use ($key) {
            $this->forgetTranslation($key, $locale);
        });

        return $this;
    }

    public function forgetAllTranslations(string $locale): static
    {
        collect($this->getTranslatableAttributes())->each(function (string $attribute) use ($locale) {
            $this->forgetTranslation($attribute, $locale);
        });

        return $this;
    }

    public function getTranslatedLocales(string $key): array
    {
        return array_keys($this->getTranslations($key));
    }

    public function isTranslatableAttribute(string $key): bool
    {
        return in_array($key, $this->getTranslatableAttributes());
    }

    public function hasTranslation(string $key, string $locale = null): bool
    {
        $locale = $locale ?: $this->getLocale();

        return isset($this->getTranslations($key)[$locale]);
    }

    public function replaceTranslations(string $key, array $translations): static
    {
        foreach ($this->getTranslatedLocales($key) as $locale) {
            $this->forgetTranslation($key, $locale);
        }

        $this->setTranslations($key, $translations);

        return $this;
    }

    protected function guardAgainstNonTranslatableAttribute(string $key): void
    {
        if (! $this->isTranslatableAttribute($key)) {
            throw AttributeIsNotTranslatable::make($key, $this);
        }
    }

    protected function normalizeLocale(string $key, string $locale, bool $useFallbackLocale): string
    {
        $translatedLocales = $this->getTranslatedLocales($key);

        if (in_array($locale, $translatedLocales)) {
            return $locale;
        }

        if (! $useFallbackLocale) {
            return $locale;
        }

        if (method_exists($this, 'getFallbackLocale')) {
            $fallbackLocale = $this->getFallbackLocale();
        }

        $fallbackConfig = app(Translatable::class);

        $fallbackLocale ??= $fallbackConfig->fallbackLocale ?? config('app.fallback_locale');

        if (! is_null($fallbackLocale) && in_array($fallbackLocale, $translatedLocales)) {
            return $fallbackLocale;
        }

        if (! empty($translatedLocales) && $fallbackConfig->fallbackAny) {
            return $translatedLocales[0];
        }

        return $locale;
    }

    protected function filterTranslations(mixed $value = null, string $locale = null, array $allowedLocales = null): bool
    {
        if ($value === null) {
            return false;
        }

        if ($value === '') {
            return false;
        }

        if ($allowedLocales === null) {
            return true;
        }

        if (! in_array($locale, $allowedLocales)) {
            return false;
        }

        return true;
    }

    public function setLocale(string $locale): static
    {
        $this->translationLocale = $locale;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->translationLocale ?: config('app.locale');
    }

    public function getTranslatableAttributes(): array
    {
        return property_exists($this, 'translatable') && is_array($this->translatable)
            ? $this->translatable
            : $this->getGuessedTranslatableAttributes();
    }

    protected function getGuessedTranslatableAttributes(): array
    {
        return collect($this->getAttributes())
            ->keys()
            ->filter(fn(string $key) => array_key_exists($this->getTranslationKey($key), $this->getAttributes()))
            ->values()
            ->toArray();
    }

    protected function getTranslationKey(string $key)
    {
        return $key . 'Translations';
    }

    public function translations(): Attribute
    {
        return Attribute::get(function () {
            return collect($this->getTranslatableAttributes())
                ->mapWithKeys(function (string $key) {
                    return [$key => $this->getTranslations($key)];
                })
                ->toArray();
        });
    }

    public function locales(): array
    {
        return array_unique(
            array_reduce($this->getTranslatableAttributes(), function ($result, $item) {
                return array_merge($result, $this->getTranslatedLocales($item));
            }, [])
        );
    }

    public function scopeWhereLocale(Builder $query, string $column, string $locale): void
    {
        if ($locale === $this->mainLocale) {
            $query->whereNotNull($column);
            return;
        }

        $query->where($this->getTranslationKey($column), 'like', "%<$locale>%</$locale>%");
    }

    public function scopeWhereLocales(Builder $query, string $column, array $locales): void
    {
        $query->where(function (Builder $query) use ($column, $locales) {
            foreach ($locales as $locale) {
                $this->scopeWhereLocale($query, $column, $locale);
            }
        });
    }
}
