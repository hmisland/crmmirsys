<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * A contract with a contact and/or company (Core tables only — no
 * foreign key to any other module's table, so Contracts installs and
 * runs with Core alone; links to deals etc. come later through
 * contract_links, owned by this module).
 *
 * contract_number stays NULL until the contract is activated (numbered
 * from the Core number series then). status `expired` may be stored,
 * but expiry is also computed on read from end_date (no scheduler).
 * Money follows Finance's convention: DECIMAL(38,18) + currency_id.
 * owner_id/team_id/department_id drive RBAC own/team/department scope,
 * the same columns Finance documents use.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE contracts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                contract_number VARCHAR(50) NULL,
                title VARCHAR(255) NOT NULL,
                category_id INT UNSIGNED NULL,
                contact_id INT UNSIGNED NULL,
                company_id INT UNSIGNED NULL,
                owner_id INT UNSIGNED NOT NULL,
                team_id INT UNSIGNED NULL,
                department_id INT UNSIGNED NULL,
                status ENUM('draft', 'pending_approval', 'active', 'expired', 'terminated', 'cancelled') NOT NULL DEFAULT 'draft',
                start_date DATE NULL,
                end_date DATE NULL,
                signed_at DATETIME NULL,
                terminated_at DATETIME NULL,
                value DECIMAL(38,18) NULL,
                currency_id INT UNSIGNED NULL,
                auto_renew TINYINT(1) NOT NULL DEFAULT 0,
                renewal_notice_days SMALLINT UNSIGNED NULL,
                parent_contract_id INT UNSIGNED NULL,
                relation ENUM('original', 'renewal', 'amendment') NOT NULL DEFAULT 'original',
                description TEXT NULL,
                terms TEXT NULL,
                created_by_user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_contracts_org_number (organization_id, contract_number),
                KEY idx_contracts_org_status (organization_id, status, end_date),
                KEY idx_contracts_owner (owner_id),
                KEY idx_contracts_contact (contact_id),
                KEY idx_contracts_company (company_id),
                CONSTRAINT fk_contracts_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_contracts_category FOREIGN KEY (category_id) REFERENCES contract_categories (id) ON DELETE SET NULL,
                CONSTRAINT fk_contracts_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL,
                CONSTRAINT fk_contracts_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL,
                CONSTRAINT fk_contracts_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_contracts_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL,
                CONSTRAINT fk_contracts_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL,
                CONSTRAINT fk_contracts_currency FOREIGN KEY (currency_id) REFERENCES currencies (id),
                CONSTRAINT fk_contracts_parent FOREIGN KEY (parent_contract_id) REFERENCES contracts (id) ON DELETE SET NULL,
                CONSTRAINT fk_contracts_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS contracts');
    }
};
