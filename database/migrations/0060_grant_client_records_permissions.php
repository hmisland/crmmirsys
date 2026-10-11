<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Client Records module. Members can see and open records across the
 * organization (reception needs that), but everything that touches
 * clinical-style detail — sensitive intake fields, media, documents,
 * sessions — is OWN scope, i.e. only on records they own or are the
 * assigned specialist for. Permanent delete and export stay with
 * org_admin. Granting a permission is not the same as the module being
 * enabled for the organization: ModuleMiddleware still gates both.
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

        foreach (['client_records.view', 'client_records.create'] as $key) {
            $insert->execute(['role_id' => $roleIdByKey['member'], 'permission_key' => $key, 'scope' => 'organization']);
        }

        foreach ([
            'client_records.edit', 'client_records.view_sensitive', 'client_records.manage_sessions',
            'client_records.manage_media', 'client_records.manage_documents',
        ] as $key) {
            $insert->execute(['role_id' => $roleIdByKey['member'], 'permission_key' => $key, 'scope' => 'own']);
        }

        foreach ([
            'client_records.view', 'client_records.create', 'client_records.edit', 'client_records.delete',
            'client_records.view_sensitive', 'client_records.manage_sessions', 'client_records.manage_media',
            'client_records.manage_documents', 'client_records.export',
        ] as $key) {
            $insert->execute(['role_id' => $roleIdByKey['org_admin'], 'permission_key' => $key, 'scope' => 'organization']);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM role_permissions WHERE permission_key LIKE 'client_records.%'");
    }
};
