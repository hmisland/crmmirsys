<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Marketing 3.1 (F2): "transactional" campaigns skip marketing opt-outs,
 * so choosing that type needs its own key. Granted to org_admin only;
 * an Org Admin may grant it to another role on /org/roles.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $roleId = $pdo->query("SELECT id FROM roles WHERE `key` = 'org_admin' AND is_system = 1 LIMIT 1")->fetchColumn();
        if ($roleId === false) {
            return;
        }

        $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_key, scope) VALUES (?, 'marketing.send_transactional', 'organization')")
            ->execute([(int) $roleId]);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM role_permissions WHERE permission_key = 'marketing.send_transactional'");
    }
};
