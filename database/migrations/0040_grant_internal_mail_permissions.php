<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * mail.view/mail.send go to everyone (org_admin AND member) — sending
 * a message to a colleague is an everyday action, not a privileged
 * one. The two broadcast permissions are org_admin only, per the
 * explicit requirement that "send to all" is an admin action.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $orgAdminId = $this->roleId($pdo, 'org_admin');
        $memberId = $this->roleId($pdo, 'member');

        if ($orgAdminId !== null) {
            foreach (['mail.view', 'mail.send', 'mail.broadcast_team', 'mail.broadcast_customers'] as $key) {
                $this->grant($pdo, $orgAdminId, $key, 'organization');
            }
        }

        if ($memberId !== null) {
            $this->grant($pdo, $memberId, 'mail.view', 'own');
            $this->grant($pdo, $memberId, 'mail.send', 'own');
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec(
            "DELETE FROM role_permissions WHERE permission_key IN (
                'mail.view', 'mail.send', 'mail.broadcast_team', 'mail.broadcast_customers'
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
