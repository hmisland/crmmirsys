<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Default grants for the budget keys (Stage 5, decision 10): the SYSTEM org_admin
 * role only (global row: key = 'org_admin', is_system = 1, organization_id
 * IS NULL), organization scope, INSERT IGNORE — the Contracts 0003 pattern.
 * Members get nothing by default; custom roles are never touched (an Org
 * Admin grants these per role on /org/roles). down() removes only the rows
 * this migration may have added, i.e. those of the system org_admin role.
 * Keys are written out (not read from code) so the migration stays a fixed
 * historical record.
 */
return new class implements MigrationInterface {
    private const KEYS = ['finance.budgets.view', 'finance.budgets.manage'];

    public function up(\PDO $pdo): void
    {
        $roleId = $this->systemOrgAdmin($pdo);
        if ($roleId === null) {
            return;
        }

        $insert = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_key, scope) VALUES (:role_id, :permission_key, :scope)');
        foreach (self::KEYS as $key) {
            $insert->execute(['role_id' => $roleId, 'permission_key' => $key, 'scope' => 'organization']);
        }
    }

    public function down(\PDO $pdo): void
    {
        $roleId = $this->systemOrgAdmin($pdo);
        if ($roleId === null) {
            return;
        }

        $delete = $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :role_id AND permission_key = :permission_key');
        foreach (self::KEYS as $key) {
            $delete->execute(['role_id' => $roleId, 'permission_key' => $key]);
        }
    }

    private function systemOrgAdmin(\PDO $pdo): ?int
    {
        $id = $pdo->query("SELECT id FROM roles WHERE `key` = 'org_admin' AND is_system = 1 AND organization_id IS NULL LIMIT 1")->fetchColumn();

        return $id === false ? null : (int) $id;
    }
};
