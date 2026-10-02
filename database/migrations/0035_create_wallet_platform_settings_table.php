<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Single-row table (id always 1) — the platform-wide cap Super Admin
 * sets; every organization's own min/max (Wallet module's
 * wallet_org_settings) is validated against this and can never exceed
 * it. Deliberately its own tiny table rather than a generic key-value
 * settings table, matching how other single-row/singleton config in
 * this codebase is modeled (e.g. how `config/*.php` files work, just
 * DB-backed here because Super Admin edits it at runtime).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE wallet_platform_settings (
                id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
                currency_id INT UNSIGNED NOT NULL,
                min_topup DECIMAL(38,18) NOT NULL DEFAULT 10000,
                max_topup DECIMAL(38,18) NOT NULL DEFAULT 500000000,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_wallet_platform_settings_currency FOREIGN KEY (currency_id) REFERENCES currencies (id),
                CONSTRAINT chk_wallet_platform_settings_single_row CHECK (id = 1)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $irrId = $pdo->query("SELECT id FROM currencies WHERE code = 'IRR'")->fetchColumn();

        $stmt = $pdo->prepare(
            'INSERT INTO wallet_platform_settings (id, currency_id, min_topup, max_topup) VALUES (1, :currency_id, 10000, 500000000)'
        );
        $stmt->execute(['currency_id' => (int) $irrId]);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS wallet_platform_settings');
    }
};
