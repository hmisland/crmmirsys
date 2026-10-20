<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Invoice type for the Iranian tax system (inty): 1 with buyer information
 * (the buyer's national ID / economic code is required), 2 without, 3 store
 * sale. NULL = not set (invoices not meant for the tax system keep working
 * unchanged).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE invoices ADD COLUMN tax_invoice_type TINYINT UNSIGNED NULL AFTER custom_number');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE invoices DROP COLUMN tax_invoice_type');
    }
};
