<?php

declare(strict_types=1);

namespace Modules\Sales;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * Soft-deleted tasks (deleted_at set, Sales migration 0007) are invisible
 * to every read here: findById() returns null for them, so every route
 * that loads a task by id 404s, and no list or count includes them.
 */
final class TaskRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'tasks';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM tasks WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    /** A record's tasks (e.g. a deal's), archived ones excluded; finished ones sink to the bottom. */
    public function forSubject(string $relatedType, int $relatedId): array
    {
        return $this->tenantFindMany(
            "SELECT * FROM tasks WHERE related_type = :type AND related_id = :id AND archived_at IS NULL AND deleted_at IS NULL
             ORDER BY status IN ('completed', 'rejected', 'cancelled'), due_at IS NULL, due_at",
            ['type' => $relatedType, 'id' => $relatedId]
        );
    }

    /**
     * The "My tasks" page: every task assigned to the user (primary or
     * additional assignee), in any status — completed ones stay visible
     * (struck through) instead of vanishing. $archived switches between
     * the working list and the archive.
     */
    public function forUser(int $userId, bool $archived, ?string $status = null): array
    {
        $sql = 'SELECT * FROM tasks
                WHERE archived_at IS ' . ($archived ? 'NOT NULL' : 'NULL') . '
                  AND deleted_at IS NULL
                  AND (assigned_to_user_id = :user_id1
                       OR id IN (SELECT task_id FROM task_assignees WHERE user_id = :user_id2))';
        $params = ['user_id1' => $userId, 'user_id2' => $userId];

        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }

        $sql .= $archived
            ? ' ORDER BY archived_at DESC'
            : " ORDER BY status IN ('completed', 'rejected', 'cancelled'), due_at IS NULL, due_at, id DESC";

        return $this->tenantFindMany($sql . ' LIMIT 500', $params);
    }

    /**
     * Working (not archived) tasks of the organization for the list's
     * "Created by me" view ($createdBy set) or "All tasks" view (null).
     * Callers apply the viewer's scope (TaskService::visibleRows()).
     */
    public function listing(?int $createdBy, ?string $status = null): array
    {
        $sql = 'SELECT * FROM tasks WHERE archived_at IS NULL AND deleted_at IS NULL';
        $params = [];

        if ($createdBy !== null) {
            $sql .= ' AND created_by_user_id = :created_by';
            $params['created_by'] = $createdBy;
        }
        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }

        return $this->tenantFindMany(
            $sql . " ORDER BY status IN ('completed', 'rejected', 'cancelled'), due_at IS NULL, due_at, id DESC LIMIT 500",
            $params
        );
    }

    /**
     * Upcoming pending tasks where the user is either the primary
     * assignee OR one of possibly-several assignees (task_assignees).
     * Two distinct placeholders for the same value — PDO with real
     * (non-emulated) prepared statements rejects reusing one named
     * parameter twice in a query (see ContactRepository::search()'s
     * docblock for the same fix, hit for real during Phase 5 testing).
     */
    public function upcomingForUser(int $userId, int $limit = 20): array
    {
        return $this->tenantFindMany(
            "SELECT * FROM tasks
             WHERE status IN ('pending', 'in_progress', 'on_hold') AND archived_at IS NULL AND deleted_at IS NULL
               AND (assigned_to_user_id = :user_id1
                    OR id IN (SELECT task_id FROM task_assignees WHERE user_id = :user_id2))
             ORDER BY due_at IS NULL, due_at LIMIT " . max(1, min(100, $limit)),
            ['user_id1' => $userId, 'user_id2' => $userId]
        );
    }

    /** @return int[] */
    public function assigneeIds(int $taskId): array
    {
        $rows = $this->findMany('SELECT user_id FROM task_assignees WHERE task_id = :task_id', ['task_id' => $taskId]);

        return array_map(fn ($row) => (int) $row['user_id'], $rows);
    }

    /**
     * Assignee ids of several tasks in one query (list-level scope checks).
     *
     * @param int[] $taskIds
     * @return array<int, int[]> task id => assignee user ids
     */
    public function assigneeIdsFor(array $taskIds): array
    {
        $taskIds = array_values(array_unique(array_map('intval', $taskIds)));
        if ($taskIds === []) {
            return [];
        }

        $result = array_fill_keys($taskIds, []);
        $rows = $this->findMany('SELECT task_id, user_id FROM task_assignees WHERE task_id IN (' . implode(',', $taskIds) . ')');
        foreach ($rows as $row) {
            $result[(int) $row['task_id']][] = (int) $row['user_id'];
        }

        return $result;
    }

    /**
     * Team and department of users of the current organization — what
     * TEAM/DEPARTMENT scope compares. Another organization's id is simply
     * absent from the result.
     *
     * @param int[] $userIds
     * @return array<int, array{team_id: ?int, department_id: ?int}>
     */
    public function teamsOf(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn (int $id) => $id > 0)));
        if ($userIds === []) {
            return [];
        }

        $rows = $this->findMany(
            'SELECT id, team_id, department_id FROM users WHERE organization_id = :org_id AND id IN (' . implode(',', $userIds) . ')',
            ['org_id' => OrganizationContext::id()]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = [
                'team_id' => $row['team_id'] !== null ? (int) $row['team_id'] : null,
                'department_id' => $row['department_id'] !== null ? (int) $row['department_id'] : null,
            ];
        }

        return $result;
    }

    /**
     * Replaces the full assignee set for a task in one go — simpler
     * for an edit form than diffing individual add/remove operations.
     * Not organization_id-scoped directly (task_assignees has no such
     * column, same reasoning as TagRepository's taggables methods) —
     * authorization is inherited from the task itself, which callers
     * (TaskService) have already verified access to before calling this.
     * Joins the caller's transaction when one is open.
     *
     * @param int[] $userIds
     */
    public function setAssignees(int $taskId, array $userIds): void
    {
        $this->atomically(function () use ($taskId, $userIds) {
            $this->delete('task_assignees', 'task_id = :task_id', ['task_id' => $taskId]);

            foreach (array_unique($userIds) as $userId) {
                $this->insertGetId('task_assignees', ['task_id' => $taskId, 'user_id' => $userId]);
            }
        });
    }

    /** Runs $work in a transaction, or inside the caller's when one is already open. */
    public function atomically(callable $work): mixed
    {
        return $this->pdo()->inTransaction() ? $work() : $this->transaction($work);
    }

    public function create(array $data): int
    {
        return $this->tenantInsertGetId($data);
    }

    public function updateTask(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $this->tenantUpdate($fields, 'id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    /** Soft delete: the row, its assignees, comments and attachments all stay for audit and recovery. */
    public function softDelete(int $id, int $userId): void
    {
        $this->tenantUpdate(
            ['deleted_at' => date('Y-m-d H:i:s'), 'deleted_by_user_id' => $userId],
            'id = :id AND deleted_at IS NULL',
            ['id' => $id]
        );
    }
}
