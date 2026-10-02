<?php

declare(strict_types=1);

namespace Modules\Wallet;

use App\Infrastructure\Repositories\TenantScopedRepository;

final class WalletOrgSettingsRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'wallet_org_settings';
    }

    public function find(int $organizationId): ?array
    {
        return $this->findOne(
            'SELECT * FROM wallet_org_settings WHERE organization_id = :org_id',
            ['org_id' => $organizationId]
        );
    }

    public function upsert(int $organizationId, array $fields): void
    {
        $existing = $this->find($organizationId);

        if ($existing === null) {
            $fields['organization_id'] = $organizationId;
            $this->insertGetId($this->table(), $fields);

            return;
        }

        $this->update($this->table(), $fields, 'organization_id = :org_id', ['org_id' => $organizationId]);
    }
}
