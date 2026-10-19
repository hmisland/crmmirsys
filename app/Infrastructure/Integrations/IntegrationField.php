<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

/**
 * One setting on a connection form. A "secret" field is stored
 * encrypted in credentials_encrypted, never shown again (the form only
 * shows a hint), and left unchanged when submitted empty.
 */
final class IntegrationField
{
    public const TEXT = 'text';
    public const URL = 'url';
    public const SECRET = 'secret';
    public const NUMBER = 'number';

    public function __construct(
        public readonly string $name,
        public readonly string $labelKey,
        public readonly string $type = self::TEXT,
        public readonly bool $required = true,
        public readonly ?string $hintKey = null,
        public readonly int $maxLength = 255,
    ) {
    }

    public function isSecret(): bool
    {
        return $this->type === self::SECRET;
    }
}
