<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * files.manage stays org_admin only at the ROLE level — per-member
 * upload/delete access beyond that is the file_library_uploaders
 * allow-list (Knowledge module migration 0004), managed by org_admin
 * through its own permission (files.manage_uploaders), not a role
 * grant. Everyone gets files.view/kb.view by default — "needs to be
 * accessible to some or all users" starts from "all can see," with
 * org_admin narrowing who can also change things.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $orgAdminId = $this->roleId($pdo, 'org_admin');
        $memberId = $this->roleId($pdo, 'member');

        if ($orgAdminId !== null) {
            foreach (['kb.view', 'kb.manage', 'files.view', 'files.manage', 'files.manage_uploaders'] as $key) {
                $this->grant($pdo, $orgAdminId, $key, 'organization');
            }
        }

        if ($memberId !== null) {
            $this->grant($pdo, $memberId, 'kb.view', 'organization');
            $this->grant($pdo, $memberId, 'files.view', 'organization');
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec(
            "DELETE FROM role_permissions WHERE permission_key IN (
                'kb.view', 'kb.manage', 'files.view', 'files.manage', 'files.manage_uploaders'
            )"
        );
    }

    private function roleId(\PDO $pdo, string $key): ?int
    {
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE `key` = :key AND is_system = 1 LIMIT 1");
        $stmt->execute(['key' => $key]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function grant(\PDO $pdo, int $roleId, string $permissionKey, string $scope): void
    {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_key, scope) VALUES (:role_id, :permission_key, :scope)'
        );
        $stmt->execute(['role_id' => $roleId, 'permission_key' => $permissionKey, 'scope' => $scope]);
    }
};
