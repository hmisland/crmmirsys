<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * org_admin-only — a regular member can't issue an invoice
 * (invoices.issue), so they shouldn't be able to correct one either.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE `key` = 'org_admin' AND is_system = 1 LIMIT 1");
        $stmt->execute();
        $roleId = $stmt->fetchColumn();

        if ($roleId === false) {
            return;
        }

        $insert = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_key, scope) VALUES (:role_id, :permission_key, :scope)'
        );

        foreach (['credit_notes.view', 'credit_notes.create'] as $key) {
            $insert->execute(['role_id' => $roleId, 'permission_key' => $key, 'scope' => 'organization']);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM role_permissions WHERE permission_key IN ('credit_notes.view', 'credit_notes.create')");
    }
};
