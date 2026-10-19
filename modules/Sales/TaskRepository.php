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
    /**
     * @param int[] $ids
     * @return array<int, array> by id; deleted tasks are left out
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $rows = $this->tenantFindMany('SELECT * FROM tasks WHERE id IN (' . implode(',', $ids) . ') AND deleted_at IS NULL');

        return array_column($rows, null, 'id');
    }

    /** The occurrence generated from this task (T7 recurrence), unless deleted. */
    public function occurrenceAfter(int $taskId): ?array
    {
        return $this->tenantFindOne('SELECT * FROM tasks WHERE recur_parent_id = :id AND deleted_at IS NULL', ['id' => $taskId]);
    }

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
     * The task list (search + pagination), database-side like the Contacts
     * list: countList() for the total, pageList() for one page. $criteria:
     *  - view: mine | created | all | archived (TaskService::LIST_VIEWS)
     *  - user_id: the viewer (mine / archived = assigned to them, created = by them)
     *  - status: one status or null
     *  - search: text matched in title, description and label names, or ''
     *  - exclude: task ids the viewer may not see (TaskService decides)
     * Soft-deleted tasks are never included; working views exclude archived ones.
     */
    public function countList(array $criteria): int
    {
        [$where, $params] = $this->listConditions($criteria);
        $row = $this->tenantFindOne('SELECT COUNT(*) AS c FROM tasks WHERE ' . $where, $params);

        return (int) ($row['c'] ?? 0);
    }

    public function pageList(array $criteria, int $limit, int $offset): array
    {
        [$where, $params] = $this->listConditions($criteria);
        $order = ($criteria['view'] ?? '') === 'archived'
            ? 'archived_at DESC, id DESC'
            : "status IN ('completed', 'rejected', 'cancelled'), due_at IS NULL, due_at, id DESC";

        return $this->tenantFindMany(
            'SELECT * FROM tasks WHERE ' . $where . ' ORDER BY ' . $order
            . ' LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset),
            $params
        );
    }

    /**
     * Tasks scheduled within [$from, $to) — the organization's local wall
     * clock, compared as stored (C3, never converted): those starting before
     * $to and due (or starting, without a due date) on or after $from, so a
     * task spanning the period shows too. Same conditions as the list (view,
     * filters, hidden relations); tasks without any date are not included.
     */
    public function calendarList(array $criteria, string $from, string $to, int $limit): array
    {
        [$where, $params] = $this->listConditions($criteria);

        return $this->tenantFindMany(
            'SELECT * FROM tasks WHERE ' . $where . ' AND (start_at IS NOT NULL OR due_at IS NOT NULL)
                AND COALESCE(start_at, due_at) < :range_to AND COALESCE(due_at, start_at) >= :range_from
             ORDER BY COALESCE(start_at, due_at), id LIMIT ' . max(1, $limit),
            $params + ['range_to' => $to, 'range_from' => $from]
        );
    }

    /**
     * The list's project/contract-related tasks (id + relation), so the
     * service can exclude those whose record the viewer may not see.
     */
    public function listRelations(array $criteria): array
    {
        [$where, $params] = $this->listConditions(['exclude' => []] + $criteria);

        return $this->tenantFindMany(
            "SELECT id, related_type, related_id FROM tasks WHERE {$where} AND related_type IN ('project', 'contract')",
            $params
        );
    }

    /** @return array{0: string, 1: array} */
    private function listConditions(array $criteria): array
    {
        $view = $criteria['view'] ?? 'mine';
        $userId = (int) ($criteria['user_id'] ?? 0);
        $where = 'deleted_at IS NULL AND archived_at IS ' . ($view === 'archived' ? 'NOT NULL' : 'NULL');
        $params = [];

        if ($view === 'mine' || $view === 'archived') {
            $where .= ' AND (assigned_to_user_id = :user_id1 OR id IN (SELECT task_id FROM task_assignees WHERE user_id = :user_id2))';
            $params += ['user_id1' => $userId, 'user_id2' => $userId];
        } elseif ($view === 'created') {
            $where .= ' AND created_by_user_id = :created_by';
            $params['created_by'] = $userId;
        }

        if (!empty($criteria['status'])) {
            $where .= ' AND status = :status';
            $params['status'] = $criteria['status'];
        }

        // One of the organization's labels (T9 calendar filter); another's id matches nothing.
        if (!empty($criteria['label_id'])) {
            $where .= ' AND id IN (SELECT l.task_id FROM task_label_links l JOIN task_labels tl ON tl.id = l.label_id
                WHERE l.label_id = :label_id AND tl.organization_id = :label_org)';
            $params['label_id'] = (int) $criteria['label_id'];
            $params['label_org'] = OrganizationContext::id();
        }

        // A custom status (T8): chosen on the task, or — for an original
        // status — tasks untouched since that still carry its legacy value.
        if (!empty($criteria['status_filter'])) {
            $filter = $criteria['status_filter'];
            $where .= ' AND (status_id = :status_id' . ($filter['system_key'] !== null ? ' OR (status_id IS NULL AND status = :status_system)' : '') . ')';
            $params['status_id'] = (int) $filter['id'];
            if ($filter['system_key'] !== null) {
                $params['status_system'] = $filter['system_key'];
            }
        }

        if (($criteria['search'] ?? '') !== '') {
            // Distinct placeholders: real prepared statements reject a reused name.
            $where .= ' AND (title LIKE :search1 OR description LIKE :search2
                OR id IN (SELECT l.task_id FROM task_label_links l JOIN task_labels t ON t.id = l.label_id
                          WHERE l.organization_id = :search_org AND t.name LIKE :search3))';
            $like = '%' . addcslashes((string) $criteria['search'], '%_\\') . '%';
            $params += ['search1' => $like, 'search2' => $like, 'search3' => $like, 'search_org' => OrganizationContext::id()];
        }

        $exclude = array_values(array_filter(array_map('intval', $criteria['exclude'] ?? []), fn (int $id) => $id > 0));
        if ($exclude !== []) {
            $where .= ' AND id NOT IN (' . implode(',', $exclude) . ')';
        }

        return [$where, $params];
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
