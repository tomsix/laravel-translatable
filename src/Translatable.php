<?php

namespace Spatie\Translatable;

use Closure;

class Translatable
{
    public string $mainLocale;

    /*
     * If a translation has not been set for a given locale, use this locale instead.
     */
    public ?string $fallbackLocale;

    /*
     * If a translation has not been set for a given locale and the fallback locale,
     * any other locale will be chosen instead.
     */
    public bool $fallbackAny = false;

    public ?Closure $missingKeyCallback = null;

    public function __construct(string $mainLocale)
    {
        $this->mainLocale = $mainLocale;
    }
    public function fallback(
        ?string $fallbackLocale = null,
        ?bool $fallbackAny = false,
        $missingKeyCallback = null
    ): self {
        $this->fallbackLocale = $fallbackLocale;
        $this->fallbackAny = $fallbackAny;
        $this->missingKeyCallback = $missingKeyCallback;

        return $this;
    }
}
