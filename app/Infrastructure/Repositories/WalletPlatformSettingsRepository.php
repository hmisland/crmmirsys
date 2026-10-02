<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * Platform-wide, not tenant-scoped — lives in Core (not the Wallet
 * module) since Super Admin's platform cap must be readable even by
 * code that has no organization context at all.
 */
final class WalletPlatformSettingsRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'wallet_platform_settings';
    }

    public function get(): array
    {
        return $this->findOne('SELECT * FROM wallet_platform_settings WHERE id = 1') ?? [
            'id' => 1, 'currency_id' => null, 'min_topup' => '0', 'max_topup' => '0',
        ];
    }

    public function updateSettings(int $currencyId, string $minTopup, string $maxTopup): void
    {
        $this->update($this->table(), [
            'currency_id' => $currencyId,
            'min_topup' => $minTopup,
            'max_topup' => $maxTopup,
        ], 'id = 1', []);
    }
}
