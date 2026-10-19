<?php

declare(strict_types=1);

namespace Modules\Sales;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * An organization's task labels (task_labels) and their links to tasks
 * (task_label_links). Every read and write is limited to the current
 * organization; authorization is TaskLabelService's job.
 */
final class TaskLabelRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'task_labels';
    }

    public function all(): array
    {
        return $this->tenantFindMany('SELECT * FROM task_labels WHERE 1 = 1 ORDER BY name, id');
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM task_labels WHERE id = :id', ['id' => $id]);
    }

    public function findByName(string $name, ?int $exceptId = null): ?array
    {
        return $this->tenantFindOne(
            'SELECT * FROM task_labels WHERE name = :name AND id <> :except',
            ['name' => $name, 'except' => $exceptId ?? 0]
        );
    }

    public function create(string $name, string $color, int $userId): int
    {
        return $this->tenantInsertGetId(['name' => $name, 'color' => $color, 'created_by_user_id' => $userId]);
    }

    public function updateLabel(int $id, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    /** Deletes the label; its task links go with it (FK CASCADE). Tasks are untouched. */
    public function deleteLabel(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }

    /** @return int[] the label ids on a task */
    public function labelIdsFor(int $taskId): array
    {
        $rows = $this->findMany(
            'SELECT label_id FROM task_label_links WHERE task_id = :task_id AND organization_id = :org_id',
            ['task_id' => $taskId, 'org_id' => OrganizationContext::id()]
        );

        return array_map(fn (array $r) => (int) $r['label_id'], $rows);
    }

    /**
     * Labels of several tasks in one query, ordered by name.
     *
     * @param int[] $taskIds
     * @return array<int, array[]> task id => label rows
     */
    public function labelsForTasks(array $taskIds): array
    {
        $taskIds = array_values(array_unique(array_map('intval', $taskIds)));
        if ($taskIds === []) {
            return [];
        }

        $rows = $this->findMany(
            'SELECT l.task_id, t.id, t.name, t.color FROM task_label_links l
             JOIN task_labels t ON t.id = l.label_id AND t.organization_id = l.organization_id
             WHERE l.organization_id = :org_id AND l.task_id IN (' . implode(',', $taskIds) . ')
             ORDER BY t.name, t.id',
            ['org_id' => OrganizationContext::id()]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['task_id']][] = ['id' => (int) $row['id'], 'name' => $row['name'], 'color' => $row['color']];
        }

        return $result;
    }

    /**
     * Replaces a task's labels with $labelIds (already validated as this
     * organization's labels), in the caller's transaction when one is open.
     *
     * @param int[] $labelIds
     */
    public function setForTask(int $taskId, array $labelIds): void
    {
        $work = function () use ($taskId, $labelIds) {
            $orgId = OrganizationContext::id();
            $this->delete('task_label_links', 'task_id = :task_id AND organization_id = :org_id', ['task_id' => $taskId, 'org_id' => $orgId]);
            foreach (array_unique($labelIds) as $labelId) {
                $this->insertGetId('task_label_links', ['task_id' => $taskId, 'label_id' => $labelId, 'organization_id' => $orgId]);
            }
        };

        $this->pdo()->inTransaction() ? $work() : $this->transaction($work);
    }
}
