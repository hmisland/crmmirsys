<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

/**
 * What a provider decided about one webhook request:
 *  - rejected: signature missing/invalid → 401, nothing queued;
 *  - ignored: authentic but nothing to do (ping, unsupported topic) → 200;
 *  - queue: authentic; queue one job for $resource/$externalId with the
 *    (already verified) payload. $dedupeKey makes a replay a no-op.
 */
final class WebhookReceipt
{
    public const REJECTED = 'rejected';
    public const IGNORED = 'ignored';
    public const QUEUE = 'queue';

    private function __construct(
        public readonly string $decision,
        public readonly ?string $resource = null,
        public readonly ?string $externalId = null,
        public readonly ?string $payload = null,
        public readonly ?string $dedupeKey = null,
    ) {
    }

    public static function rejected(): self
    {
        return new self(self::REJECTED);
    }

    public static function ignored(): self
    {
        return new self(self::IGNORED);
    }

    public static function queue(string $resource, string $externalId, ?string $payload, string $dedupeKey): self
    {
        return new self(self::QUEUE, $resource, $externalId, $payload, $dedupeKey);
    }
}
