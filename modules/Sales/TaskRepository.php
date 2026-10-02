<?php

declare(strict_types=1);

namespace Modules\Sales;

use App\Infrastructure\Repositories\TenantScopedRepository;

final class TaskRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'tasks';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM tasks WHERE id = :id', ['id' => $id]);
    }

    public function forSubject(string $relatedType, int $relatedId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM tasks WHERE related_type = :type AND related_id = :id ORDER BY due_at IS NULL, due_at',
            ['type' => $relatedType, 'id' => $relatedId]
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
            'SELECT * FROM tasks
             WHERE status = "pending"
               AND (assigned_to_user_id = :user_id1
                    OR id IN (SELECT task_id FROM task_assignees WHERE user_id = :user_id2))
             ORDER BY due_at IS NULL, due_at LIMIT ' . max(1, min(100, $limit)),
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
     * Replaces the full assignee set for a task in one go — simpler
     * for an edit form than diffing individual add/remove operations.
     * Not organization_id-scoped directly (task_assignees has no such
     * column, same reasoning as TagRepository's taggables methods) —
     * authorization is inherited from the task itself, which callers
     * (TaskService) have already verified access to before calling this.
     *
     * @param int[] $userIds
     */
    public function setAssignees(int $taskId, array $userIds): void
    {
        $this->transaction(function () use ($taskId, $userIds) {
            $this->delete('task_assignees', 'task_id = :task_id', ['task_id' => $taskId]);

            foreach (array_unique($userIds) as $userId) {
                $this->insertGetId('task_assignees', ['task_id' => $taskId, 'user_id' => $userId]);
            }
        });
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

        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteTask(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
