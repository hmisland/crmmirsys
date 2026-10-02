<?php

declare(strict_types=1);

namespace Modules\Finance;

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
