<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE proformas ADD CONSTRAINT fk_proformas_converted_invoice
             FOREIGN KEY (converted_invoice_id) REFERENCES invoices (id) ON DELETE SET NULL'
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE proformas DROP FOREIGN KEY fk_proformas_converted_invoice');
    }
};
