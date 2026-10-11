<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Optional number typed by the user on a proforma/invoice. When set it is
 * the number shown on the document, in lists and in print/PDF; when empty
 * the system number is used as before (invoice_number series / PF-id).
 * The system numbering itself is unchanged.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE proformas ADD COLUMN custom_number VARCHAR(50) NULL AFTER contact_id');
        $pdo->exec('ALTER TABLE invoices ADD COLUMN custom_number VARCHAR(50) NULL AFTER invoice_number');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE proformas DROP COLUMN custom_number');
        $pdo->exec('ALTER TABLE invoices DROP COLUMN custom_number');
    }
};
