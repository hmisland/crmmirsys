<?php

declare(strict_types=1);

namespace App\Infrastructure\Sms;

final class SmsResult
{
    /**
     * @param string[] $messageIds provider message ids, one per accepted receptor
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $errorMessage = null,
        public readonly ?int $statusCode = null,
        public readonly array $messageIds = [],
        public readonly int $cost = 0,
    ) {
    }

    public static function failure(string $message, ?int $statusCode = null): self
    {
        return new self(false, $message, $statusCode);
    }
}
