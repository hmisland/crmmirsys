<?php

declare(strict_types=1);

namespace Modules\Wallet;

use App\Infrastructure\Repositories\TenantScopedRepository;

final class WalletRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'wallets';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM wallets WHERE id = :id', ['id' => $id]);
    }

    public function findForUser(int $userId, int $currencyId): ?array
    {
        return $this->tenantFindOne(
            'SELECT * FROM wallets WHERE user_id = :user_id AND currency_id = :currency_id',
            ['user_id' => $userId, 'currency_id' => $currencyId]
        );
    }

    public function create(int $userId, int $currencyId): int
    {
        return $this->tenantInsertGetId([
            'user_id' => $userId,
            'currency_id' => $currencyId,
            'asset_type' => 'fiat',
            'balance' => 0,
        ]);
    }

    /**
     * Row-locks the wallet within the caller's own transaction (see
     * WalletService) so two concurrent credits/debits on the same
     * wallet can never race — the second waits for the first's
     * transaction to commit before it can even read the balance.
     */
    public function lockForUpdate(int $walletId): ?array
    {
        return $this->tenantFindOne('SELECT * FROM wallets WHERE id = :id FOR UPDATE', ['id' => $walletId]);
    }

    public function updateBalance(int $walletId, string $newBalance): void
    {
        $this->tenantUpdate(['balance' => $newBalance], 'id = :id', ['id' => $walletId]);
    }
}
