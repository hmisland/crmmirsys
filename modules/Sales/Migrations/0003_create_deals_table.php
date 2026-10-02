<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * `currency_code` is a plain 3-letter code here, not a currency_id FK
 * into a central currencies table — the full Currency Management
 * system (Phase 0's Rev. 3 decisions: DECIMAL(38,18), centralized
 * currencies table, exchange rates) is its own future phase, not
 * part of Sales. This column is a deliberate placeholder that will
 * be migrated to `currency_id` when that phase lands, not a
 * permanent design choice.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE deals (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                pipeline_id INT UNSIGNED NOT NULL,
                stage_id INT UNSIGNED NOT NULL,
                name VARCHAR(255) NOT NULL,
                value DECIMAL(19,4) NOT NULL DEFAULT 0,
                currency_code VARCHAR(3) NOT NULL DEFAULT 'USD',
                contact_id INT UNSIGNED NULL,
                company_id INT UNSIGNED NULL,
                owner_id INT UNSIGNED NULL,
                team_id INT UNSIGNED NULL,
                source VARCHAR(100) NULL,
                status ENUM('open', 'won', 'lost') NOT NULL DEFAULT 'open',
                expected_close_date DATE NULL,
                closed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_deals_organization (organization_id),
                KEY idx_deals_pipeline (pipeline_id),
                KEY idx_deals_stage (stage_id),
                KEY idx_deals_owner (owner_id),
                CONSTRAINT fk_deals_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_deals_pipeline FOREIGN KEY (pipeline_id) REFERENCES pipelines (id) ON DELETE CASCADE,
                CONSTRAINT fk_deals_stage FOREIGN KEY (stage_id) REFERENCES pipeline_stages (id) ON DELETE CASCADE,
                CONSTRAINT fk_deals_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL,
                CONSTRAINT fk_deals_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL,
                CONSTRAINT fk_deals_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_deals_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS deals');
    }
};
