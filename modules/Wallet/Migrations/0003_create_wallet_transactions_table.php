<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Tracks a top-up's lifecycle through the payment gateway, separately
 * from wallet_ledger (which only ever gets a row once the top-up is
 * CONFIRMED). `provider_reference` (ZarinPal's "authority") is
 * UNIQUE — the idempotency guard: a gateway callback hit twice (a
 * user double-clicking, a retried webhook) can only ever credit the
 * wallet once, enforced by the database, not just application logic.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE wallet_transactions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                wallet_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                amount DECIMAL(38,18) NOT NULL,
                gateway_key VARCHAR(50) NOT NULL,
                provider_reference VARCHAR(255) NULL,
                status ENUM('pending', 'completed', 'failed') NOT NULL DEFAULT 'pending',
                gateway_response TEXT NULL,
                ref_id VARCHAR(100) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME NULL,
                UNIQUE KEY uniq_wallet_transactions_provider_ref (gateway_key, provider_reference),
                KEY idx_wallet_transactions_wallet (wallet_id),
                KEY idx_wallet_transactions_organization (organization_id),
                CONSTRAINT fk_wallet_transactions_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_wallet_transactions_wallet FOREIGN KEY (wallet_id) REFERENCES wallets (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS wallet_transactions');
    }
};
