<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Per Phase 0 §15: roles are per-organization, and org admins can
 * clone/customize system role templates. A role with organization_id
 * IS NULL is a system template (visible to every org, not editable by
 * an org admin); one with organization_id set belongs to that org
 * only. `key` identifies a role within its scope (e.g. 'org_admin')
 * and is what seed data / code refers to — display `name` is
 * separate and editable by an org admin for their own custom roles.
 *
 * Note: MySQL's UNIQUE index treats every NULL as distinct, so
 * `UNIQUE(organization_id, key)` does NOT stop two system-template
 * rows (organization_id NULL) from sharing a key at the DB level —
 * only per-organization rows are actually protected by this
 * constraint. System templates are seeded once by migration 0010
 * with keys the seeder itself controls, so this is safe in practice,
 * but it's not a DB-level guarantee for that specific case.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE roles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NULL,
                `key` VARCHAR(100) NOT NULL,
                name VARCHAR(255) NOT NULL,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_roles_organization (organization_id),
                UNIQUE KEY uniq_roles_org_key (organization_id, `key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS roles');
    }
};
