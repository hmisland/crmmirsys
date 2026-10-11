<?php

declare(strict_types=1);

namespace Modules\Notification\Services;

use App\Infrastructure\Repositories\NotificationPlatformSettingsRepository;
use App\Infrastructure\Sms\SmsBillingInterface;

/**
 * Pays for SMS sent with the platform's Kavenegar account out of the
 * organization's prepaid notification credit (which org admins charge
 * from their Wallet, topped up through the payment gateway). Price per
 * SMS part = the Super Admin's sms_cost on /admin/notifications/settings.
 * Organizations using their own Kavenegar key never reach this.
 */
final class NotificationSmsBilling implements SmsBillingInterface
{
    public function __construct(
        private readonly NotificationCreditService $credits,
        private readonly NotificationPlatformSettingsRepository $platformSettings,
    ) {
    }

    public function unitPrice(): string
    {
        return (string) ($this->platformSettings->get()['sms_cost'] ?? '0');
    }

    public function balance(int $organizationId): string
    {
        return $this->credits->balance($organizationId);
    }

    public function tryCharge(int $organizationId, int $units, string $context): bool
    {
        return $this->credits->tryDeduct($organizationId, $this->amount($units), 'sms', null);
    }

    public function refund(int $organizationId, int $units, string $context): void
    {
        $this->credits->refund($organizationId, $this->amount($units), 'SMS not delivered by provider' . ($context !== '' ? " ({$context})" : ''));
    }

    private function amount(int $units): string
    {
        return bcmul($this->unitPrice(), (string) max(1, $units), 18);
    }
}
