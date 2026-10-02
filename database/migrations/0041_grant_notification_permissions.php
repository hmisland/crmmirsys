<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * org_admin only — charging the module's credit balance (spending the
 * org's Wallet money) and viewing the send log are both admin
 * actions. Any member can still set their OWN bale_chat_id/phone in
 * notification_recipients and check the opt-in box when sending mail
 * — that's identity-scoped self-service, not gated by a permission
 * any more than editing one's own profile is.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE `key` = 'org_admin' AND is_system = 1 LIMIT 1");
        $stmt->execute();
        $orgAdminId = $stmt->fetchColumn();

        if ($orgAdminId === false) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_key, scope) VALUES (:role_id, :permission_key, :scope)'
        );
        foreach (['notifications.charge', 'notifications.view_log'] as $key) {
            $stmt->execute(['role_id' => $orgAdminId, 'permission_key' => $key, 'scope' => 'organization']);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM role_permissions WHERE permission_key IN ('notifications.charge', 'notifications.view_log')");
    }
};
