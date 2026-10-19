<?php

declare(strict_types=1);

namespace Modules\Sales;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * An organization's custom task statuses (task_statuses, Sales migration
 * 0013). Every read and write is limited to the current organization;
 * authorization and the rules are TaskStatusService's job.
 */
final class TaskStatusRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'task_statuses';
    }

    public function all(): array
    {
        return $this->tenantFindMany('SELECT * FROM task_statuses WHERE 1 = 1 ORDER BY sort_order, id');
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM task_statuses WHERE id = :id', ['id' => $id]);
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }

    public function updateStatus(int $id, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteStatus(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }

    public function nextSortOrder(): int
    {
        $row = $this->tenantFindOne('SELECT COALESCE(MAX(sort_order), 0) AS m FROM task_statuses WHERE 1 = 1');

        return (int) ($row['m'] ?? 0) + 10;
    }

    /**
     * Tasks (including archived and deleted ones) shown with this status:
     * chosen explicitly, or — for its legacy value — untouched since T8.
     */
    public function countTasksUsing(int $statusId, ?string $systemKey): int
    {
        $row = $this->findOne(
            'SELECT COUNT(*) AS c FROM tasks WHERE organization_id = :org_id AND (status_id = :id'
            . ($systemKey !== null ? ' OR (status_id IS NULL AND status = :system_key)' : '') . ')',
            ['org_id' => OrganizationContext::id(), 'id' => $statusId] + ($systemKey !== null ? ['system_key' => $systemKey] : [])
        );

        return (int) ($row['c'] ?? 0);
    }

    /** Moves those tasks to another status of the same lifecycle category (legacy value kept in step). */
    public function reassign(int $fromId, ?string $systemKey, int $toId, string $toLegacy): int
    {
        return $this->query(
            'UPDATE tasks SET status_id = :to_id, status = :legacy WHERE organization_id = :org_id AND (status_id = :from_id'
            . ($systemKey !== null ? ' OR (status_id IS NULL AND status = :system_key)' : '') . ')',
            ['to_id' => $toId, 'legacy' => $toLegacy, 'org_id' => OrganizationContext::id(), 'from_id' => $fromId]
                + ($systemKey !== null ? ['system_key' => $systemKey] : [])
        )->rowCount();
    }

    /** Runs $work in a transaction, or inside the caller's when one is already open. */
    public function atomically(callable $work): mixed
    {
        return $this->pdo()->inTransaction() ? $work() : $this->transaction($work);
    }
}
