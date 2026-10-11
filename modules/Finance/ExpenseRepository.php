<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * finance_expenses (module migration 0018) — tenant-scoped: every read and
 * write is confined to the caller's organization. Queries that join or read
 * another table scope it explicitly.
 */
final class ExpenseRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'finance_expenses';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM finance_expenses WHERE id = :id', ['id' => $id]);
    }

    /** Row-locked read; every write re-checks its rules on this row (Phase 4, lifecycle). */
    public function lockForUpdate(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM finance_expenses WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    /** @return array<int, array<string, mixed>> corrections of one original, oldest first (voided included) */
    public function correctionsOf(int $originalId): array
    {
        return $this->tenantFindMany(
            "SELECT * FROM finance_expenses WHERE correction_of_id = :id AND entry_type = 'correction' ORDER BY id",
            ['id' => $originalId]
        );
    }

    /**
     * One page of the list, already restricted by the caller's scope filters
     * (owner_id / team_id / department_id). Corrections are listed too.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->conditions($filters);
        $total = (int) ($this->findOne("SELECT COUNT(*) AS n FROM finance_expenses e WHERE {$where}", $params)['n'] ?? 0);
        $rows = $this->findMany(
            "SELECT e.* FROM finance_expenses e WHERE {$where} ORDER BY e.expense_date DESC, e.id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<int, array<string, mixed>> recorded rows (originals and corrections) attributed to a project */
    public function recordedForProject(int $projectId): array
    {
        return $this->tenantFindMany(
            "SELECT * FROM finance_expenses WHERE project_id = :project_id AND status = 'recorded' ORDER BY id",
            ['project_id' => $projectId]
        );
    }

    /**
     * D-P3: the status of the financial period covering $date, read with a
     * LOCKING read (works on MySQL and MariaDB) so it serializes with
     * FinancialPeriodService::close() / reopen() (a single UPDATE on that
     * row): a pending close is waited for and then seen; a close that comes
     * later waits for this transaction. true = a closed period covers $date.
     * The table is Finance's own; scoped explicitly to the organization.
     */
    public function isDateLockedForUpdate(string $date): bool
    {
        $rows = $this->findMany(
            'SELECT status FROM financial_periods
              WHERE organization_id = :org_id AND starts_on <= :d1 AND ends_on >= :d2
              LOCK IN SHARE MODE',
            ['org_id' => OrganizationContext::id(), 'd1' => $date, 'd2' => $date]
        );
        foreach ($rows as $row) {
            if ($row['status'] === 'closed') {
                return true;
            }
        }

        return false;
    }

    /** Same question without a lock — for page flags only; actions use isDateLockedForUpdate(). */
    public function isDateLocked(string $date): bool
    {
        $row = $this->findOne(
            "SELECT COUNT(*) AS n FROM financial_periods
              WHERE organization_id = :org_id AND status = 'closed' AND starts_on <= :d1 AND ends_on >= :d2",
            ['org_id' => OrganizationContext::id(), 'd1' => $date, 'd2' => $date]
        );

        return (int) ($row['n'] ?? 0) > 0;
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }

    public function updateExpense(int $id, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    /** Runs $work atomically, joining the caller's transaction if one is already open. */
    public function atomically(callable $work): mixed
    {
        return $this->pdo()->inTransaction() ? $work() : $this->transaction($work);
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function conditions(array $filters): array
    {
        $where = ['e.organization_id = :org_id'];
        $params = ['org_id' => OrganizationContext::id()];
        $map = [
            'owner_id' => 'e.owner_id', 'team_id' => 'e.team_id', 'department_id' => 'e.department_id',
            'status' => 'e.status', 'category' => 'e.category', 'currency_id' => 'e.currency_id', 'project_id' => 'e.project_id',
        ];
        foreach ($map as $key => $column) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $where[] = "{$column} = :{$key}";
                $params[$key] = $filters[$key];
            }
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'e.expense_date >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'e.expense_date <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }
        if (array_key_exists('team_id', $filters) && $filters['team_id'] === null) {
            $where[] = '1 = 0'; // team scope without a team: nothing
        }
        if (array_key_exists('department_id', $filters) && $filters['department_id'] === null) {
            $where[] = '1 = 0';
        }

        return [implode(' AND ', $where), $params];
    }
}
