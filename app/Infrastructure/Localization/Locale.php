<?php

declare(strict_types=1);

namespace App\Infrastructure\Localization;

/**
 * Deliberately mirrors App\Infrastructure\Security\CurrentUser's
 * static-holder pattern (see that class's own docblock) — set once
 * per request by Application::handle(), read from anywhere without
 * threading a Translator instance through every function call,
 * including the global t() helper in Theme/functions.php which has
 * no constructor to inject one into.
 */
final class Locale
{
    private static ?Translator $translator = null;

    public static function set(Translator $translator): void
    {
        self::$translator = $translator;
    }

    public static function get(): ?Translator
    {
        return self::$translator;
    }

    public static function reset(): void
    {
        self::$translator = null;
    }
}
