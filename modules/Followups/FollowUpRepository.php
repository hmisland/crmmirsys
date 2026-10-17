<?php

declare(strict_types=1);

namespace Modules\Followups;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use App\Infrastructure\Repositories\TenantScopedRepository;
use App\Infrastructure\Time\OrganizationTimezone;

final class FollowUpRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'follow_ups';
    }

    /**
     * Deliberately NOT tenantFindMany() — the correlated subqueries
     * in the SELECT list have their own WHERE before the outer one,
     * same reasoning as every Finance repository's search().
     */
    public function search(array $filters = []): array
    {
        $orgScoped = !SuperAdminContext::isActive();
        $sql = "SELECT f.*,
                    (SELECT CONCAT(first_name, ' ', COALESCE(last_name, '')) FROM users WHERE users.id = f.assignee_user_id) AS assignee_name,
                    (SELECT CONCAT(first_name, ' ', COALESCE(last_name, '')) FROM users WHERE users.id = f.owner_id) AS owner_name
                FROM follow_ups f
                WHERE " . ($orgScoped ? 'f.organization_id = :org_id' : '1 = 1');
        $params = $orgScoped ? ['org_id' => OrganizationContext::id()] : [];

        if (!empty($filters['owner_id'])) {
            $sql .= ' AND f.owner_id = :owner_id';
            $params['owner_id'] = $filters['owner_id'];
        }

        if (!empty($filters['assignee_user_id'])) {
            $sql .= ' AND f.assignee_user_id = :assignee_user_id';
            $params['assignee_user_id'] = $filters['assignee_user_id'];
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND f.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['related_type']) && array_key_exists('related_id', $filters)) {
            $sql .= ' AND f.related_type = :related_type AND f.related_id = :related_id';
            $params['related_type'] = $filters['related_type'];
            $params['related_id'] = $filters['related_id'];
        }

        if (!empty($filters['overdue_only'])) {
            $sql .= " AND f.status IN ('pending', 'scheduled', 'in_progress') AND f.due_at IS NOT NULL AND f.due_at < :now";
            // due_at is the organization's local wall clock: compare with its local "now", not UTC.
            $params['now'] = OrganizationTimezone::shared()->localNow();
        }

        $sql .= ' ORDER BY f.due_at IS NULL, f.due_at ASC, f.id DESC';

        return $this->findMany($sql, $params);
    }

    /**
     * For the dashboard tile: this user's own open follow-ups
     * (owner or assignee), priority first (urgent > high > normal >
     * low), then soonest due date — the same "sorted, capped,
     * see-more-in-the-real-page" shape as the Tasks dashboard widget.
     * No correlated-subquery risk here (no subqueries at all), so
     * tenantFindMany()'s auto-scoping is safe.
     */
    public function topForUser(int $userId, int $limit): array
    {
        return $this->tenantFindMany(
            "SELECT * FROM follow_ups
             WHERE status IN ('pending', 'scheduled', 'in_progress')
               AND (owner_id = :user_id OR assignee_user_id = :user_id2)
             ORDER BY FIELD(priority, 'urgent', 'high', 'normal', 'low'), due_at IS NULL, due_at ASC
             LIMIT " . max(1, (int) $limit),
            ['user_id' => $userId, 'user_id2' => $userId]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM follow_ups WHERE id = :id', ['id' => $id]);
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }

    public function updateFollowUp(int $id, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteFollowUp(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
