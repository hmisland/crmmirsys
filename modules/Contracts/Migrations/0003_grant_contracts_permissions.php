<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Default grants for the system roles (global rows, so every
 * organization gets them), same shape as 0059_grant_followups_permissions:
 * - org_admin: every contracts.* key, organization scope;
 * - member: view + create at organization scope, edit only their own.
 * Approve/activate/terminate/renew/delete/settings stay org_admin-only;
 * an Org Admin can widen any of this per role on /org/roles.
 *
 * A grant has no effect while the module isn't entitled to the
 * organization — module access is always checked first.
 * Keys are written out here (not read from ContractPermission) so the
 * migration stays a fixed historical record.
 */
return new class implements MigrationInterface {
    private const ALL = [
        'contracts.view', 'contracts.create', 'contracts.edit', 'contracts.delete', 'contracts.approve',
        'contracts.activate', 'contracts.terminate', 'contracts.renew', 'contracts.manage_settings',
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

        foreach (['contracts.view', 'contracts.create'] as $key) {
            $insert->execute(['role_id' => $roleIdByKey['member'], 'permission_key' => $key, 'scope' => 'organization']);
        }

        $insert->execute(['role_id' => $roleIdByKey['member'], 'permission_key' => 'contracts.edit', 'scope' => 'own']);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM role_permissions WHERE permission_key LIKE 'contracts.%'");
    }
};
