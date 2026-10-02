<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * Not tenant-scoped: role_permissions rows are reached via a role_id
 * the caller already resolved (from the user's own role_id, or from
 * a role the RoleService already verified belongs to the caller's
 * organization) — there is no organization_id column on this table
 * to scope by directly.
 */
final class RolePermissionRepository extends BaseRepository
{
    public function findForRole(int $roleId, string $permissionKey): ?array
    {
        return $this->findOne(
            'SELECT * FROM role_permissions WHERE role_id = :role_id AND permission_key = :permission_key',
            ['role_id' => $roleId, 'permission_key' => $permissionKey]
        );
    }

    public function allForRole(int $roleId): array
    {
        return $this->findMany('SELECT * FROM role_permissions WHERE role_id = :role_id', ['role_id' => $roleId]);
    }

    /**
     * Replaces the full permission set for a role in one go — simpler
     * and less error-prone for a role-edit form than diffing
     * individual add/remove operations.
     *
     * @param array<string, string> $permissions permission_key => scope
     */
    public function replaceForRole(int $roleId, array $permissions): void
    {
        $this->transaction(function () use ($roleId, $permissions) {
            $this->delete('role_permissions', 'role_id = :role_id', ['role_id' => $roleId]);

            foreach ($permissions as $permissionKey => $scope) {
                $this->insertGetId('role_permissions', [
                    'role_id' => $roleId,
                    'permission_key' => $permissionKey,
                    'scope' => $scope,
                ]);
            }
        });
    }
}
