<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

final class CreditNoteRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'credit_notes';
    }

    /**
     * Deliberately NOT tenantFindMany() — same reasoning as every
     * other Finance repository with a correlated subquery before the
     * outer WHERE.
     */
    public function forInvoice(int $invoiceId): array
    {
        $orgScoped = !SuperAdminContext::isActive();
        $sql = "SELECT cn.*,
                    (SELECT CONCAT(first_name, ' ', COALESCE(last_name, '')) FROM users WHERE users.id = cn.issued_by_user_id) AS issued_by_name
                FROM credit_notes cn
                WHERE cn.invoice_id = :invoice_id" . ($orgScoped ? ' AND cn.organization_id = :org_id' : '');
        $params = ['invoice_id' => $invoiceId];
        if ($orgScoped) {
            $params['org_id'] = OrganizationContext::id();
        }

        $sql .= ' ORDER BY cn.issue_date DESC, cn.id DESC';

        return $this->findMany($sql, $params);
    }

    /** No correlated subquery — safe to use tenantFindOne()'s auto-scoping. */
    public function totalCreditedForInvoice(int $invoiceId): string
    {
        $row = $this->tenantFindOne('SELECT COALESCE(SUM(amount), 0) AS total FROM credit_notes WHERE invoice_id = :invoice_id', ['invoice_id' => $invoiceId]);

        return (string) ($row['total'] ?? '0');
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }
}
