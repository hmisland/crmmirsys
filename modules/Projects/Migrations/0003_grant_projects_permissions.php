<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Default grants for the system roles (global rows, so every
 * organization gets them), same shape as contracts/0003:
 * - org_admin: every projects.* key, organization scope;
 * - member: view + create at organization scope; edit and
 *   manage_members only on projects they manage (own).
 * Delete and settings stay org_admin-only; an Org Admin can widen any
 * of this per role on /org/roles.
 *
 * A grant has no effect while the module isn't entitled to the
 * organization — module access is always checked first.
 * Keys are written out here (not read from ProjectPermission) so the
 * migration stays a fixed historical record.
 */
return new class implements MigrationInterface {
    private const ALL = [
        'projects.view', 'projects.create', 'projects.edit', 'projects.delete',
        'projects.manage_members', 'projects.manage_settings',
    ];

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

        foreach (self::ALL as $key) {
            $insert->execute(['role_id' => $roleIdByKey['org_admin'], 'permission_key' => $key, 'scope' => 'organization']);
        }

        foreach (['projects.view', 'projects.create'] as $key) {
            $insert->execute(['role_id' => $roleIdByKey['member'], 'permission_key' => $key, 'scope' => 'organization']);
        }

        foreach (['projects.edit', 'projects.manage_members'] as $key) {
            $insert->execute(['role_id' => $roleIdByKey['member'], 'permission_key' => $key, 'scope' => 'own']);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM role_permissions WHERE permission_key LIKE 'projects.%'");
    }
};
