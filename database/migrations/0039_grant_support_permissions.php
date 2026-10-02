<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $orgAdminId = $this->roleId($pdo, 'org_admin');
        $memberId = $this->roleId($pdo, 'member');

        if ($orgAdminId !== null) {
            foreach (['tickets.view', 'tickets.create', 'tickets.edit', 'tickets.delete', 'tickets.assign', 'ticket_categories.manage'] as $key) {
                $this->grant($pdo, $orgAdminId, $key, 'organization');
            }
        }

        // Members: see and act on every ticket in the org (support is
        // a team sport — unlike Deals/Tasks, restricting a member to
        // only tickets assigned to them would block them from helping
        // a colleague's queue), but cannot manage categories or
        // delete tickets.
        if ($memberId !== null) {
            $this->grant($pdo, $memberId, 'tickets.view', 'organization');
            $this->grant($pdo, $memberId, 'tickets.create', 'organization');
            $this->grant($pdo, $memberId, 'tickets.edit', 'organization');
            $this->grant($pdo, $memberId, 'tickets.assign', 'organization');
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec(
            "DELETE FROM role_permissions WHERE permission_key IN (
                'tickets.view', 'tickets.create', 'tickets.edit', 'tickets.delete', 'tickets.assign', 'ticket_categories.manage'
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
