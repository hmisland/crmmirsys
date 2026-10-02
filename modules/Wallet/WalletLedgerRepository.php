<?php

declare(strict_types=1);

namespace Modules\Wallet;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

final class WalletLedgerRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'wallet_ledger';
    }

    /**
     * The only write this repository exposes — no update/delete
     * methods exist at all, by design (see the migration's docblock).
     */
    public function record(
        int $walletId,
        string $type,
        string $amount,
        string $balanceAfter,
        ?string $referenceType,
        ?int $referenceId,
        ?string $description,
        ?int $createdByUserId
    ): int {
        return $this->tenantInsertGetId([
            'wallet_id' => $walletId,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $description,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    public function forWallet(int $walletId, int $limit = 100): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM wallet_ledger WHERE wallet_id = :wallet_id ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)),
            ['wallet_id' => $walletId]
        );
    }

    public function forOrganization(int $limit = 200): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM wallet_ledger WHERE 1 = 1 ORDER BY id DESC LIMIT ' . max(1, min(1000, $limit))
        );
    }

    /**
     * Same as forOrganization() but with the wallet owner's name
     * attached, for the org admin's transaction list. Deliberately a
     * correlated subquery, not a JOIN — but the subquery itself
     * contains its own "WHERE", which appears EARLIER in the SQL text
     * than the outer WHERE. tenantFindMany()'s automatic org-scoping
     * patches only the FIRST "WHERE" it finds, so it would hijack the
     * subquery's clause instead of the outer one (see
     * ContactRepository::search()'s docblock — same bug class, hit
     * for real in Phase 5, and almost repeated here). The org
     * condition is built explicitly and qualified instead.
     */
    public function forOrganizationWithOwnerNames(int $limit = 200): array
    {
        $orgScoped = !SuperAdminContext::isActive();
        $where = $orgScoped ? 'wallet_ledger.organization_id = :org_id' : '1 = 1';
        $params = $orgScoped ? ['org_id' => OrganizationContext::id()] : [];

        return $this->findMany(
            'SELECT wallet_ledger.*,
                (SELECT CONCAT(u.first_name, " ", COALESCE(u.last_name, ""))
                 FROM wallets w JOIN users u ON u.id = w.user_id
                 WHERE w.id = wallet_ledger.wallet_id) AS owner_name
             FROM wallet_ledger
             WHERE ' . $where . '
             ORDER BY id DESC LIMIT ' . max(1, min(1000, $limit)),
            $params
        );
    }
}
