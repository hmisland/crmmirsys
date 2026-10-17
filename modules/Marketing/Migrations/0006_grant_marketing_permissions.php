<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Default grants for the system roles (global rows), same shape as
 * Contracts 0003:
 *  - org_admin: every marketing.* key, organization scope;
 *  - member: view + create (drafts) — sending stays with whoever holds
 *    marketing.send, so a member's campaign needs approval.
 * A grant has no effect while the module isn't entitled to the
 * organization. Keys are written out so the migration stays fixed.
 */
return new class implements MigrationInterface {
    private const ALL = [
        'marketing.view', 'marketing.create', 'marketing.send', 'marketing.manage_templates', 'marketing.manage_suppressions',
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

        foreach (['marketing.view', 'marketing.create'] as $key) {
            $insert->execute(['role_id' => $roleIdByKey['member'], 'permission_key' => $key, 'scope' => 'organization']);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM role_permissions WHERE permission_key LIKE 'marketing.%'");
    }
};
