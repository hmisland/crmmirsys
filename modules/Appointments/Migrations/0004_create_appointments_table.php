<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * One booked time slot for a contact (the customer), optionally on a
 * resource, of a type, with a responsible staff member.
 *
 * Built so self-service booking can be added later without a schema
 * change:
 *  - `source` records who created it: staff (this version), a public
 *    booking page or a customer portal (future);
 *  - status `requested` = waiting for staff confirmation, which is
 *    what a customer-made booking would start as.
 *
 * owner_id / team_id / department_id are captured from the creator for
 * RBAC scope (OWN also matches assigned_user_id), as in other modules.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE appointments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                contact_id INT UNSIGNED NOT NULL,
                appointment_type_id INT UNSIGNED NULL,
                resource_id INT UNSIGNED NULL,
                assigned_user_id INT UNSIGNED NULL,
                title VARCHAR(255) NULL,
                starts_at DATETIME NOT NULL,
                ends_at DATETIME NOT NULL,
                status ENUM('requested', 'scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show') NOT NULL DEFAULT 'scheduled',
                source ENUM('staff', 'public', 'portal') NOT NULL DEFAULT 'staff',
                notes TEXT NULL,
                cancel_reason VARCHAR(255) NULL,
                owner_id INT UNSIGNED NOT NULL,
                team_id INT UNSIGNED NULL,
                department_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_appt_org_start (organization_id, starts_at),
                KEY idx_appt_resource_start (resource_id, starts_at),
                KEY idx_appt_assigned_start (assigned_user_id, starts_at),
                KEY idx_appt_contact (contact_id, starts_at),
                KEY idx_appt_org_status (organization_id, status),
                CONSTRAINT fk_appt_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_appt_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE,
                CONSTRAINT fk_appt_type FOREIGN KEY (appointment_type_id) REFERENCES appointment_types (id) ON DELETE SET NULL,
                CONSTRAINT fk_appt_resource FOREIGN KEY (resource_id) REFERENCES appointment_resources (id) ON DELETE SET NULL,
                CONSTRAINT fk_appt_assigned FOREIGN KEY (assigned_user_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_appt_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS appointments');
    }
};
