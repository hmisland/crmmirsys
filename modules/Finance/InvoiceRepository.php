<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

final class InvoiceRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'invoices';
    }

    /**
     * Deliberately NOT tenantFindMany() — same reasoning as
     * ProductRepository/ProformaRepository::search(): the SELECT
     * list's correlated subqueries have their own WHERE clauses
     * textually before the outer one.
     */
    public function search(array $filters = []): array
    {
        $orgScoped = !SuperAdminContext::isActive();
        $sql = "SELECT i.*,
                    (SELECT code FROM currencies WHERE currencies.id = i.currency_id) AS currency_code,
                    (SELECT CONCAT(first_name, ' ', COALESCE(last_name, '')) FROM contacts WHERE contacts.id = i.contact_id) AS contact_name,
                    (SELECT CONCAT(first_name, ' ', COALESCE(last_name, '')) FROM users WHERE users.id = i.owner_id) AS owner_name
                FROM invoices i
                WHERE " . ($orgScoped ? 'i.organization_id = :org_id' : '1 = 1');
        $params = $orgScoped ? ['org_id' => OrganizationContext::id()] : [];

        if (!empty($filters['owner_id'])) {
            $sql .= ' AND i.owner_id = :owner_id';
            $params['owner_id'] = $filters['owner_id'];
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND i.status = :status';
            $params['status'] = $filters['status'];
        }

        $sql .= ' ORDER BY i.created_at DESC';

        return $this->findMany($sql, $params);
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM invoices WHERE id = :id', ['id' => $id]);
    }

    /** Row-locked read, for the same atomic-update pattern already used for Wallet balances and invoice numbering — paid_total must never be updated from a stale read under concurrent payments. */
    public function lockForUpdate(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM invoices WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    /**
     * Issued invoices only — a basic report has no business counting
     * drafts (not real yet) or cancelled ones (voided) as revenue.
     * No correlated subquery here, so tenantFindOne()'s auto-scoping
     * is safe.
     */
    public function summaryForPeriod(string $startDate, string $endDate): array
    {
        $row = $this->tenantFindOne(
            "SELECT COUNT(*) AS invoice_count,
                    COALESCE(SUM(grand_total), 0) AS total_revenue,
                    COALESCE(SUM(tax_total), 0) AS total_tax,
                    COALESCE(SUM(paid_total), 0) AS total_paid
             FROM invoices
             WHERE status = 'issued' AND issue_date BETWEEN :start_date AND :end_date",
            ['start_date' => $startDate, 'end_date' => $endDate]
        );

        return $row ?? ['invoice_count' => 0, 'total_revenue' => '0', 'total_tax' => '0', 'total_paid' => '0'];
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }

    public function updateInvoice(int $id, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteInvoice(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
