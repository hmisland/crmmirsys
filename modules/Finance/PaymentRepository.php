<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

final class PaymentRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'payments';
    }

    /**
     * Deliberately NOT tenantFindMany() — same reasoning as every
     * other Finance search() method: the correlated subquery here
     * has its own WHERE before the outer one.
     */
    public function forInvoice(int $invoiceId): array
    {
        $orgScoped = !SuperAdminContext::isActive();
        $sql = "SELECT p.*,
                    (SELECT CONCAT(first_name, ' ', COALESCE(last_name, '')) FROM users WHERE users.id = p.recorded_by_user_id) AS recorded_by_name
                FROM payments p
                WHERE p.invoice_id = :invoice_id" . ($orgScoped ? ' AND p.organization_id = :org_id' : '');
        $params = ['invoice_id' => $invoiceId];
        if ($orgScoped) {
            $params['org_id'] = OrganizationContext::id();
        }

        $sql .= ' ORDER BY p.payment_date DESC, p.id DESC';

        return $this->findMany($sql, $params);
    }

    /**
     * No correlated subquery here — safe to use tenantFindOne()'s
     * auto-scoping (only one WHERE in the whole string).
     */
    public function totalPaidForInvoice(int $invoiceId): string
    {
        $row = $this->tenantFindOne('SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE invoice_id = :invoice_id', ['invoice_id' => $invoiceId]);

        return (string) ($row['total'] ?? '0');
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }
}
