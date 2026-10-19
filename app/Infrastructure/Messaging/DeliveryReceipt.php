<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

/**
 * Deliverer's answer: accepted (sent or queued for sending), retry
 * (temporary: provider down, rate limited), or failed (permanent:
 * invalid address, recipient opted out). $errorKey is a translation key.
 */
final class DeliveryReceipt
{
    public const ACCEPTED = 'accepted';
    public const RETRY = 'retry';
    public const FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $errorKey = null,
    ) {
    }

    public static function accepted(?string $providerReference = null): self
    {
        return new self(self::ACCEPTED, $providerReference);
    }

    public static function retry(string $errorKey): self
    {
        return new self(self::RETRY, null, $errorKey);
    }

    public static function failed(string $errorKey): self
    {
        return new self(self::FAILED, null, $errorKey);
    }
}
