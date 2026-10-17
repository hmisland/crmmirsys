<?php

declare(strict_types=1);

namespace Modules\Marketing\Channels;

/**
 * Optional companion of MarketingChannelInterface for channels that
 * address a contact by mobile number and send plain text (like SMS) but
 * with their own number format and rules — e.g. Bale (MarketingBale).
 * SMS and email keep their built-in handling and do not implement it.
 *
 * The audience reads the same phone columns as SMS for such a channel;
 * addresses, duplicates and opt-outs use classifyAddress()'s result.
 */
interface PhoneTextChannel
{
    /**
     * @return array{0: ?string, 1: ?string} [normalized address, or null with the skip
     *         reason: 'invalid_address' | 'unsupported_recipient']
     */
    public function classifyAddress(string $raw): array;

    /** Application limit on the message text (characters). */
    public function maxTextLength(): int;

    /** Whether the approver must confirm that the recipients agreed to receive messages on this channel. */
    public function requiresConsentConfirmation(): bool;

    /** Approvals one user may make per hour on this channel (0 = no limit) — guards against accidental repeats. */
    public function approvalsPerUserPerHour(): int;
}
