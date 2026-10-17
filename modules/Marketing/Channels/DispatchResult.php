<?php

declare(strict_types=1);

namespace Modules\Marketing\Channels;

/**
 * Outcome of handing one campaign message to a channel's provider.
 * success = accepted by the provider (NOT delivered).
 * retryable = certainly not taken; may be resubmitted automatically.
 * ambiguous = may have been taken; never resubmitted automatically.
 *
 * Optional (default off, so existing channels are unchanged):
 * stopBatch = stop this worker batch now (provider rate limit);
 * pauseReason = nothing was sent and the account cannot send for now
 *   (no credit, contact limit, key refused): the row goes back to the
 *   queue without using an attempt, the batch stops and the campaign
 *   shows this translation key;
 * skipReason = the recipient cannot be served on this channel (row
 *   becomes "skipped" with this reason, e.g. unsupported_recipient).
 */
final class DispatchResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $provider,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $error = null,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfterSeconds = null,
        public readonly bool $ambiguous = false,
        public readonly bool $stopBatch = false,
        public readonly ?string $pauseReason = null,
        public readonly ?string $skipReason = null,
    ) {
    }
}
