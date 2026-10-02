<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Runs via Migrator with a module_key of 'demo', tracked in
 * `module_migrations` — completely separate from core's own
 * `migrations` table, per Phase 0 §31. See ModuleController's sync
 * action for where this actually gets invoked.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE demo_notes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                body TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_demo_notes_organization (organization_id),
                CONSTRAINT fk_demo_notes_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS demo_notes');
    }
};
