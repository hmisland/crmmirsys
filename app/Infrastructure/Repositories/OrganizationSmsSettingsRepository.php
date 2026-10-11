<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * organization_sms_settings — one row per organization at most; no row
 * means the default (platform mode). Reached with an explicit
 * organization id, like UserModuleOverrideRepository.
 */
final class OrganizationSmsSettingsRepository extends BaseRepository
{
    public function find(int $organizationId): ?array
    {
        try {
            return $this->findOne('SELECT * FROM organization_sms_settings WHERE organization_id = :org_id', ['org_id' => $organizationId]);
        } catch (\PDOException) {
            return null; // not migrated yet → platform default
        }
    }

    public function save(int $organizationId, array $fields): void
    {
        if ($this->find($organizationId) === null) {
            $this->insertGetId('organization_sms_settings', $fields + ['organization_id' => $organizationId]);

            return;
        }

        $this->update('organization_sms_settings', $fields, 'organization_id = :org_id', ['org_id' => $organizationId]);
    }
}
