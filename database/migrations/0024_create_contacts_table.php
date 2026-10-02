<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE contacts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NULL,
                email VARCHAR(255) NULL,
                phone VARCHAR(50) NULL,
                company_id INT UNSIGNED NULL,
                position VARCHAR(150) NULL,
                address VARCHAR(255) NULL,
                country VARCHAR(100) NULL,
                city VARCHAR(100) NULL,
                source VARCHAR(100) NULL,
                status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
                owner_id INT UNSIGNED NULL,
                team_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_contacts_organization (organization_id),
                KEY idx_contacts_company (company_id),
                KEY idx_contacts_owner (owner_id),
                CONSTRAINT fk_contacts_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_contacts_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL,
                CONSTRAINT fk_contacts_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_contacts_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS contacts');
    }
};
