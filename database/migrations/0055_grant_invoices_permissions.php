<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Per the client's explicit requirement: any authorized member can
 * create/edit a DRAFT invoice (including via direct Proforma
 * conversion), but only org_admin can actually ISSUE it — the
 * approval step. invoices.issue and invoices.cancel are therefore
 * org_admin-only; everything else follows the same OWN/ORGANIZATION
 * split as Proformas (12.4).
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

        foreach (['invoices.view', 'invoices.create', 'invoices.edit', 'invoices.delete'] as $key) {
            $insert->execute(['role_id' => $roleIdByKey['member'], 'permission_key' => $key, 'scope' => 'own']);
        }

        foreach (['invoices.view', 'invoices.create', 'invoices.edit', 'invoices.delete', 'invoices.issue', 'invoices.cancel'] as $key) {
            $insert->execute(['role_id' => $roleIdByKey['org_admin'], 'permission_key' => $key, 'scope' => 'organization']);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM role_permissions WHERE permission_key IN ('invoices.view', 'invoices.create', 'invoices.edit', 'invoices.delete', 'invoices.issue', 'invoices.cancel')");
    }
};
