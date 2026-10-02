<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * Concurrency-safe numbering, the same row-lock-inside-a-transaction
 * pattern already proven for Wallet's balance updates — never a bare
 * MAX(invoice_number)+1, which races under concurrent issuance.
 *
 * Note: organization_id is this table's PRIMARY KEY, not an
 * auto-increment column, so tenantInsertGetId()'s returned "id" is
 * meaningless here (MySQL's lastInsertId() has nothing real to
 * report) — createDefault() ignores it and re-fetches the row by its
 * actual key instead.
 */
final class InvoiceNumberSeriesRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'invoice_number_series';
    }

    public function lockForUpdate(int $organizationId): ?array
    {
        return $this->tenantFindOne('SELECT * FROM invoice_number_series WHERE organization_id = :org_id FOR UPDATE', ['org_id' => $organizationId]);
    }

    public function createDefault(int $organizationId, string $prefix): array
    {
        $this->tenantInsertGetId(['prefix' => $prefix, 'next_sequence' => 1]);

        return $this->lockForUpdate($organizationId);
    }

    public function advance(int $organizationId, int $nextSequence): void
    {
        $this->tenantUpdate(['next_sequence' => $nextSequence], 'organization_id = :org_id', ['org_id' => $organizationId]);
    }
}
