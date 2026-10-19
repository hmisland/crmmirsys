<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

/**
 * Result of one handleJob():
 *  - done: success. $syncState (if not null) is merged into the
 *    connection's sync_state_json; $more = true queues a continuation
 *    job for the same resource (next page/batch) instead of doing
 *    everything in one cron run;
 *  - retry: temporary failure (network, 429, 5xx) — retried later with
 *    back-off until max_attempts;
 *  - failed: permanent (bad credentials, invalid payload) — not retried.
 * $errorKey is a translation key, never a raw response body.
 */
final class JobOutcome
{
    public const DONE = 'done';
    public const RETRY = 'retry';
    public const FAILED = 'failed';

    private function __construct(
        public readonly string $status,
        public readonly ?string $errorKey = null,
        public readonly ?array $syncState = null,
        public readonly bool $more = false,
        public readonly int $processed = 0,
    ) {
    }

    public static function done(int $processed = 0, ?array $syncState = null, bool $more = false): self
    {
        return new self(self::DONE, null, $syncState, $more, $processed);
    }

    public static function retry(string $errorKey): self
    {
        return new self(self::RETRY, $errorKey);
    }

    public static function failed(string $errorKey): self
    {
        return new self(self::FAILED, $errorKey);
    }
}
