<?php

declare(strict_types=1);

namespace Modules\Notification\Gateways;

/**
 * No real SMS provider is connected yet (explicitly deferred, per the
 * request that built this module — "برای پیامک جاش رو طراحی کن، فعلاً
 * سرویسی نداریم وصل کنیم"). This stub exists so the rest of the
 * system (the opt-in checkbox, the credit-charging flow, the log)
 * works end-to-end today via Bale alone, and connecting a real
 * provider later — Kavenegar, Ghasedak, or any other Iranian SMS
 * gateway — means writing ONE new class that implements
 * NotificationGatewayInterface and swapping it in
 * NotificationModule::register(), the same pattern already used for
 * ZarinPal (Wallet) and Bale (this module) — nothing else in the
 * codebase needs to change.
 */
final class NullSmsGateway implements NotificationGatewayInterface
{
    public function channel(): string
    {
        return 'sms';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function send(string $destination, string $message): NotificationSendResult
    {
        return new NotificationSendResult(false, 'No SMS provider is connected yet.');
    }
}
