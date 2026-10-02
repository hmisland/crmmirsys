<?php

declare(strict_types=1);

namespace Modules\Notification\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Repositories\NotificationPlatformSettingsRepository;

final class NotificationSettingsService
{
    public function __construct(private readonly NotificationPlatformSettingsRepository $settings)
    {
    }

    public function get(): array
    {
        return $this->settings->get();
    }

    public function update(array $actingUser, string $baleCost, string $smsCost): void
    {
        if (empty($actingUser['is_super_admin'])) {
            throw new AuthorizationException();
        }

        if (!is_numeric($baleCost) || bccomp($baleCost, '0', 18) < 0) {
            throw new ValidationException(['bale_cost' => ['Must be zero or greater.']]);
        }
        if (!is_numeric($smsCost) || bccomp($smsCost, '0', 18) < 0) {
            throw new ValidationException(['sms_cost' => ['Must be zero or greater.']]);
        }

        $this->settings->updateCosts($baleCost, $smsCost);
    }
}
