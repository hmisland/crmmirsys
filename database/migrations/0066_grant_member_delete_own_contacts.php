<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * The system Member role may now delete the contacts it owns (scope
 * `own`), matching its existing `contacts.edit` = own — the contacts
 * list shows edit/delete per row, enabled only on the user's own
 * contacts. Organization Admin keeps organization-wide scope.
 * INSERT IGNORE: an org that already gave Member a delete scope keeps it.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE `key` = 'member' AND is_system = 1 LIMIT 1");
        $stmt->execute();
        $roleId = $stmt->fetchColumn();

        if ($roleId === false) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_key, scope) VALUES (:role_id, :permission_key, :scope)'
        );
        $stmt->execute(['role_id' => $roleId, 'permission_key' => 'contacts.delete', 'scope' => 'own']);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec(
            "DELETE rp FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
             WHERE r.`key` = 'member' AND r.is_system = 1 AND rp.permission_key = 'contacts.delete' AND rp.scope = 'own'"
        );
    }
};
