<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * Deliberately not tenant-scoped via TenantScopedRepository, even
 * though the table has an organization_id column: callers already
 * have a specific user_id in hand (whose organization is implied by
 * the user itself), and ModuleService resolves overrides for one
 * user at a time — an explicit organization_id + user_id pair is
 * clearer here than relying on ambient OrganizationContext, the same
 * reasoning as UserRepository's own docblock.
 */
final class UserModuleOverrideRepository extends BaseRepository
{
    public function find(int $organizationId, int $userId, int $moduleId): ?array
    {
        return $this->findOne(
            'SELECT * FROM user_module_overrides WHERE organization_id = :org_id AND user_id = :user_id AND module_id = :module_id',
            ['org_id' => $organizationId, 'user_id' => $userId, 'module_id' => $moduleId]
        );
    }

    /** @return array<int, string> user_id => 'enabled'|'disabled', for every override on this module */
    public function forModuleInOrganization(int $organizationId, int $moduleId): array
    {
        $rows = $this->findMany(
            'SELECT user_id, enabled FROM user_module_overrides WHERE organization_id = :org_id AND module_id = :module_id',
            ['org_id' => $organizationId, 'module_id' => $moduleId]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['user_id']] = ((bool) $row['enabled']) ? 'enabled' : 'disabled';
        }

        return $result;
    }

    public function setOverride(int $organizationId, int $userId, int $moduleId, ?bool $enabled): void
    {
        $existing = $this->find($organizationId, $userId, $moduleId);

        if ($enabled === null) {
            if ($existing !== null) {
                $this->delete('user_module_overrides', 'id = :id', ['id' => $existing['id']]);
            }

            return;
        }

        if ($existing === null) {
            $this->insertGetId('user_module_overrides', [
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'module_id' => $moduleId,
                'enabled' => $enabled ? 1 : 0,
            ]);

            return;
        }

        $this->update(
            'user_module_overrides',
            ['enabled' => $enabled ? 1 : 0],
            'id = :id',
            ['id' => $existing['id']]
        );
    }
}
