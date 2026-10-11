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
