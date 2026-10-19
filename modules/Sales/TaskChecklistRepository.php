<?php

declare(strict_types=1);

namespace Modules\Sales;

use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * A task's checklist items. Tenant-scoped like every repository here; an
 * item is only ever loaded together with its task id, so an id from a URL
 * never reaches another task's item. Authorization is TaskService's job.
 */
final class TaskChecklistRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'task_checklist_items';
    }

    public function forTask(int $taskId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM task_checklist_items WHERE task_id = :task_id ORDER BY sort_order, id',
            ['task_id' => $taskId]
        );
    }

    /** The item only when it belongs to $taskId. */
    public function findForTask(int $taskId, int $itemId): ?array
    {
        return $this->tenantFindOne(
            'SELECT * FROM task_checklist_items WHERE id = :id AND task_id = :task_id',
            ['id' => $itemId, 'task_id' => $taskId]
        );
    }

    public function create(int $taskId, string $title, int $userId): int
    {
        $next = $this->tenantFindOne(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 AS next FROM task_checklist_items WHERE task_id = :task_id',
            ['task_id' => $taskId]
        );

        return $this->tenantInsertGetId([
            'task_id' => $taskId,
            'title' => $title,
            'sort_order' => (int) ($next['next'] ?? 1),
            'created_by_user_id' => $userId,
        ]);
    }

    public function updateItem(int $taskId, int $itemId, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id AND task_id = :task_id', ['id' => $itemId, 'task_id' => $taskId]);
    }

    public function deleteItem(int $taskId, int $itemId): void
    {
        $this->tenantDelete('id = :id AND task_id = :task_id', ['id' => $itemId, 'task_id' => $taskId]);
    }

    /**
     * Done / total per task, for list rows (one query).
     *
     * @param int[] $taskIds
     * @return array<int, array{done: int, total: int}>
     */
    public function progressFor(array $taskIds): array
    {
        $taskIds = array_values(array_unique(array_map('intval', $taskIds)));
        if ($taskIds === []) {
            return [];
        }

        $rows = $this->tenantFindMany(
            'SELECT task_id, SUM(is_done) AS done, COUNT(*) AS total FROM task_checklist_items
             WHERE task_id IN (' . implode(',', $taskIds) . ') GROUP BY task_id'
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['task_id']] = ['done' => (int) $row['done'], 'total' => (int) $row['total']];
        }

        return $result;
    }
}
