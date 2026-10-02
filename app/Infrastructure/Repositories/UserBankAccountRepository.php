<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class UserBankAccountRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'user_bank_accounts';
    }

    public function forUser(int $userId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM user_bank_accounts WHERE user_id = :user_id ORDER BY id',
            ['user_id' => $userId]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM user_bank_accounts WHERE id = :id', ['id' => $id]);
    }

    public function create(array $data): int
    {
        return $this->tenantInsertGetId($data);
    }

    public function deleteAccount(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
