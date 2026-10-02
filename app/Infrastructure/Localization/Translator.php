<?php

declare(strict_types=1);

namespace App\Infrastructure\Localization;

/**
 * Per Phase 0 §21/§49-50 and the Phase 1 stopgap this replaces: each
 * supported locale is one plain PHP file under /lang returning a flat
 * key => string array — deliberately NOT a database table, so fixing
 * a bad translation is a direct file edit, no admin UI or migration
 * needed, and adding a new language is dropping in one new file (see
 * SUPPORTED_LOCALES below) with no code change beyond that one line.
 *
 * Falls back to English for any key missing in the active locale, so
 * a partially-translated language degrades to English for the gaps
 * rather than showing the raw key or breaking the page.
 */
final class Translator
{
    /** Every locale the language switcher offers. Adding a language = add its file under /lang AND a line here. */
    public const SUPPORTED_LOCALES = ['en', 'fa', 'ar', 'tr'];

    /** Locales that read right-to-left. */
    private const RTL_LOCALES = ['fa', 'ar'];

    private array $strings = [];
    private array $fallback = [];

    public function __construct(private readonly string $locale, string $langPath)
    {
        $this->fallback = $this->loadFile($langPath, 'en');
        $this->strings = $locale === 'en' ? $this->fallback : $this->loadFile($langPath, $locale);
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function direction(): string
    {
        return in_array($this->locale, self::RTL_LOCALES, true) ? 'rtl' : 'ltr';
    }

    public function get(string $key, array $replace = []): string
    {
        $value = $this->strings[$key] ?? $this->fallback[$key] ?? $key;

        foreach ($replace as $search => $replacement) {
            $value = str_replace(':' . $search, (string) $replacement, $value);
        }

        return $value;
    }

    private function loadFile(string $langPath, string $locale): array
    {
        $file = rtrim($langPath, '/') . '/' . $locale . '.php';

        if (!is_file($file)) {
            return [];
        }

        $strings = require $file;

        return is_array($strings) ? $strings : [];
    }
}
