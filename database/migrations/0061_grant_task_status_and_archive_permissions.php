<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Changing a task's status and archiving it are open to everyone in
 * the organization (product decision), so both system roles get them
 * at ORGANIZATION scope. Deleting stays tasks.delete — org_admin only,
 * unchanged. Kept as separate keys (not folded into tasks.edit, which
 * members only hold at OWN scope) so an org can still restrict them
 * per custom role.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $stmt = $pdo->prepare("SELECT `key`, id FROM roles WHERE `key` IN ('org_admin', 'member') AND is_system = 1");
        $stmt->execute();
        $roleIdByKey = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

        $insert = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_key, scope) VALUES (:role_id, :permission_key, :scope)'
        );

        foreach (['org_admin', 'member'] as $roleKey) {
            if (!isset($roleIdByKey[$roleKey])) {
                continue;
            }
            foreach (['tasks.change_status', 'tasks.archive'] as $key) {
                $insert->execute(['role_id' => $roleIdByKey[$roleKey], 'permission_key' => $key, 'scope' => 'organization']);
            }
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM role_permissions WHERE permission_key IN ('tasks.change_status', 'tasks.archive')");
    }
};
