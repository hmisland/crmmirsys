<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Repositories\BaseRepository;

final class TaxProfileRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'tax_profiles';
    }

    /** Platform templates plus this organization's own profiles — what an org admin is allowed to pick from. */
    public function availableFor(int $organizationId): array
    {
        return $this->findMany(
            'SELECT * FROM tax_profiles WHERE organization_id IS NULL OR organization_id = :org_id ORDER BY name',
            ['org_id' => $organizationId]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->findOne('SELECT * FROM tax_profiles WHERE id = :id', ['id' => $id]);
    }
}
