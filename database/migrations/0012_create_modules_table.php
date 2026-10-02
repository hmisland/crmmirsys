<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Per Phase 0 §7: the module registry, now persisted (Phase 1's
 * ModuleRegistry only ever read module.json from disk). `dependencies`
 * is stored as JSON (an array of module keys) rather than a join
 * table — dependency lists are small, read far more than written, and
 * always read as a whole list, so JSON avoids a join for no real
 * benefit. Rows here are populated by ModuleService::syncFromRegistry(),
 * not by hand — this table mirrors what's on disk, filesystem is the
 * source of truth for "does this module exist", this table is the
 * source of truth for "is it enabled".
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE modules (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `key` VARCHAR(100) NOT NULL,
                name VARCHAR(255) NOT NULL,
                version VARCHAR(20) NOT NULL,
                type ENUM('core', 'optional', 'addon', 'future') NOT NULL DEFAULT 'optional',
                is_core TINYINT(1) NOT NULL DEFAULT 0,
                status ENUM('active', 'disabled', 'deprecated') NOT NULL DEFAULT 'active',
                dependencies JSON NOT NULL,
                installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_modules_key (`key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS modules');
    }
};
