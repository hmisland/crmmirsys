<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Adds the one new permission key Phase 4 introduces to the org_admin
 * system role template. Deliberately a NEW migration rather than
 * editing 0010_seed_system_roles.php — that migration already ran on
 * any deployed instance; a migration must stay a truthful historical
 * record of what it did when it ran; new permission keys are
 * additive migrations, same pattern any module will use later to add
 * its own permissions to existing roles.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE `key` = 'org_admin' AND is_system = 1 LIMIT 1");
        $stmt->execute();
        $roleId = $stmt->fetchColumn();

        if ($roleId === false) {
            // Defensive: if the system role template is somehow
            // missing, don't fail the whole migration run over one
            // permission grant — modules.manage simply won't be
            // pre-granted to anyone until an org admin's role is
            // edited by hand.
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_key, scope) VALUES (:role_id, :permission_key, :scope)'
        );
        $stmt->execute(['role_id' => $roleId, 'permission_key' => 'modules.manage', 'scope' => 'organization']);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM role_permissions WHERE permission_key = 'modules.manage'");
    }
};
