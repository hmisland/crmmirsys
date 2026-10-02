<?php

declare(strict_types=1);

namespace Modules\Notification;

use App\Infrastructure\Repositories\TenantScopedRepository;

final class NotificationCreditTransactionRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'notification_credit_transactions';
    }

    public function record(
        string $type,
        string $amount,
        string $balanceAfter,
        ?string $referenceType,
        ?int $referenceId,
        ?string $description,
        ?int $createdByUserId
    ): int {
        return $this->tenantInsertGetId([
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $description,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    public function recent(int $limit = 50): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM notification_credit_transactions WHERE 1 = 1 ORDER BY id DESC LIMIT ' . max(1, min(200, $limit))
        );
    }
}
