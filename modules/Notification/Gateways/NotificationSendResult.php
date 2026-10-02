<?php

declare(strict_types=1);

namespace Modules\Notification\Gateways;

final class NotificationSendResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $errorMessage,
    ) {
    }
}
