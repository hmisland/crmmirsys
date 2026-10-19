<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

/** Outcome of a connection test: ok, or a translation key describing the failure (never a raw provider message with secrets). */
final class IntegrationResult
{
    private function __construct(public readonly bool $ok, public readonly ?string $errorKey)
    {
    }

    public static function ok(): self
    {
        return new self(true, null);
    }

    public static function failed(string $errorKey): self
    {
        return new self(false, $errorKey);
    }
}
