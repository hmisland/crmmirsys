<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * finance_installments (module migration 0017) — tenant-scoped like every
 * other Finance table: every read and write is confined to the caller's
 * organization, so another organization's installment id is "not found".
 */
final class InstallmentRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'finance_installments';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM finance_installments WHERE id = :id', ['id' => $id]);
    }

    /** Row-locked read, for every check that must not race a concurrent write on the same installment. */
    public function lockForUpdate(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM finance_installments WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    /** @return array<int, array<string, mixed>> the schedule of one record (voided rows included), by sequence */
    public function forLinked(string $linkedType, int $linkedId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM finance_installments WHERE linked_type = :type AND linked_id = :id ORDER BY sequence',
            ['type' => $linkedType, 'id' => $linkedId]
        );
    }

    public function nextSequence(string $linkedType, int $linkedId): int
    {
        $row = $this->tenantFindOne(
            'SELECT COALESCE(MAX(sequence), 0) AS max_sequence FROM finance_installments WHERE linked_type = :type AND linked_id = :id',
            ['type' => $linkedType, 'id' => $linkedId]
        );

        return (int) ($row['max_sequence'] ?? 0) + 1;
    }

    /** @return int[] distinct currencies of the record's NON-void installments */
    public function activeCurrencies(string $linkedType, int $linkedId): array
    {
        $rows = $this->tenantFindMany(
            'SELECT DISTINCT currency_id FROM finance_installments WHERE linked_type = :type AND linked_id = :id AND voided_at IS NULL',
            ['type' => $linkedType, 'id' => $linkedId]
        );

        return array_map(fn (array $r) => (int) $r['currency_id'], $rows);
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }

    public function updateInstallment(int $id, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    /** Runs $work atomically, joining the caller's transaction if one is already open. */
    public function atomically(callable $work): mixed
    {
        return $this->pdo()->inTransaction() ? $work() : $this->transaction($work);
    }
}
