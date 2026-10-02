<?php

declare(strict_types=1);

namespace Modules\Notification\Gateways;

interface NotificationGatewayInterface
{
    /** 'bale' or 'sms' — matches notification_logs.channel and notification_platform_settings' cost columns. */
    public function channel(): string;

    public function isConfigured(): bool;

    public function send(string $destination, string $message): NotificationSendResult;
}
