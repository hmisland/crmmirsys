<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Real gap identified during Phase 12's architecture review: no
 * exchange-rate system existed anywhere in this codebase (Wallet
 * deliberately stayed single-currency). This is Core, not
 * Finance-owned, so Wallet inherits it too rather than Finance
 * duplicating it.
 *
 * Per an explicit instruction: never hardcode a rate into logic — a
 * Super Admin management page (this phase) is how a rate is set or
 * changed. The seeded row below is a placeholder starting value, not
 * a hardcoded rate the app relies on; update it to the real current
 * rate immediately after deploying. A future API-connected rate
 * source is additive (a new `rate_source` value), not a redesign.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE exchange_rates (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source_currency_id INT UNSIGNED NOT NULL,
                destination_currency_id INT UNSIGNED NOT NULL,
                rate DECIMAL(38,18) NOT NULL,
                rate_source VARCHAR(50) NOT NULL DEFAULT 'manual',
                effective_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_by_user_id INT UNSIGNED NULL,
                KEY idx_exchange_rates_pair (source_currency_id, destination_currency_id, effective_at),
                CONSTRAINT fk_exchange_rates_source FOREIGN KEY (source_currency_id) REFERENCES currencies (id) ON DELETE CASCADE,
                CONSTRAINT fk_exchange_rates_destination FOREIGN KEY (destination_currency_id) REFERENCES currencies (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $usdId = $pdo->query("SELECT id FROM currencies WHERE code = 'USD'")->fetchColumn();
        $irrId = $pdo->query("SELECT id FROM currencies WHERE code = 'IRR'")->fetchColumn();

        if ($usdId !== false && $irrId !== false) {
            // Placeholder starting value only — a Super Admin must
            // update this to the real current rate right after
            // deploying. Never read as a hardcoded fact by any
            // Service; it's just the first row in an editable table.
            $stmt = $pdo->prepare(
                'INSERT INTO exchange_rates (source_currency_id, destination_currency_id, rate, rate_source) VALUES (:src, :dst, :rate, :source)'
            );
            $stmt->execute(['src' => $usdId, 'dst' => $irrId, 'rate' => '580000', 'source' => 'manual']);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS exchange_rates');
    }
};
