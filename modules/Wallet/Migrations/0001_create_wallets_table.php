<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Per Phase 0 Rev. 3 D2: one wallet per (user, currency) — UNIQUE
 * enforces it at the schema level, not just in application code.
 * `balance` is DECIMAL(38,18) per D3 (crypto-ready precision, even
 * though `asset_type` is FIAT-only for now — the column exists per D9
 * so a future crypto wallet doesn't need a schema migration).
 * `balance` is a cached, always-correct-by-construction total: every
 * write to it happens inside the SAME transaction as the ledger row
 * that justifies it (see WalletService) — it is never updated on its
 * own, and can always be reconstructed by summing wallet_ledger.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE wallets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                currency_id INT UNSIGNED NOT NULL,
                asset_type ENUM('fiat', 'crypto') NOT NULL DEFAULT 'fiat',
                balance DECIMAL(38,18) NOT NULL DEFAULT 0,
                status ENUM('active', 'frozen') NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_wallets_user_currency (user_id, currency_id),
                KEY idx_wallets_organization (organization_id),
                CONSTRAINT fk_wallets_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_wallets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_wallets_currency FOREIGN KEY (currency_id) REFERENCES currencies (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS wallets');
    }
};
