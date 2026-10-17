<?php

declare(strict_types=1);

namespace Modules\MarketingBale\Safir;

/**
 * What one send_message request means for the message, after mapping
 * Safir's documented error codes (docs.bale.ai/safir, "مقادیر Error
 * Code") and the HTTP-level outcome:
 *
 *  accepted    — message_id returned, no error_data. NOT "delivered":
 *                Safir documents no delivery or read reports.
 *  failed      — definitely rejected, nothing sent (4 InvalidInput,
 *                8 InvalidPhone, other HTTP 4xx). No automatic retry.
 *  unsupported — 17 NotBaleUser: the number has no Bale account.
 *  retry       — certainly not sent, safe to resend with the same
 *                request_id: 3 RateLimitExceeded, HTTP 429, or a
 *                connection that failed before the request left.
 *  pause       — the account cannot send right now: 20 PaymentRequired,
 *                21 MaximumContactLimitReached, key rejected (HTTP
 *                401/403). Nothing sent; the batch stops.
 *  unknown     — may have been sent: timeout / connection lost after the
 *                request left, HTTP 5xx, 2 InternalServerError, an
 *                undocumented code, or an unreadable answer. Never
 *                retried automatically.
 *
 * $reason is a short fixed label (e.g. "safir_8_invalid_phone"); it never
 * contains the key, the number or the text, and never Safir's free-text
 * description.
 */
final class SafirOutcome
{
    public const ACCEPTED = 'accepted';
    public const FAILED = 'failed';
    public const UNSUPPORTED = 'unsupported';
    public const RETRY = 'retry';
    public const PAUSE = 'pause';
    public const UNKNOWN = 'unknown';

    /** Documented Safir error codes → [kind, reason label]. */
    public const CODES = [
        2 => [self::UNKNOWN, 'safir_2_internal_error'],
        3 => [self::RETRY, 'safir_3_rate_limited'],
        4 => [self::FAILED, 'safir_4_invalid_input'],
        8 => [self::FAILED, 'safir_8_invalid_phone'],
        17 => [self::UNSUPPORTED, 'safir_17_not_bale_user'],
        20 => [self::PAUSE, 'safir_20_payment_required'],
        21 => [self::PAUSE, 'safir_21_contact_limit'],
    ];

    public function __construct(
        public readonly string $kind,
        public readonly string $reason,
        public readonly ?string $messageId = null,
        public readonly ?int $code = null,
        public readonly ?int $httpStatus = null,
    ) {
    }

    public function isAccepted(): bool
    {
        return $this->kind === self::ACCEPTED;
    }

    /** "Safir: <reason>" for message rows and logs — safe to store and show. */
    public function label(): string
    {
        return 'Safir: ' . $this->reason . ($this->httpStatus !== null ? ' (HTTP ' . $this->httpStatus . ')' : '');
    }
}
