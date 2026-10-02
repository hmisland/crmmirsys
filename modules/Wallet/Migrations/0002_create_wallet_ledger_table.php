<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Append-only, never UPDATEd or DELETEd (per Phase 0's financial
 * integrity requirements) — every balance change is a new row here,
 * and `balance_after` is recorded at write time so the wallet's
 * history is independently auditable without re-deriving state.
 * A mistaken entry is corrected with a new REVERSAL row, never by
 * editing or removing the original.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE wallet_ledger (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                wallet_id INT UNSIGNED NOT NULL,
                type ENUM('topup', 'withdrawal', 'transfer_in', 'transfer_out', 'transfer_fee', 'adjustment_credit', 'adjustment_debit', 'reversal') NOT NULL,
                amount DECIMAL(38,18) NOT NULL,
                balance_after DECIMAL(38,18) NOT NULL,
                reference_type VARCHAR(50) NULL,
                reference_id BIGINT UNSIGNED NULL,
                description VARCHAR(255) NULL,
                created_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_wallet_ledger_wallet (wallet_id, id),
                KEY idx_wallet_ledger_organization (organization_id),
                KEY idx_wallet_ledger_reference (reference_type, reference_id),
                CONSTRAINT fk_wallet_ledger_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_wallet_ledger_wallet FOREIGN KEY (wallet_id) REFERENCES wallets (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS wallet_ledger');
    }
};
