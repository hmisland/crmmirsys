<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

final class ProformaRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'proformas';
    }

    /**
     * Deliberately NOT tenantFindMany(): the SELECT list's correlated
     * subqueries have their own WHERE clauses textually before the
     * outer one, which would confuse tenantFindMany()'s naive
     * first-WHERE auto-scoping — same bug class, same fix, as
     * ProductRepository::search().
     */
    public function search(array $filters = [], ?int $limit = null, int $offset = 0): array
    {
        [$where, $params] = $this->searchConditions($filters);
        $sql = "SELECT p.*,
                    (SELECT code FROM currencies WHERE currencies.id = p.currency_id) AS currency_code,
                    (SELECT CONCAT(first_name, ' ', COALESCE(last_name, '')) FROM contacts WHERE contacts.id = p.contact_id) AS contact_name,
                    (SELECT CONCAT(first_name, ' ', COALESCE(last_name, '')) FROM users WHERE users.id = p.owner_id) AS owner_name
                FROM proformas p
                WHERE " . $where . ' ORDER BY p.created_at DESC, p.id DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        }

        return $this->findMany($sql, $params);
    }

    public function countSearch(array $filters = []): int
    {
        [$where, $params] = $this->searchConditions($filters);
        $row = $this->findOne('SELECT COUNT(*) AS c FROM proformas p WHERE ' . $where, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Filters: owner_id, status, search (#number or contact name),
     * date_from / date_to (issue date, Y-m-d).
     *
     * @return array{0: string, 1: array}
     */
    private function searchConditions(array $filters): array
    {
        $orgScoped = !SuperAdminContext::isActive();
        $where = $orgScoped ? 'p.organization_id = :org_id' : '1 = 1';
        $params = $orgScoped ? ['org_id' => OrganizationContext::id()] : [];

        if (!empty($filters['owner_id'])) {
            $where .= ' AND p.owner_id = :owner_id';
            $params['owner_id'] = $filters['owner_id'];
        }

        if (!empty($filters['status'])) {
            $where .= ' AND p.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $term = ltrim((string) $filters['search'], '#');
            $where .= " AND (p.id = :search_id OR p.custom_number LIKE :search_custom OR p.contact_id IN (
                SELECT c.id FROM contacts c WHERE c.organization_id = p.organization_id
                    AND CONCAT(c.first_name, ' ', COALESCE(c.last_name, '')) LIKE :search_name))";
            $params['search_id'] = ctype_digit($term) ? (int) $term : 0;
            $params['search_name'] = '%' . $filters['search'] . '%';
            $params['search_custom'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['date_from'])) {
            $where .= ' AND p.issue_date >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where .= ' AND p.issue_date <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }

        return [$where, $params];
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM proformas WHERE id = :id', ['id' => $id]);
    }

    /** Row-locked read (conversion re-checks converted_invoice_id under this lock). */
    public function lockForUpdate(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM proformas WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }

    public function updateProforma(int $id, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteProforma(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
