<?php

declare(strict_types=1);

namespace Modules\Sales;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * Recurring tasks (T7): finding series whose next occurrence is due and
 * writing that occurrence. Everything except organizationsWithDueSeries()
 * is limited to the current organization; copies (checklist, labels)
 * read and write only rows of that organization.
 *
 * A "head" is the latest occurrence of a series: an active recurring task
 * no other task was generated from yet (UNIQUE tasks.recur_parent_id).
 */
final class TaskRecurrenceRepository extends TenantScopedRepository
{
    private const HEAD = "tasks.recur_active = 1 AND tasks.recur_type IS NOT NULL AND tasks.recur_anchor IS NOT NULL
        AND tasks.deleted_at IS NULL AND tasks.archived_at IS NULL
        AND NOT EXISTS (SELECT 1 FROM tasks c WHERE c.recur_parent_id = tasks.id)";

    protected function table(): string
    {
        return 'tasks';
    }

    /**
     * Active organizations, with Sales on, that have at least one active
     * series head. The only cross-organization read.
     *
     * @return int[]
     */
    public function organizationsWithDueSeries(): array
    {
        $rows = $this->findMany(
            "SELECT DISTINCT tasks.organization_id FROM tasks
             JOIN organizations o ON o.id = tasks.organization_id AND o.status = 'active'
             JOIN modules m ON m.`key` = 'sales' AND m.status = 'active'
             JOIN organization_modules om ON om.organization_id = tasks.organization_id AND om.module_id = m.id AND om.enabled = 1
             WHERE " . self::HEAD . ' ORDER BY tasks.organization_id'
        );

        return array_map(fn (array $r) => (int) $r['organization_id'], $rows);
    }

    /** This organization's heads whose own date (start, else due — local wall clock) has come. */
    public function dueSeries(string $localNow, int $limit): array
    {
        return $this->findMany(
            'SELECT * FROM tasks WHERE organization_id = :org_id AND ' . self::HEAD . '
                AND COALESCE(start_at, due_at) <= :now ORDER BY COALESCE(start_at, due_at), id LIMIT ' . max(1, $limit),
            ['org_id' => OrganizationContext::id(), 'now' => $localNow]
        );
    }

    /** Re-reads a head under a row lock (inside the caller's transaction); null once it is no longer one. */
    public function lockHead(int $id): ?array
    {
        return $this->findOne(
            'SELECT * FROM tasks WHERE id = :id AND organization_id = :org_id AND ' . self::HEAD . ' FOR UPDATE',
            ['id' => $id, 'org_id' => OrganizationContext::id()]
        );
    }

    /** The next occurrence; null when one already exists for this parent (UNIQUE recur_parent_id). */
    public function insertOccurrence(array $fields): ?int
    {
        try {
            return $this->tenantInsertGetId($fields);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uniq_tasks_recur_parent')) {
                return null;
            }
            throw $e;
        }
    }

    /** The checklist's items, in order, all open again (never the done state). */
    public function copyChecklist(int $fromTaskId, int $toTaskId): void
    {
        $this->query(
            'INSERT INTO task_checklist_items (organization_id, task_id, title, is_done, sort_order, created_by_user_id)
             SELECT organization_id, :to_id, title, 0, sort_order, created_by_user_id
             FROM task_checklist_items WHERE task_id = :from_id AND organization_id = :org_id ORDER BY sort_order, id',
            ['to_id' => $toTaskId, 'from_id' => $fromTaskId, 'org_id' => OrganizationContext::id()]
        );
    }

    /** The task's labels — only this organization's own. */
    public function copyLabels(int $fromTaskId, int $toTaskId): void
    {
        $this->query(
            'INSERT INTO task_label_links (task_id, label_id, organization_id)
             SELECT :to_id, l.label_id, l.organization_id FROM task_label_links l
             JOIN task_labels tl ON tl.id = l.label_id AND tl.organization_id = :org_id1
             WHERE l.task_id = :from_id AND l.organization_id = :org_id2',
            ['to_id' => $toTaskId, 'from_id' => $fromTaskId, 'org_id1' => OrganizationContext::id(), 'org_id2' => OrganizationContext::id()]
        );
    }

    /** The series has passed its end date: it stops (shown as inactive). */
    public function endSeries(int $id): void
    {
        $this->tenantUpdate(['recur_active' => 0], 'id = :id', ['id' => $id]);
    }

    /** The occurrence generated from this task, if any (deleted ones included, for traceability). */
    public function childOf(int $taskId): ?array
    {
        return $this->tenantFindOne('SELECT id, deleted_at FROM tasks WHERE recur_parent_id = :id', ['id' => $taskId]);
    }

    /** Runs $work in a transaction, or inside the caller's when one is already open. */
    public function atomically(callable $work): mixed
    {
        return $this->pdo()->inTransaction() ? $work() : $this->transaction($work);
    }
}
