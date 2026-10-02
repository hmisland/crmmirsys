<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class NotificationPlatformSettingsRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'notification_platform_settings';
    }

    public function get(): array
    {
        return $this->findOne('SELECT * FROM notification_platform_settings WHERE id = 1') ?? [
            'id' => 1, 'bale_cost' => '0', 'sms_cost' => '500',
        ];
    }

    public function updateCosts(string $baleCost, string $smsCost): void
    {
        $this->update($this->table(), ['bale_cost' => $baleCost, 'sms_cost' => $smsCost], 'id = 1', []);
    }
}
