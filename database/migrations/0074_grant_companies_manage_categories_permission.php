<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/** companies.manage_categories for the org_admin template — same as contacts.manage_categories (0048). */
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
        $stmt->execute(['role_id' => $roleId, 'permission_key' => 'companies.manage_categories', 'scope' => 'organization']);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM role_permissions WHERE permission_key = 'companies.manage_categories'");
    }
};
