<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Task Manager Phase 0: reopening a completed or cancelled task needs
 * its own key, tasks.reopen. Only the org_admin system role gets it by
 * default (organization scope); members do not. An organization can
 * grant it to any custom role from the role editor.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE `key` = 'org_admin' AND is_system = 1");
        $stmt->execute();

        $insert = $pdo->prepare(
            "INSERT IGNORE INTO role_permissions (role_id, permission_key, scope) VALUES (:role_id, 'tasks.reopen', 'organization')"
        );

        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $roleId) {
            $insert->execute(['role_id' => (int) $roleId]);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM role_permissions WHERE permission_key = 'tasks.reopen'");
    }
};
