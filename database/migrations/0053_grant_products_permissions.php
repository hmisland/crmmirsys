<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * products.view goes to both system roles so any team member can
 * browse the catalog when building a Proforma/Invoice later (12.4/
 * 12.5) — matching how ticket categories and banks are admin-managed
 * but visible to everyone. Create/edit/delete stay org_admin-only,
 * the same "admin curates the list" pattern.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $stmt = $pdo->prepare("SELECT `key`, id FROM roles WHERE `key` IN ('org_admin', 'member') AND is_system = 1");
        $stmt->execute();
        $roleIdByKey = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

        if (!isset($roleIdByKey['org_admin'])) {
            return;
        }

        $insert = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_key, scope) VALUES (:role_id, :permission_key, :scope)'
        );

        foreach (['products.view', 'products.create', 'products.edit', 'products.delete'] as $key) {
            $insert->execute(['role_id' => $roleIdByKey['org_admin'], 'permission_key' => $key, 'scope' => 'organization']);
        }

        if (isset($roleIdByKey['member'])) {
            $insert->execute(['role_id' => $roleIdByKey['member'], 'permission_key' => 'products.view', 'scope' => 'organization']);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM role_permissions WHERE permission_key IN ('products.view', 'products.create', 'products.edit', 'products.delete')");
    }
};
