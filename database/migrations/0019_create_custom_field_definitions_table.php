<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Per Phase 0 §45: metadata-driven custom fields — a new field is a
 * new row here, never a new database column, per entity_type
 * ('contact', 'company', 'lead', ...) and organization.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE custom_field_definitions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                entity_type VARCHAR(50) NOT NULL,
                field_key VARCHAR(100) NOT NULL,
                label VARCHAR(255) NOT NULL,
                field_type ENUM('text', 'number', 'date', 'boolean', 'select', 'multiselect', 'email', 'phone', 'url') NOT NULL,
                options JSON NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_custom_field (organization_id, entity_type, field_key),
                CONSTRAINT fk_custom_field_definitions_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS custom_field_definitions');
    }
};
