<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * company_name is free text (not a company_id FK) — a lead's company
 * is unverified/unstructured information by definition until
 * conversion; LeadService::convert() is what turns it into a real
 * Company row (or links to an existing one) and a Contact row.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE leads (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NULL,
                email VARCHAR(255) NULL,
                phone VARCHAR(50) NULL,
                company_name VARCHAR(255) NULL,
                status ENUM('new', 'contacted', 'qualified', 'unqualified', 'converted') NOT NULL DEFAULT 'new',
                source VARCHAR(100) NULL,
                score INT NOT NULL DEFAULT 0,
                owner_id INT UNSIGNED NULL,
                team_id INT UNSIGNED NULL,
                converted_at DATETIME NULL,
                converted_contact_id INT UNSIGNED NULL,
                converted_company_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_leads_organization (organization_id),
                KEY idx_leads_owner (owner_id),
                CONSTRAINT fk_leads_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_leads_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_leads_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL,
                CONSTRAINT fk_leads_converted_contact FOREIGN KEY (converted_contact_id) REFERENCES contacts (id) ON DELETE SET NULL,
                CONSTRAINT fk_leads_converted_company FOREIGN KEY (converted_company_id) REFERENCES companies (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS leads');
    }
};
