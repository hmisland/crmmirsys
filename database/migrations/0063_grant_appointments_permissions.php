<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Appointments module. The calendar is shared operational data: every
 * member can see it, book and reschedule (front-desk work). Deleting
 * an appointment (instead of cancelling it) and managing resources,
 * appointment types and working hours stay with org_admin. Scopes can
 * be narrowed per role from the roles page. Granting a permission is
 * not the same as the module being enabled: ModuleMiddleware still
 * gates both.
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

        foreach (['appointments.view', 'appointments.create', 'appointments.edit'] as $key) {
            $insert->execute(['role_id' => $roleIdByKey['member'], 'permission_key' => $key, 'scope' => 'organization']);
        }

        foreach ([
            'appointments.view', 'appointments.create', 'appointments.edit',
            'appointments.delete', 'appointments.manage_settings',
        ] as $key) {
            $insert->execute(['role_id' => $roleIdByKey['org_admin'], 'permission_key' => $key, 'scope' => 'organization']);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM role_permissions WHERE permission_key LIKE 'appointments.%'");
    }
};
