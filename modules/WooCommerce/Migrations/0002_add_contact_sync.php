<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Phase A — WooCommerce customer → CRM contact sync.
 *
 * woo_customers gains the billing address of the customer's latest
 * order (needed to fill a contact's address fields, also when the sync
 * is re-run later) and the per-customer sync state:
 *   contact_sync_status: NULL = pending (new or changed since the last
 *     run) | created | matched | updated | skipped | conflict
 *   contact_sync_note: translation key explaining skipped / conflict
 *
 * woo_contact_sync_settings: one row per connection (shop) — whether the
 * sync is on, the contact group (contact_categories) and tags new and
 * matched contacts get, the source label for new contacts, the field
 * mapping, the user new contacts belong to, and the last run summary.
 *
 * Additive only; rollback drops the table and the new columns.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE woo_customers
                ADD COLUMN address_1 VARCHAR(255) NULL AFTER phone,
                ADD COLUMN address_2 VARCHAR(255) NULL AFTER address_1,
                ADD COLUMN city VARCHAR(100) NULL AFTER address_2,
                ADD COLUMN state VARCHAR(100) NULL AFTER city,
                ADD COLUMN postcode VARCHAR(20) NULL AFTER state,
                ADD COLUMN country VARCHAR(10) NULL AFTER postcode,
                ADD COLUMN contact_sync_status VARCHAR(20) NULL AFTER contact_id,
                ADD COLUMN contact_sync_note VARCHAR(100) NULL AFTER contact_sync_status,
                ADD COLUMN contact_synced_at DATETIME NULL AFTER contact_sync_note,
                ADD INDEX idx_woo_customers_contact_sync (connection_id, contact_sync_status)'
        );

        $pdo->exec(
            "CREATE TABLE woo_contact_sync_settings (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                connection_id INT UNSIGNED NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                category_id INT UNSIGNED NULL,
                tag_ids VARCHAR(500) NULL,
                source VARCHAR(100) NULL,
                mapping_json TEXT NULL,
                owner_user_id INT UNSIGNED NULL,
                last_run_at DATETIME NULL,
                last_run_summary VARCHAR(500) NULL,
                last_error VARCHAR(255) NULL,
                updated_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_woo_contact_sync_connection (connection_id),
                KEY idx_woo_contact_sync_org (organization_id, enabled),
                CONSTRAINT fk_woo_contact_sync_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_woo_contact_sync_connection FOREIGN KEY (connection_id) REFERENCES integration_connections (id) ON DELETE CASCADE,
                CONSTRAINT fk_woo_contact_sync_category FOREIGN KEY (category_id) REFERENCES contact_categories (id) ON DELETE SET NULL,
                CONSTRAINT fk_woo_contact_sync_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_woo_contact_sync_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS woo_contact_sync_settings');
        $pdo->exec(
            'ALTER TABLE woo_customers
                DROP INDEX idx_woo_customers_contact_sync,
                DROP COLUMN contact_synced_at, DROP COLUMN contact_sync_note, DROP COLUMN contact_sync_status,
                DROP COLUMN country, DROP COLUMN postcode, DROP COLUMN state, DROP COLUMN city, DROP COLUMN address_2, DROP COLUMN address_1'
        );
    }
};
