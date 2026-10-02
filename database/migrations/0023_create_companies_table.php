<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE companies (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                name VARCHAR(255) NOT NULL,
                website VARCHAR(255) NULL,
                email VARCHAR(255) NULL,
                phone VARCHAR(50) NULL,
                address VARCHAR(255) NULL,
                country VARCHAR(100) NULL,
                city VARCHAR(100) NULL,
                industry VARCHAR(100) NULL,
                employees_count INT UNSIGNED NULL,
                owner_id INT UNSIGNED NULL,
                team_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_companies_organization (organization_id),
                KEY idx_companies_owner (owner_id),
                CONSTRAINT fk_companies_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_companies_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_companies_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS companies');
    }
};
