<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Organization-defined contract kinds (service, lease, NDA, ...).
 * Free names per organization; nothing industry-specific is seeded.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE contract_categories (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                name VARCHAR(150) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_contract_categories_org_name (organization_id, name),
                CONSTRAINT fk_contract_categories_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS contract_categories');
    }
};
