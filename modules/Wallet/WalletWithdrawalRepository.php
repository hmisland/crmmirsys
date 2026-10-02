<?php

declare(strict_types=1);

namespace Modules\Wallet;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

final class WalletWithdrawalRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'wallet_withdrawal_requests';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM wallet_withdrawal_requests WHERE id = :id', ['id' => $id]);
    }

    public function forWallet(int $walletId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM wallet_withdrawal_requests WHERE wallet_id = :wallet_id ORDER BY id DESC',
            ['wallet_id' => $walletId]
        );
    }

    /**
     * Correlated subquery for the requester's name, no JOIN — the
     * subquery here has its own WHERE too, so (same fix as
     * WalletLedgerRepository::forOrganizationWithOwnerNames()) this
     * must bypass tenantFindMany()'s naive first-WHERE scoping and
     * qualify the org condition explicitly instead.
     */
    public function pendingForOrganization(): array
    {
        $orgScoped = !SuperAdminContext::isActive();
        $where = $orgScoped
            ? 'wallet_withdrawal_requests.organization_id = :org_id AND wallet_withdrawal_requests.status = "pending"'
            : 'wallet_withdrawal_requests.status = "pending"';
        $params = $orgScoped ? ['org_id' => OrganizationContext::id()] : [];

        return $this->findMany(
            "SELECT wallet_withdrawal_requests.*,
                (SELECT CONCAT(u.first_name, ' ', COALESCE(u.last_name, ''))
                 FROM users u WHERE u.id = wallet_withdrawal_requests.user_id) AS user_name
             FROM wallet_withdrawal_requests
             WHERE {$where}
             ORDER BY requested_at",
            $params
        );
    }

    public function create(int $walletId, int $userId, string $amount, string $destinationInfo): int
    {
        return $this->tenantInsertGetId([
            'wallet_id' => $walletId,
            'user_id' => $userId,
            'amount' => $amount,
            'destination_info' => $destinationInfo,
            'status' => 'pending',
        ]);
    }

    public function setStatus(int $id, string $status, ?string $adminNote, ?int $processedByUserId): void
    {
        $this->tenantUpdate([
            'status' => $status,
            'admin_note' => $adminNote,
            'processed_at' => date('Y-m-d H:i:s'),
            'processed_by_user_id' => $processedByUserId,
        ], 'id = :id', ['id' => $id]);
    }
}
