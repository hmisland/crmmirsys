<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * One client record per contact — the contact stays the central CRM
 * entity (name, phones, email, gender, date of birth, source all live
 * there and are never copied here). This row only adds the history
 * layer's own state: status, the assigned specialist, and the intake
 * text that isn't contact data.
 *
 * sensitivities / restrictions / observations are the "sensitive"
 * fields gated by client_records.view_sensitive. Industry-specific
 * intake (skin type, treatment area, ...) is NOT a column: it is a
 * custom field with entity_type = 'client_record'.
 *
 * owner_id / team_id / department_id exist for AuthorizationEngine's
 * OWN / TEAM / DEPARTMENT scopes, same convention as follow_ups.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE client_records (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                contact_id INT UNSIGNED NOT NULL,
                record_number VARCHAR(30) NULL,
                status ENUM('active', 'inactive', 'archived') NOT NULL DEFAULT 'active',
                assigned_user_id INT UNSIGNED NULL,
                owner_id INT UNSIGNED NOT NULL,
                team_id INT UNSIGNED NULL,
                department_id INT UNSIGNED NULL,
                important_notes TEXT NULL,
                preferences TEXT NULL,
                sensitivities TEXT NULL,
                restrictions TEXT NULL,
                observations TEXT NULL,
                archived_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_client_records_contact (organization_id, contact_id),
                UNIQUE KEY uniq_client_records_number (organization_id, record_number),
                KEY idx_client_records_status (organization_id, status),
                KEY idx_client_records_assigned (assigned_user_id),
                KEY idx_client_records_owner (owner_id),
                CONSTRAINT fk_client_records_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_client_records_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE,
                CONSTRAINT fk_client_records_assigned FOREIGN KEY (assigned_user_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_client_records_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_client_records_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL,
                CONSTRAINT fk_client_records_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS client_records');
    }
};
