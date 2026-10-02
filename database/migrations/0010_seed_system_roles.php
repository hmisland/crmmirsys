<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Seeds the two system role templates every organization can assign
 * from day one. Permission keys are hardcoded here (not read from
 * App\Infrastructure\Authorization\Permission) deliberately — a
 * migration is a historical snapshot of what it did when it ran, and
 * must keep working even if the Permission class's contents change
 * later. Org admins can later clone/customize these into their own
 * organization-specific roles (Phase 0 §15) — that's a Phase 3 UI
 * feature (RoleController), not something this migration does.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $orgAdminId = $this->insertRole($pdo, 'org_admin', 'Organization Admin');
        $memberId = $this->insertRole($pdo, 'member', 'Member');

        // Organization Admin: everything Phase 3 introduces, scoped to
        // their own organization (tenant isolation already prevents
        // reaching another org's data; ORGANIZATION scope here means
        // "not restricted further to own/team/department within it").
        $orgAdminPermissions = [
            'organization.view', 'organization.edit',
            'users.view', 'users.create', 'users.edit', 'users.delete', 'users.activate', 'users.deactivate',
            'departments.view', 'departments.create', 'departments.edit', 'departments.delete',
            'teams.view', 'teams.create', 'teams.edit', 'teams.delete',
            'roles.view', 'roles.create', 'roles.edit', 'roles.delete', 'roles.assign',
        ];

        foreach ($orgAdminPermissions as $key) {
            $this->insertPermission($pdo, $orgAdminId, $key, 'organization');
        }

        // Member: deliberately minimal. Viewing/editing one's own
        // profile is identity-based (Phase 2's /profile), not an RBAC
        // permission, so Member doesn't need anything for that. A
        // Member can see who else is in their org (useful for
        // assigning things to colleagues later) but manage nothing.
        $this->insertPermission($pdo, $memberId, 'users.view', 'organization');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM roles WHERE is_system = 1 AND `key` IN ('org_admin', 'member')");
    }

    private function insertRole(\PDO $pdo, string $key, string $name): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO roles (organization_id, `key`, name, is_system) VALUES (NULL, :key, :name, 1)'
        );
        $stmt->execute(['key' => $key, 'name' => $name]);

        return (int) $pdo->lastInsertId();
    }

    private function insertPermission(\PDO $pdo, int $roleId, string $permissionKey, string $scope): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (:role_id, :permission_key, :scope)'
        );
        $stmt->execute(['role_id' => $roleId, 'permission_key' => $permissionKey, 'scope' => $scope]);
    }
};
