<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Proforma/invoice rows get the product code and name as they were when
 * the document was written (a snapshot — the product may be renamed or
 * re-coded later, an issued document must not change). `description`
 * stays the free "شرح" text. Older rows fall back to the linked
 * product's sku/name when printed.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        foreach (['proforma_items', 'invoice_items'] as $table) {
            $pdo->exec(
                "ALTER TABLE {$table}
                    ADD COLUMN item_code VARCHAR(50) NULL AFTER product_id,
                    ADD COLUMN item_name VARCHAR(255) NULL AFTER item_code"
            );
        }
    }

    public function down(\PDO $pdo): void
    {
        foreach (['proforma_items', 'invoice_items'] as $table) {
            $pdo->exec("ALTER TABLE {$table} DROP COLUMN item_name, DROP COLUMN item_code");
        }
    }
};
