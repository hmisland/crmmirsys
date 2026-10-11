<?php

declare(strict_types=1);

namespace Modules\Contracts;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * Contracts data access. Joins only Core tables (contacts, companies,
 * currencies, users) plus this module's own.
 *
 * search()/countSearch() join other tables, so — like Finance's and
 * Followups' search() — they scope organization_id explicitly on the
 * contracts alias instead of relying on tenantFindMany()'s first-WHERE
 * injection.
 */
final class ContractRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'contracts';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM contracts WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array{search?: string, status?: string, effective_status?: string, owner_id?: int,
     *              team_id?: int, department_id?: int, contact_id?: int, company_id?: int,
     *              category_id?: int, expiring_within_days?: int, today?: string} $filters
     */
    public function search(array $filters = [], ?int $limit = null, int $offset = 0): array
    {
        [$where, $params] = $this->conditions($filters);
        $sql = "SELECT c.*,
                    TRIM(CONCAT(ct.first_name, ' ', COALESCE(ct.last_name, ''))) AS contact_name,
                    co.name AS company_name,
                    cat.name AS category_name,
                    cur.code AS currency_code,
                    TRIM(CONCAT(u.first_name, ' ', COALESCE(u.last_name, ''))) AS owner_name
                FROM contracts c
                LEFT JOIN contacts ct ON ct.id = c.contact_id
                LEFT JOIN companies co ON co.id = c.company_id
                LEFT JOIN contract_categories cat ON cat.id = c.category_id
                LEFT JOIN currencies cur ON cur.id = c.currency_id
                LEFT JOIN users u ON u.id = c.owner_id
                WHERE {$where}
                ORDER BY c.created_at DESC, c.id DESC";

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        }

        return $this->findMany($sql, $params);
    }

    public function countSearch(array $filters = []): int
    {
        [$where, $params] = $this->conditions($filters);
        $row = $this->findOne("SELECT COUNT(*) AS n FROM contracts c WHERE {$where}", $params);

        return (int) ($row['n'] ?? 0);
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }

    public function updateContract(int $id, array $fields): void
    {
        if ($fields !== []) {
            $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
        }
    }

    public function deleteContract(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }

    /** Runs $work atomically, joining the caller's transaction if one is already open. */
    public function atomically(callable $work): mixed
    {
        return $this->pdo()->inTransaction() ? $work() : $this->transaction($work);
    }

    /** @return array{0: string, 1: array} */
    private function conditions(array $filters): array
    {
        $where = SuperAdminContext::isActive() ? '1 = 1' : 'c.organization_id = :org_id';
        $params = SuperAdminContext::isActive() ? [] : ['org_id' => OrganizationContext::id()];
        $today = $filters['today'] ?? date('Y-m-d');

        if (!empty($filters['search'])) {
            // Parenthesized OR; distinct placeholders (real prepared statements can't reuse one).
            $where .= ' AND (c.title LIKE :s1 OR c.contract_number LIKE :s2)';
            $params['s1'] = $params['s2'] = '%' . $filters['search'] . '%';
        }

        foreach (['status', 'owner_id', 'team_id', 'department_id', 'contact_id', 'company_id', 'category_id', 'parent_contract_id'] as $column) {
            if (isset($filters[$column]) && $filters[$column] !== '' && $filters[$column] !== null) {
                $where .= " AND c.{$column} = :f_{$column}";
                $params['f_' . $column] = $filters[$column];
            }
        }

        if (isset($filters['id_in'])) {
            $ids = array_values(array_filter(array_map('intval', (array) $filters['id_in']), fn (int $id) => $id > 0));
            $where .= $ids === [] ? ' AND 1 = 0' : ' AND c.id IN (' . implode(',', $ids) . ')';
        }

        // Expiry is computed on read (no scheduler): an "active" contract
        // whose end date has passed counts as expired.
        switch ($filters['effective_status'] ?? '') {
            case 'active':
                $where .= " AND c.status = 'active' AND (c.end_date IS NULL OR c.end_date >= :today)";
                $params['today'] = $today;
                break;
            case 'expired':
                $where .= " AND (c.status = 'expired' OR (c.status = 'active' AND c.end_date < :today))";
                $params['today'] = $today;
                break;
            case '':
                break;
            default:
                $where .= ' AND c.status = :eff_status';
                $params['eff_status'] = $filters['effective_status'];
        }

        if (!empty($filters['expiring_within_days'])) {
            $where .= " AND c.status = 'active' AND c.end_date >= :exp_from AND c.end_date <= :exp_to";
            $params['exp_from'] = $today;
            $params['exp_to'] = date('Y-m-d', strtotime($today . ' +' . (int) $filters['expiring_within_days'] . ' days'));
        }

        return [$where, $params];
    }
}
