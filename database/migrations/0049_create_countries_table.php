<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Per Phase 12's architecture: jurisdiction is never derived from UI
 * language — this is the explicit input to Finance's tax logic
 * instead. Seeded with Iran only for now (the first jurisdiction with
 * real tax rules, per an explicit decision); more countries are just
 * more rows, never a code change.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE countries (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                iso_code VARCHAR(3) NOT NULL UNIQUE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec("INSERT INTO countries (name, iso_code) VALUES ('Iran', 'IRN')");
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS countries');
    }
};
