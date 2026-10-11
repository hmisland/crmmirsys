<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * finance_budget_lines (module migration 0020) — tenant-scoped: every read
 * and write is confined to the caller's organization. Expense budget only,
 * one line per project × category × currency (Stage 5).
 */
final class BudgetRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'finance_budget_lines';
    }

    /** @return array<int, array<string, mixed>> the project's lines (non-locking; a consistent snapshot), by currency then category */
    public function linesForProject(int $projectId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM finance_budget_lines WHERE project_id = :project_id ORDER BY currency_id, category',
            ['project_id' => $projectId]
        );
    }

    /**
     * The project's lines, row-locked (FOR UPDATE) inside the caller's
     * transaction: a concurrent replace of the same budget waits until this
     * one commits, so a budget is always one whole submission, never a mix.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lockLinesForProject(int $projectId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM finance_budget_lines WHERE project_id = :project_id ORDER BY currency_id, category FOR UPDATE',
            ['project_id' => $projectId]
        );
    }

    public function deleteForProject(int $projectId): int
    {
        return $this->tenantDelete('project_id = :project_id', ['project_id' => $projectId]);
    }

    public function insertLine(array $columns): int
    {
        return $this->tenantInsertGetId($columns);
    }

    /** Runs $work atomically, joining the caller's transaction if one is already open. */
    public function atomically(callable $work): mixed
    {
        return $this->pdo()->inTransaction() ? $work() : $this->transaction($work);
    }
}
