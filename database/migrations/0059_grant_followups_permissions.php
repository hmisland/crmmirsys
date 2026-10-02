<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Same reasoning as tasks.* (0027_grant_sales_permissions): view/create
 * at ORGANIZATION scope for member (a follow-up is often useful for a
 * teammate to see, same as a task), but edit/complete/cancel at OWN
 * scope (only your own). Admin-level actions (delete, assign someone
 * else's follow-up, export, manage automation rules, manage reminder
 * defaults) are org_admin-only.
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

        $insert = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_key, scope) VALUES (:role_id, :permission_key, :scope)'
        );

        foreach (['followups.view', 'followups.create'] as $key) {
            $insert->execute(['role_id' => $roleIdByKey['member'], 'permission_key' => $key, 'scope' => 'organization']);
        }

        foreach (['followups.edit', 'followups.complete', 'followups.cancel'] as $key) {
            $insert->execute(['role_id' => $roleIdByKey['member'], 'permission_key' => $key, 'scope' => 'own']);
        }

        foreach ([
            'followups.view', 'followups.create', 'followups.edit', 'followups.delete',
            'followups.assign', 'followups.complete', 'followups.cancel', 'followups.export',
            'followups.manage_rules', 'followups.manage_reminders',
        ] as $key) {
            $insert->execute(['role_id' => $roleIdByKey['org_admin'], 'permission_key' => $key, 'scope' => 'organization']);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM role_permissions WHERE permission_key LIKE 'followups.%'");
    }
};
