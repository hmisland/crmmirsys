<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Default grants for the system roles (same shape as Marketing 0006):
 *  - org_admin: cx.view + cx.manage (surveys, follow-up), organization scope;
 *  - member: cx.view.
 * A grant has no effect while the module isn't entitled to the organization.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $stmt = $pdo->prepare("SELECT `key`, id FROM roles WHERE `key` IN ('org_admin', 'member') AND is_system = 1");
        $stmt->execute();
        $roleIdByKey = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        if (!isset($roleIdByKey['org_admin']) || !isset($roleIdByKey['member'])) {
            return;
        }

        $insert = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_key, scope) VALUES (:role_id, :permission_key, :scope)');
        foreach (['cx.view', 'cx.manage'] as $key) {
            $insert->execute(['role_id' => $roleIdByKey['org_admin'], 'permission_key' => $key, 'scope' => 'organization']);
        }
        $insert->execute(['role_id' => $roleIdByKey['member'], 'permission_key' => 'cx.view', 'scope' => 'organization']);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM role_permissions WHERE permission_key LIKE 'cx.%'");
    }
};
