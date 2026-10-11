<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Import history so the latest CSV import can be rolled back. Each
 * batch row lists what the import touched (contact_import_batch_items):
 * contacts/companies/categories/custom fields it created, plus a JSON
 * snapshot of every existing contact it updated, taken before the
 * update. `stamp` holds the contact's updated_at right after the
 * import wrote it, so a later manual edit can be detected and that
 * contact left alone on rollback.
 *
 * Also clears the literal text "NULL" that earlier imports stored in
 * contact fields (database exports write empty cells as NULL).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE contact_import_batches (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NULL,
                file_name VARCHAR(255) NULL,
                created_count INT UNSIGNED NOT NULL DEFAULT 0,
                updated_count INT UNSIGNED NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                rolled_back_at DATETIME NULL,
                rolled_back_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_contact_import_batches_org (organization_id, id),
                CONSTRAINT fk_contact_import_batches_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE contact_import_batch_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                batch_id INT UNSIGNED NOT NULL,
                entity_type VARCHAR(30) NOT NULL,
                entity_id INT UNSIGNED NOT NULL,
                action VARCHAR(20) NOT NULL,
                snapshot MEDIUMTEXT NULL,
                stamp DATETIME NULL,
                KEY idx_contact_import_batch_items_batch (batch_id),
                CONSTRAINT fk_contact_import_batch_items_batch FOREIGN KEY (batch_id) REFERENCES contact_import_batches (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        foreach (['last_name', 'email', 'phone', 'position', 'address', 'country', 'city', 'province', 'postal_code', 'source'] as $column) {
            $pdo->exec("UPDATE contacts SET `{$column}` = NULL WHERE `{$column}` = 'NULL'");
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS contact_import_batch_items');
        $pdo->exec('DROP TABLE IF EXISTS contact_import_batches');
    }
};
