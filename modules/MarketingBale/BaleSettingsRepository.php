<?php

declare(strict_types=1);

namespace Modules\MarketingBale;

use App\Infrastructure\Repositories\BaseRepository;
use App\Infrastructure\Repositories\OrganizationContext;

/**
 * organization_bale_settings (MarketingBale 0001) — one row per
 * organization. Every query carries an explicit organization_id from
 * OrganizationContext (also under a Super Admin context, unlike the
 * TenantScopedRepository helpers): credentials are only ever read for
 * the organization being served.
 */
final class BaleSettingsRepository extends BaseRepository
{
    public function current(): ?array
    {
        return $this->findOne('SELECT * FROM organization_bale_settings WHERE organization_id = :org_id', ['org_id' => OrganizationContext::id()]);
    }

    public function save(array $fields): void
    {
        unset($fields['organization_id'], $fields['id']);
        if ($this->current() === null) {
            $this->insertGetId('organization_bale_settings', $fields + ['organization_id' => OrganizationContext::id()]);

            return;
        }

        $this->update('organization_bale_settings', $fields, 'organization_id = :org_id', ['org_id' => OrganizationContext::id()]);
    }
}
