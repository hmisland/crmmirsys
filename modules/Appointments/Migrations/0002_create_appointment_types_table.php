<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * What is being booked (a service, a visit, a consultation...) with its
 * default length. `buffer_minutes` keeps the resource blocked for a
 * while after each appointment of this type (preparation, cleanup).
 *
 * `is_public` is reserved for a future self-service booking page or
 * customer portal (types offered to customers directly). Nothing reads
 * it yet and it has no UI in this version.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE appointment_types (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                name VARCHAR(150) NOT NULL,
                duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
                buffer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                description TEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_public TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_aptype_org_active (organization_id, is_active),
                CONSTRAINT fk_aptype_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS appointment_types');
    }
};
