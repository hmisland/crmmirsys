<?php

declare(strict_types=1);

namespace Modules\Projects;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * project_settings (module migration 0005): one row per organization,
 * keyed by organization_id; absent row = defaults (everything off).
 */
final class ProjectSettingsRepository extends TenantScopedRepository
{
    private const DEFAULTS = ['auto_create_on_deal_won' => 0];

    protected function table(): string
    {
        return 'project_settings';
    }

    /** @return array{auto_create_on_deal_won: int} for the current organization */
    public function current(): array
    {
        $row = $this->tenantFindOne('SELECT * FROM project_settings WHERE 1 = 1');

        return array_merge(self::DEFAULTS, array_intersect_key($row ?? [], self::DEFAULTS));
    }

    public function save(array $settings, ?int $userId): void
    {
        $values = array_merge(self::DEFAULTS, array_intersect_key($settings, self::DEFAULTS));

        $this->query(
            'INSERT INTO project_settings (organization_id, auto_create_on_deal_won, updated_by_user_id)
             VALUES (:org_id, :auto, :user_id)
             ON DUPLICATE KEY UPDATE auto_create_on_deal_won = VALUES(auto_create_on_deal_won), updated_by_user_id = VALUES(updated_by_user_id)',
            ['org_id' => OrganizationContext::id(), 'auto' => (int) $values['auto_create_on_deal_won'], 'user_id' => $userId]
        );
    }
}
