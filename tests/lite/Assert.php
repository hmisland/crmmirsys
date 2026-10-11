<?php

declare(strict_types=1);

/** Failed assertion inside a lite test. */
final class LiteAssertionFailed extends \RuntimeException
{
}

/** Thrown to mark a test skipped (e.g. no local database); reported, never counted as passed. */
final class LiteSkip extends \RuntimeException
{
}

final class LiteAssert
{
    public static function true(mixed $actual, string $message = 'expected true'): void
    {
        if ($actual !== true) {
            throw new LiteAssertionFailed($message . ' (got ' . var_export($actual, true) . ')');
        }
    }

    public static function false(mixed $actual, string $message = 'expected false'): void
    {
        if ($actual !== false) {
            throw new LiteAssertionFailed($message . ' (got ' . var_export($actual, true) . ')');
        }
    }

    public static function same(mixed $expected, mixed $actual, string $message = 'values differ'): void
    {
        if ($expected !== $actual) {
            throw new LiteAssertionFailed($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    public static function contains(string $needle, string $haystack, string $message = 'substring not found'): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new LiteAssertionFailed($message . ": [{$needle}]");
        }
    }

    public static function notContains(string $needle, string $haystack, string $message = 'unexpected substring'): void
    {
        if (str_contains($haystack, $needle)) {
            throw new LiteAssertionFailed($message . ": [{$needle}]");
        }
    }

    /** @param class-string<\Throwable> $class */
    public static function throws(string $class, callable $fn, string $message = 'expected exception'): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                return;
            }
            throw new LiteAssertionFailed($message . ": expected {$class}, got " . get_class($e) . ' — ' . $e->getMessage());
        }

        throw new LiteAssertionFailed($message . ": {$class} was not thrown");
    }
}
