<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * One-time sign-in codes of the Customer Portal — separate from the staff
 * auth_tokens (which belong to users): a code belongs to a CONTACT of one
 * organization.
 *
 * The code itself is never stored: code_hash is password_hash() of it.
 * The destination is kept only as a masked hint (shown back to the
 * customer) and a SHA-256 hash (to tell a resend to the same address).
 * A code is single-use (used_at), expires (expires_at), allows a few
 * attempts (attempts / max_attempts) and is superseded by a newer code for
 * the same contact (invalidated_at). ip_hash only supports abuse review.
 *
 * New table only; rollback drops it.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE customer_portal_otps (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                contact_id INT UNSIGNED NOT NULL,
                channel VARCHAR(10) NOT NULL,
                destination_hint VARCHAR(120) NOT NULL,
                destination_hash CHAR(64) NOT NULL,
                code_hash VARCHAR(255) NOT NULL,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                invalidated_at DATETIME NULL,
                delivered TINYINT(1) NOT NULL DEFAULT 0,
                ip_hash CHAR(64) NULL,
                created_at DATETIME NOT NULL,
                KEY idx_customer_portal_otps_contact (organization_id, contact_id, created_at),
                KEY idx_customer_portal_otps_expiry (expires_at),
                CONSTRAINT fk_customer_portal_otps_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_customer_portal_otps_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS customer_portal_otps');
    }
};
