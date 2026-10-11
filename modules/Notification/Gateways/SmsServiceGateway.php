<?php

declare(strict_types=1);

namespace Modules\Notification\Gateways;

use App\Application\Services\SmsService;

/**
 * The Notification module's SMS channel, delivered through the core
 * SmsService (Kavenegar). Credit charging stays in this module
 * (NotificationDispatchService); SmsService only sends and logs.
 * Replaces NullSmsGateway now that a real provider exists.
 */
final class SmsServiceGateway implements NotificationGatewayInterface
{
    public function __construct(private readonly SmsService $sms)
    {
    }

    public function channel(): string
    {
        return 'sms';
    }

    public function isConfigured(): bool
    {
        return $this->sms->isConfigured();
    }

    public function send(string $destination, string $message): NotificationSendResult
    {
        $result = $this->sms->send($destination, $message, 'notifications');

        return new NotificationSendResult($result->success, $result->errorMessage);
    }
}
