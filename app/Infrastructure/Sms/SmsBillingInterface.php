<?php

declare(strict_types=1);

namespace App\Infrastructure\Sms;

/**
 * How an organization pays for SMS sent with the PLATFORM's provider
 * account. Bound by the Notification module (prepaid credit charged
 * from Wallet). Organizations using their own provider key are never
 * billed here.
 */
interface SmsBillingInterface
{
    /** Price of one SMS part (decimal string, wallet currency). */
    public function unitPrice(): string;

    /** Current prepaid balance of the organization (decimal string). */
    public function balance(int $organizationId): string;

    /** Deducts $units parts; false (nothing deducted) when the balance is too low. */
    public function tryCharge(int $organizationId, int $units, string $context): bool;

    /** Gives back a charge whose send failed. */
    public function refund(int $organizationId, int $units, string $context): void;
}
