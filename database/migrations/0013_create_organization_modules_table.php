<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Per Phase 0 §7: per-organization module entitlement. No row for a
 * (organization, module) pair means "not enabled" — rows are only
 * created when an org admin (or Super Admin) explicitly turns a
 * module on, never implicitly. CORE modules never get a row at all:
 * ModuleService short-circuits "is this core module enabled" to
 * always-true without touching this table (per Phase 0 §5: the Core
 * stays as small as practical, but what's in it is always on).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE organization_modules (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                module_id INT UNSIGNED NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                enabled_at DATETIME NULL,
                disabled_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_org_module (organization_id, module_id),
                CONSTRAINT fk_org_modules_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_org_modules_module FOREIGN KEY (module_id) REFERENCES modules (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS organization_modules');
    }
};
