<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Phase 2: marketing.manage_segments (create, edit, delete segments and
 * static memberships). Granted to org_admin only; anyone with
 * marketing.view can see segments and use them in campaigns they may
 * create. An Org Admin may grant it to other roles on /org/roles.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $roleId = $pdo->query("SELECT id FROM roles WHERE `key` = 'org_admin' AND is_system = 1 LIMIT 1")->fetchColumn();
        if ($roleId === false) {
            return;
        }

        $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_key, scope) VALUES (?, 'marketing.manage_segments', 'organization')")
            ->execute([(int) $roleId]);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM role_permissions WHERE permission_key = 'marketing.manage_segments'");
    }
};
