<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * One row per (entity, field), value always stored as TEXT regardless
 * of field_type — the definition's field_type governs how it's
 * validated/rendered, not how it's stored. Simpler than a
 * type-specific column per value type for the MVP; revisit only if
 * querying/filtering by custom field value at scale becomes a real
 * need (out of scope for Phase 5).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE custom_field_values (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                entity_type VARCHAR(50) NOT NULL,
                entity_id INT UNSIGNED NOT NULL,
                field_key VARCHAR(100) NOT NULL,
                value TEXT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_custom_field_value (organization_id, entity_type, entity_id, field_key),
                CONSTRAINT fk_custom_field_values_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS custom_field_values');
    }
};
