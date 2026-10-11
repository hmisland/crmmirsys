<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * A project of the organization (Core tables only — no foreign key to any
 * other module's table, so Projects installs and runs with Core alone;
 * links to deals/contracts go through project_links, migration 0004).
 *
 * code is assigned on create from the Core number series (PRJ-0001…).
 * owner_id is the project manager; owner_id/team_id/department_id drive
 * RBAC own/team/department scope, the same columns contracts use.
 * actual_end_date is set when the project is completed.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE projects (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                code VARCHAR(50) NOT NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                status ENUM('draft', 'planned', 'active', 'on_hold', 'completed', 'cancelled') NOT NULL DEFAULT 'draft',
                owner_id INT UNSIGNED NOT NULL,
                team_id INT UNSIGNED NULL,
                department_id INT UNSIGNED NULL,
                contact_id INT UNSIGNED NULL,
                company_id INT UNSIGNED NULL,
                start_date DATE NULL,
                expected_end_date DATE NULL,
                actual_end_date DATE NULL,
                created_by_user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_projects_org_code (organization_id, code),
                KEY idx_projects_org_status (organization_id, status),
                KEY idx_projects_owner (owner_id),
                KEY idx_projects_contact (contact_id),
                KEY idx_projects_company (company_id),
                CONSTRAINT fk_projects_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_projects_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_projects_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL,
                CONSTRAINT fk_projects_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL,
                CONSTRAINT fk_projects_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL,
                CONSTRAINT fk_projects_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL,
                CONSTRAINT fk_projects_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS projects');
    }
};
