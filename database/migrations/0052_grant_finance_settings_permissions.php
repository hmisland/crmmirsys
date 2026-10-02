<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE `key` = 'org_admin' AND is_system = 1 LIMIT 1");
        $stmt->execute();
        $roleId = $stmt->fetchColumn();

        if ($roleId === false) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_key, scope) VALUES (:role_id, :permission_key, :scope)'
        );
        foreach (['finance.settings.view', 'finance.settings.edit'] as $key) {
            $stmt->execute(['role_id' => $roleId, 'permission_key' => $key, 'scope' => 'organization']);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM role_permissions WHERE permission_key IN ('finance.settings.view', 'finance.settings.edit')");
    }
};
