<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * Not tenant-scoped via TenantScopedRepository for the same reason as
 * UserModuleOverrideRepository: callers always hold a specific user
 * (and therefore that user's organization_id) — AuthorizationEngine
 * runs before OrganizationContext is guaranteed to be resolved.
 */
final class UserPermissionGrantRepository extends BaseRepository
{
    public function has(int $organizationId, int $userId, string $permissionKey): bool
    {
        return $this->findOne(
            'SELECT id FROM user_permission_grants WHERE organization_id = :org_id AND user_id = :user_id AND permission_key = :permission_key',
            ['org_id' => $organizationId, 'user_id' => $userId, 'permission_key' => $permissionKey]
        ) !== null;
    }

    /** @return array<int, string[]> user_id => granted permission keys */
    public function allInOrganization(int $organizationId): array
    {
        $rows = $this->findMany(
            'SELECT user_id, permission_key FROM user_permission_grants WHERE organization_id = :org_id',
            ['org_id' => $organizationId]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['user_id']][] = $row['permission_key'];
        }

        return $result;
    }

    public function grant(int $organizationId, int $userId, string $permissionKey, int $grantedBy): void
    {
        if ($this->has($organizationId, $userId, $permissionKey)) {
            return;
        }

        $this->insertGetId('user_permission_grants', [
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'permission_key' => $permissionKey,
            'granted_by_user_id' => $grantedBy,
        ]);
    }

    public function revoke(int $organizationId, int $userId, string $permissionKey): void
    {
        $this->delete(
            'user_permission_grants',
            'organization_id = :org_id AND user_id = :user_id AND permission_key = :permission_key',
            ['org_id' => $organizationId, 'user_id' => $userId, 'permission_key' => $permissionKey]
        );
    }
}
