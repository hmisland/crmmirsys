<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Tax product identity on products (all optional; existing products and the
 * existing product form keep working unchanged):
 *  - tax_product_code: the 13-digit national product/service ID (sstid);
 *  - tax_product_source: 'catalog' when it was taken from the national list
 *    (kala.db) — then it can no longer be edited — or 'manual' when typed in;
 *    NULL when the product has no code;
 *  - vat_exempt: 1 exempt (معاف), 0 taxable (مشمول), NULL not set;
 *  - vat_rate: VAT percent, e.g. 10.00 (from the list, editable).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE products
                ADD COLUMN tax_product_code VARCHAR(13) NULL AFTER sku,
                ADD COLUMN tax_product_source VARCHAR(10) NULL AFTER tax_product_code,
                ADD COLUMN vat_exempt TINYINT(1) NULL AFTER tax_profile_id,
                ADD COLUMN vat_rate DECIMAL(5,2) NULL AFTER vat_exempt,
                ADD KEY idx_products_tax_source (organization_id, tax_product_source),
                ADD KEY idx_products_tax_code (organization_id, tax_product_code)'
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE products DROP KEY idx_products_tax_code, DROP KEY idx_products_tax_source, DROP COLUMN vat_rate, DROP COLUMN vat_exempt, DROP COLUMN tax_product_source, DROP COLUMN tax_product_code');
    }
};
