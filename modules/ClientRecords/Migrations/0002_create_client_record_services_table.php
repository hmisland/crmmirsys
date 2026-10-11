<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Service history: one row per service/treatment course performed for
 * a client (e.g. "Laser hair removal — 6 sessions"). Sessions hang off
 * it. product_id points at the existing Finance catalogue (products are
 * both goods and services there) and invoice_id at an existing invoice
 * — neither has an FK constraint because both tables belong to the
 * optional Finance module, which may not be installed when this
 * migration runs. ClientTreatmentService validates both through
 * Finance's own services at write time instead. `title` is the label
 * shown everywhere (copied from the product when one is picked, typed
 * freely when Finance isn't available).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE client_record_services (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                client_record_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NULL,
                title VARCHAR(255) NOT NULL,
                provider_user_id INT UNSIGNED NULL,
                location VARCHAR(150) NULL,
                status ENUM('planned', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'planned',
                planned_sessions SMALLINT UNSIGNED NULL,
                started_on DATE NULL,
                completed_on DATE NULL,
                invoice_id INT UNSIGNED NULL,
                notes TEXT NULL,
                created_by_user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_crs_record (client_record_id),
                KEY idx_crs_org_status (organization_id, status),
                KEY idx_crs_product (organization_id, product_id),
                KEY idx_crs_invoice (invoice_id),
                CONSTRAINT fk_crs_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_crs_record FOREIGN KEY (client_record_id) REFERENCES client_records (id) ON DELETE CASCADE,
                CONSTRAINT fk_crs_provider FOREIGN KEY (provider_user_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_crs_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS client_record_services');
    }
};
