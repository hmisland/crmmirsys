<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

final class FinancialPeriodRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'financial_periods';
    }

    public function all(): array
    {
        return $this->tenantFindMany('SELECT * FROM financial_periods WHERE 1 = 1 ORDER BY starts_on DESC');
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM financial_periods WHERE id = :id', ['id' => $id]);
    }

    /**
     * Stage 5.2 (L5) — the period-lock protocol every financial write follows
     * inside its own transaction, after locking its document row: a SHARED
     * locking read of the period rows covering $date (the same query Expenses
     * use since Stage 4, D-P3). close() / reopen() take an EXCLUSIVE lock on
     * the period row, so a close waits for in-flight writes and a write waits
     * for an in-flight close, then sees its result. Under REPEATABLE READ the
     * range read also takes next-key locks on this organization's index range,
     * so a covering period can't be inserted concurrently. Explicit
     * organization filter: other organizations are never locked.
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

    /** Exclusive row lock for close / reopen (L5): the status is re-checked under it. */
    public function lockForUpdate(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM financial_periods WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    /**
     * Overlap check for a NEW period as a locking read (L5): the rows found and,
     * under REPEATABLE READ, the gaps of this organization's index range are
     * locked, so two concurrent creations of overlapping periods can't both
     * pass — the second waits (or, on a deadlock, is rolled back) and then sees
     * the first.
     */
    public function overlappingForUpdate(string $startsOn, string $endsOn): array
    {
        return $this->findMany(
            'SELECT id FROM financial_periods
              WHERE organization_id = :org_id AND starts_on <= :ends_on AND ends_on >= :starts_on
              FOR UPDATE',
            ['org_id' => OrganizationContext::id(), 'starts_on' => $startsOn, 'ends_on' => $endsOn]
        );
    }

    /** Runs $work atomically, joining the caller's transaction if one is already open. */
    public function atomically(callable $work): mixed
    {
        return $this->pdo()->inTransaction() ? $work() : $this->transaction($work);
    }

    /** Any period (closed or open) overlapping this date range — used to reject an overlapping new period. */
    public function overlapping(string $startsOn, string $endsOn): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM financial_periods WHERE starts_on <= :ends_on AND ends_on >= :starts_on',
            ['starts_on' => $startsOn, 'ends_on' => $endsOn]
        );
    }

    /** The closed period covering a given date, if any, scoped to the current tenant context — this is what actually blocks a write. */
    public function closedPeriodCovering(string $date): ?array
    {
        return $this->tenantFindOne(
            "SELECT * FROM financial_periods WHERE status = 'closed' AND starts_on <= :date AND ends_on >= :date2 LIMIT 1",
            ['date' => $date, 'date2' => $date]
        );
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }

    public function updatePeriod(int $id, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }
}
