<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Deliberately minimal — per Phase 0 Rev. 3 decisions (D2-D4), monetary
 * values reference a central currencies table via currency_id rather
 * than a free-text code, and this is what Wallet (the module that
 * actually drove those decisions) needs to honor that. This is NOT
 * the full future Currency Management phase: no exchange rates, no
 * per-organization currency settings, no admin UI yet — just a
 * reference table with a handful of seeded rows. Sales' `deals.currency_code`
 * (a plain VARCHAR placeholder, see its own migration) is the
 * pattern this table lets us stop repeating going forward.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE currencies (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(3) NOT NULL UNIQUE,
                name VARCHAR(100) NOT NULL,
                symbol VARCHAR(10) NOT NULL,
                decimal_precision TINYINT UNSIGNED NOT NULL DEFAULT 2,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $stmt = $pdo->prepare(
            'INSERT INTO currencies (code, name, symbol, decimal_precision) VALUES (:code, :name, :symbol, :precision)'
        );

        // IRR (Iranian Rial) has 0 decimal precision in everyday use —
        // matters for ZarinPal specifically, which expects whole Rials.
        foreach ([
            ['IRR', 'Iranian Rial', 'ریال', 0],
            ['USD', 'US Dollar', '$', 2],
            ['EUR', 'Euro', '€', 2],
        ] as [$code, $name, $symbol, $precision]) {
            $stmt->execute(['code' => $code, 'name' => $name, 'symbol' => $symbol, 'precision' => $precision]);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS currencies');
    }
};
