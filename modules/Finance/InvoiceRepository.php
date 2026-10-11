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
    public function search(array $filters = [], ?int $limit = null, int $offset = 0): array
    {
        [$where, $params] = $this->searchConditions($filters);
        $sql = "SELECT i.*,
                    (SELECT code FROM currencies WHERE currencies.id = i.currency_id) AS currency_code,
                    (SELECT CONCAT(first_name, ' ', COALESCE(last_name, '')) FROM contacts WHERE contacts.id = i.contact_id) AS contact_name,
                    (SELECT CONCAT(first_name, ' ', COALESCE(last_name, '')) FROM users WHERE users.id = i.owner_id) AS owner_name,
                    (SELECT COALESCE(SUM(c.amount), 0) FROM credit_notes c
                      WHERE c.invoice_id = i.id AND c.organization_id = i.organization_id) AS credited_total
                FROM invoices i
                WHERE " . $where . ' ORDER BY i.created_at DESC, i.id DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        }

        return $this->findMany($sql, $params);
    }

    public function countSearch(array $filters = []): int
    {
        [$where, $params] = $this->searchConditions($filters);
        $row = $this->findOne('SELECT COUNT(*) AS c FROM invoices i WHERE ' . $where, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Filters: owner_id, status, contact_id, search (invoice number or
     * contact name), date_from / date_to (issue date, Y-m-d).
     *
     * @return array{0: string, 1: array}
     */
    private function searchConditions(array $filters): array
    {
        $orgScoped = !SuperAdminContext::isActive();
        $where = $orgScoped ? 'i.organization_id = :org_id' : '1 = 1';
        $params = $orgScoped ? ['org_id' => OrganizationContext::id()] : [];

        if (!empty($filters['owner_id'])) {
            $where .= ' AND i.owner_id = :owner_id';
            $params['owner_id'] = $filters['owner_id'];
        }

        if (!empty($filters['status'])) {
            $where .= ' AND i.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['contact_id'])) {
            $where .= ' AND i.contact_id = :contact_id';
            $params['contact_id'] = (int) $filters['contact_id'];
        }

        if (!empty($filters['search'])) {
            $where .= " AND (i.invoice_number LIKE :search1 OR i.custom_number LIKE :search3 OR i.contact_id IN (
                SELECT c.id FROM contacts c WHERE c.organization_id = i.organization_id
                    AND CONCAT(c.first_name, ' ', COALESCE(c.last_name, '')) LIKE :search2))";
            $params['search1'] = '%' . $filters['search'] . '%';
            $params['search2'] = '%' . $filters['search'] . '%';
            $params['search3'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['date_from'])) {
            $where .= ' AND i.issue_date >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where .= ' AND i.issue_date <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }

        return [$where, $params];
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
     * Period performance per currency (remediation P1, decision D1 = A): the
     * ISSUED invoices whose issue_date is in the period — drafts aren't real
     * yet and cancelled ones are void — one row per invoice currency (amounts
     * in different currencies are never added together). credited_total is
     * every credit note OF THOSE invoices, whatever its own date; paid_total
     * is what those invoices have received so far.
     *
     * Explicit organization filter on both the invoices and the correlated
     * credit-note subquery (tenantFindMany() would scope the first WHERE,
     * which is the subquery's).
     *
     * @return array<int, array<string, mixed>> ordered by currency id
     */
    public function periodPerformanceByCurrency(string $startDate, string $endDate): array
    {
        return $this->findMany(
            "SELECT i.currency_id,
                    (SELECT code FROM currencies WHERE currencies.id = i.currency_id) AS currency_code,
                    COUNT(*) AS invoice_count,
                    COALESCE(SUM(i.grand_total), 0) AS total_revenue,
                    COALESCE(SUM(i.tax_total), 0) AS total_tax,
                    COALESCE(SUM(i.paid_total), 0) AS total_paid,
                    COALESCE(SUM((SELECT COALESCE(SUM(c.amount), 0) FROM credit_notes c
                                   WHERE c.invoice_id = i.id AND c.organization_id = i.organization_id)), 0) AS total_credited
               FROM invoices i
              WHERE i.organization_id = :org_id AND i.status = 'issued' AND i.issue_date BETWEEN :start_date AND :end_date
              GROUP BY i.currency_id
              ORDER BY i.currency_id",
            ['org_id' => OrganizationContext::id(), 'start_date' => $startDate, 'end_date' => $endDate]
        );
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
