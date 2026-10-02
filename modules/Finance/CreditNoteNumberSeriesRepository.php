<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Repositories\TenantScopedRepository;

final class CreditNoteNumberSeriesRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'credit_note_number_series';
    }

    public function lockForUpdate(int $organizationId): ?array
    {
        return $this->tenantFindOne('SELECT * FROM credit_note_number_series WHERE organization_id = :org_id FOR UPDATE', ['org_id' => $organizationId]);
    }

    public function createDefault(int $organizationId): array
    {
        $this->tenantInsertGetId(['prefix' => 'CN-', 'next_sequence' => 1]);

        return $this->lockForUpdate($organizationId);
    }

    public function advance(int $organizationId, int $nextSequence): void
    {
        $this->tenantUpdate(['next_sequence' => $nextSequence], 'organization_id = :org_id', ['org_id' => $organizationId]);
    }
}
