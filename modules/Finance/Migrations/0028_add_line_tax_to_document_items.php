<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Per-row VAT on invoice and proforma rows: the rate taken from the row's
 * product (exempt → 0) and the row's tax amount. NULL on documents
 * calculated the old way (one tax for the whole document from the tax
 * profile) — existing documents are not touched.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        foreach (['invoice_items', 'proforma_items'] as $table) {
            $pdo->exec("ALTER TABLE {$table}
                ADD COLUMN tax_rate DECIMAL(5,2) NULL AFTER line_total,
                ADD COLUMN tax_amount DECIMAL(38,18) NULL AFTER tax_rate");
        }
    }

    public function down(\PDO $pdo): void
    {
        foreach (['invoice_items', 'proforma_items'] as $table) {
            $pdo->exec("ALTER TABLE {$table} DROP COLUMN tax_amount, DROP COLUMN tax_rate");
        }
    }
};
