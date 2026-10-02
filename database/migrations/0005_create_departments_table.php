<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Flat (no parent/hierarchy) for the MVP — Phase 0 lists Departments
 * as an organization structure concept without requiring nesting.
 * Adding a nullable parent_department_id later is non-destructive if
 * a hierarchy turns out to be needed.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE departments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                name VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_departments_organization (organization_id),
                CONSTRAINT fk_departments_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS departments');
    }
};
