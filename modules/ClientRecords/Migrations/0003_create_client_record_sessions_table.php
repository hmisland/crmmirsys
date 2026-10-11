<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * One visit / session. Usually part of a service course
 * (service_record_id), but a standalone visit such as a consultation
 * is allowed (service_record_id NULL, `title` labels it).
 *
 * status 'scheduled' is how a planned visit is represented — there is
 * no Appointment module in this product yet. When one exists, an
 * appointment_id column belongs here (see docs/client-records-module.md).
 *
 * follow_up_id / invoice_id reference tables owned by the optional
 * Followups / Finance modules: indexed, no FK, validated in the
 * service layer (same reasoning as client_record_services.product_id).
 * `observations` is a sensitive field (client_records.view_sensitive).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE client_record_sessions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                client_record_id INT UNSIGNED NOT NULL,
                service_record_id INT UNSIGNED NULL,
                title VARCHAR(255) NULL,
                session_number SMALLINT UNSIGNED NULL,
                session_at DATETIME NOT NULL,
                status ENUM('scheduled', 'completed', 'cancelled', 'no_show') NOT NULL DEFAULT 'completed',
                provider_user_id INT UNSIGNED NULL,
                notes TEXT NULL,
                observations TEXT NULL,
                client_feedback TEXT NULL,
                next_recommended_on DATE NULL,
                follow_up_id INT UNSIGNED NULL,
                invoice_id INT UNSIGNED NULL,
                created_by_user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_crse_record (client_record_id, session_at),
                KEY idx_crse_service (service_record_id),
                KEY idx_crse_org_status_date (organization_id, status, session_at),
                KEY idx_crse_provider (provider_user_id),
                KEY idx_crse_follow_up (follow_up_id),
                KEY idx_crse_invoice (invoice_id),
                CONSTRAINT fk_crse_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_crse_record FOREIGN KEY (client_record_id) REFERENCES client_records (id) ON DELETE CASCADE,
                CONSTRAINT fk_crse_service FOREIGN KEY (service_record_id) REFERENCES client_record_services (id) ON DELETE SET NULL,
                CONSTRAINT fk_crse_provider FOREIGN KEY (provider_user_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_crse_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS client_record_sessions');
    }
};
