<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Phase 2 — reusable audiences.
 *
 * type: static (members in marketing_segment_contacts) | dynamic (rules,
 * a JSON tree validated against the server-side condition allowlist;
 * members are never stored — they are computed when previewed or used).
 * deleted_at: segments are soft-deleted, so campaigns that already used
 * one (and their history) keep working; deleted segments are hidden and
 * cannot be chosen for new campaigns.
 *
 * Rollback drops the table (static memberships go with 0010's table).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE marketing_segments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                name VARCHAR(150) NOT NULL,
                description VARCHAR(500) NULL,
                type VARCHAR(10) NOT NULL,
                rules TEXT NULL,
                owner_id INT UNSIGNED NULL,
                updated_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                KEY idx_marketing_segments_org (organization_id, deleted_at, name),
                CONSTRAINT fk_marketing_segments_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_marketing_segments_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_marketing_segments_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS marketing_segments');
    }
};
