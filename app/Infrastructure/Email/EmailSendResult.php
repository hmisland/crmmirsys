<?php

declare(strict_types=1);

namespace App\Infrastructure\Email;

/**
 * Outcome of handing one email to a provider. "success" means ACCEPTED
 * by the provider — never "delivered"; delivery is only known from
 * provider events (webhooks).
 *
 * retryable: the provider did not take the message (rate limit, outage,
 * connection refused) and the same message may be submitted again.
 * ambiguous: the request may have been processed (timeout) — retrying
 * automatically could send it twice, so callers must not.
 */
final class EmailSendResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $messageId = null,
        public readonly ?string $errorMessage = null,
        public readonly ?int $statusCode = null,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfterSeconds = null,
        public readonly bool $ambiguous = false,
    ) {
    }

    public static function accepted(?string $messageId, int $statusCode = 200): self
    {
        return new self(true, $messageId, null, $statusCode);
    }

    public static function failure(string $message, ?int $statusCode = null, bool $retryable = false, ?int $retryAfter = null, bool $ambiguous = false): self
    {
        return new self(false, null, $message, $statusCode, $retryable, $retryAfter, $ambiguous);
    }
}
