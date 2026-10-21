<?php

declare(strict_types=1);

namespace Modules\Projects;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * Projects data access. Joins only Core tables (contacts, companies,
 * users) plus this module's own.
 *
 * search()/countSearch() join other tables and use a membership
 * subquery, so — like ContractRepository — they scope organization_id
 * explicitly on the projects alias (and inside the subquery) instead
 * of relying on tenantFindMany()'s first-WHERE injection.
 */
final class ProjectRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'projects';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM projects WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array{search?: string, status?: string, owner_id?: int, contact_id?: int, company_id?: int,
     *              id_in?: int[], involving_user_id?: int,
     *              visible_to?: array{user_id: int, column: ?string, value: mixed}} $filters
     */
    public function search(array $filters = [], ?int $limit = null, int $offset = 0): array
    {
        [$where, $params] = $this->conditions($filters);
        $sql = "SELECT p.*,
                    TRIM(CONCAT(ct.first_name, ' ', COALESCE(ct.last_name, ''))) AS contact_name,
                    co.name AS company_name,
                    TRIM(CONCAT(u.first_name, ' ', COALESCE(u.last_name, ''))) AS owner_name
                FROM projects p
                LEFT JOIN contacts ct ON ct.id = p.contact_id
                LEFT JOIN companies co ON co.id = p.company_id
                LEFT JOIN users u ON u.id = p.owner_id
                WHERE {$where}
                ORDER BY p.created_at DESC, p.id DESC";

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        }

        return $this->findMany($sql, $params);
    }

    public function countSearch(array $filters = []): int
    {
        [$where, $params] = $this->conditions($filters);
        $row = $this->findOne("SELECT COUNT(*) AS n FROM projects p WHERE {$where}", $params);

        return (int) ($row['n'] ?? 0);
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }

    public function updateProject(int $id, array $fields): void
    {
        if ($fields !== []) {
            $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
        }
    }

    public function deleteProject(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }

    /** Runs $work atomically, joining the caller's transaction if one is already open. */
    public function atomically(callable $work): mixed
    {
        return $this->pdo()->inTransaction() ? $work() : $this->transaction($work);
    }

    // ---- Dashboard (Phase 3): aggregates over the user's visible projects ----
    //
    // $filters are ProjectService::visibleFilters() (organization + record
    // scope + membership). "Closed" = completed / cancelled: never overdue,
    // never ending soon, their milestones never late. Everything is counted
    // in SQL; only small, limited lists are returned as rows.

    private const OPEN_SQL = "p.status NOT IN ('completed', 'cancelled')";

    /** @return array{total: int, active: int, overdue: int, ending_soon: int} */
    public function dashboardCounts(array $filters, string $today, string $horizon): array
    {
        [$where, $params] = $this->conditions($filters);
        $open = self::OPEN_SQL;
        $row = $this->findOne(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(p.status = 'active'), 0) AS active,
                    COALESCE(SUM({$open} AND p.expected_end_date < :d_today1), 0) AS overdue,
                    COALESCE(SUM({$open} AND p.expected_end_date BETWEEN :d_today2 AND :d_horizon), 0) AS ending_soon
             FROM projects p WHERE {$where}",
            $params + ['d_today1' => $today, 'd_today2' => $today, 'd_horizon' => $horizon]
        );

        return array_map('intval', ['total' => $row['total'] ?? 0, 'active' => $row['active'] ?? 0, 'overdue' => $row['overdue'] ?? 0, 'ending_soon' => $row['ending_soon'] ?? 0]);
    }

    /** Planned milestones of open visible projects: late (date before today) and upcoming (today … horizon). @return array{overdue: int, upcoming: int} */
    public function milestoneCounts(array $filters, string $today, string $horizon): array
    {
        [$where, $params] = $this->conditions($filters);
        $open = self::OPEN_SQL;
        $row = $this->findOne(
            "SELECT COALESCE(SUM(m.date < :m_today1), 0) AS overdue,
                    COALESCE(SUM(m.date BETWEEN :m_today2 AND :m_horizon), 0) AS upcoming
             FROM project_milestones m
             JOIN projects p ON p.id = m.project_id AND p.organization_id = m.organization_id
             WHERE {$where} AND {$open} AND m.status = 'planned'",
            $params + ['m_today1' => $today, 'm_today2' => $today, 'm_horizon' => $horizon]
        );

        return ['overdue' => (int) ($row['overdue'] ?? 0), 'upcoming' => (int) ($row['upcoming'] ?? 0)];
    }

    /** The next planned milestones (today … horizon) of open visible projects, earliest first. */
    public function upcomingMilestones(array $filters, string $today, string $horizon, int $limit): array
    {
        [$where, $params] = $this->conditions($filters);
        $open = self::OPEN_SQL;

        return $this->findMany(
            "SELECT m.id, m.name, m.date, p.id AS project_id, p.code AS project_code, p.name AS project_name
             FROM project_milestones m
             JOIN projects p ON p.id = m.project_id AND p.organization_id = m.organization_id
             WHERE {$where} AND {$open} AND m.status = 'planned' AND m.date BETWEEN :u_today AND :u_horizon
             ORDER BY m.date, m.id LIMIT " . max(1, $limit),
            $params + ['u_today' => $today, 'u_horizon' => $horizon]
        );
    }

    /**
     * Open visible projects that need attention — past their end date, ending
     * by the horizon, or with a late planned milestone — most urgent first,
     * each with its reasons as columns (is_overdue / ends_soon exclude each
     * other by date; overdue_milestones is a count).
     *
     * @return array{rows: array, total: int}
     */
    public function attention(array $filters, string $today, string $horizon, int $limit): array
    {
        [$where, $params] = $this->conditions($filters);
        $open = self::OPEN_SQL;
        $late = "(SELECT COUNT(*) FROM project_milestones lm WHERE lm.project_id = p.id AND lm.organization_id = p.organization_id
                  AND lm.status = 'planned' AND lm.date < :%s)";
        $need = "{$open} AND (p.expected_end_date <= :a_horizon OR " . sprintf($late, 'a_today0') . ' > 0)';
        $params += ['a_horizon' => $horizon, 'a_today0' => $today];

        $total = (int) ($this->findOne("SELECT COUNT(*) AS n FROM projects p WHERE {$where} AND {$need}", $params)['n'] ?? 0);
        $rows = $total === 0 ? [] : $this->findMany(
            "SELECT p.id, p.code, p.name, p.status, p.expected_end_date,
                    COALESCE(p.expected_end_date < :a_today1, 0) AS is_overdue,
                    COALESCE(p.expected_end_date BETWEEN :a_today2 AND :a_horizon2, 0) AS ends_soon,
                    " . sprintf($late, 'a_today3') . " AS overdue_milestones
             FROM projects p WHERE {$where} AND {$need}
             ORDER BY is_overdue DESC, overdue_milestones > 0 DESC, p.expected_end_date IS NULL, p.expected_end_date, p.id
             LIMIT " . max(1, $limit),
            $params + ['a_today1' => $today, 'a_today2' => $today, 'a_horizon2' => $horizon, 'a_today3' => $today]
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Ids and labels of the open visible projects (for other modules' dashboard
     * figures, e.g. task counts) — two columns, no full rows.
     *
     * @return array<int, string> id => "CODE name"
     */
    public function openLabels(array $filters): array
    {
        [$where, $params] = $this->conditions($filters);
        $open = self::OPEN_SQL;
        $out = [];
        foreach ($this->findMany("SELECT p.id, p.code, p.name FROM projects p WHERE {$where} AND {$open} ORDER BY p.id", $params) as $row) {
            $out[(int) $row['id']] = trim($row['code'] . ' ' . $row['name']);
        }

        return $out;
    }

    /** @return array{0: string, 1: array} */
    private function conditions(array $filters): array
    {
        $superAdmin = SuperAdminContext::isActive();
        $where = $superAdmin ? '1 = 1' : 'p.organization_id = :org_id';
        $params = $superAdmin ? [] : ['org_id' => OrganizationContext::id()];
        // The membership subquery stays inside the project's own organization.
        $memberOf = fn (string $placeholder) => "EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = p.id
            AND pm.organization_id = p.organization_id AND pm.user_id = :{$placeholder})";

        if (!empty($filters['search'])) {
            // Parenthesized OR; distinct placeholders (real prepared statements can't reuse one).
            $where .= ' AND (p.name LIKE :s1 OR p.code LIKE :s2)';
            $params['s1'] = $params['s2'] = '%' . $filters['search'] . '%';
        }

        foreach (['status', 'owner_id', 'contact_id', 'company_id'] as $column) {
            if (isset($filters[$column]) && $filters[$column] !== '' && $filters[$column] !== null) {
                $where .= " AND p.{$column} = :f_{$column}";
                $params['f_' . $column] = $filters[$column];
            }
        }

        if (isset($filters['id_in'])) {
            $ids = array_values(array_filter(array_map('intval', (array) $filters['id_in']), fn (int $id) => $id > 0));
            $where .= $ids === [] ? ' AND 1 = 0' : ' AND p.id IN (' . implode(',', $ids) . ')';
        }

        // "Mine": projects I manage or belong to.
        if (!empty($filters['involving_user_id'])) {
            $where .= ' AND (p.owner_id = :inv_owner OR ' . $memberOf('inv_member') . ')';
            $params['inv_owner'] = $params['inv_member'] = (int) $filters['involving_user_id'];
        }

        // Record scope (own/team/department) widened by membership — see
        // ProjectService::scoped(). column null = no scope column (no rows
        // beyond the user's memberships).
        if (isset($filters['visible_to'])) {
            $visible = $filters['visible_to'];
            $params['vis_member'] = (int) $visible['user_id'];
            if (in_array($visible['column'] ?? null, ['owner_id', 'team_id', 'department_id'], true) && $visible['value'] !== null) {
                $where .= " AND (p.{$visible['column']} = :vis_value OR " . $memberOf('vis_member') . ')';
                $params['vis_value'] = $visible['value'];
            } else {
                $where .= ' AND ' . $memberOf('vis_member');
            }
        }

        return [$where, $params];
    }
}
