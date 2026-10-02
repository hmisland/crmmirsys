<?php

declare(strict_types=1);

namespace Modules\Notification;

use App\Infrastructure\Repositories\TenantScopedRepository;

final class NotificationCreditRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'notification_credits';
    }

    public function get(int $organizationId): array
    {
        return $this->findOne(
            'SELECT * FROM notification_credits WHERE organization_id = :org_id',
            ['org_id' => $organizationId]
        ) ?? ['organization_id' => $organizationId, 'balance' => '0'];
    }

    /**
     * Row-locks within the caller's own transaction (see
     * NotificationCreditService), same reasoning as
     * WalletRepository::lockForUpdate().
     */
    public function lockForUpdate(int $organizationId): ?array
    {
        return $this->findOne(
            'SELECT * FROM notification_credits WHERE organization_id = :org_id FOR UPDATE',
            ['org_id' => $organizationId]
        );
    }

    public function ensureRow(int $organizationId): void
    {
        if ($this->findOne('SELECT organization_id FROM notification_credits WHERE organization_id = :org_id', ['org_id' => $organizationId]) === null) {
            $this->insertGetId($this->table(), ['organization_id' => $organizationId, 'balance' => 0]);
        }
    }

    public function updateBalance(int $organizationId, string $newBalance): void
    {
        $this->update($this->table(), ['balance' => $newBalance], 'organization_id = :org_id', ['org_id' => $organizationId]);
    }
}
