<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Gift credit:
 *  - wallet_gift_codes: codes the Super Admin generates (amount, optional
 *    organization lock, optional expiry). An org admin redeems one into
 *    their wallet; each code works once.
 *  - two new ledger types: gift_credit (credit received as a gift — from
 *    a code or from an org admin) and gift_sent (an org admin giving part
 *    of their balance to a colleague as gift credit).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE wallet_gift_codes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(32) NOT NULL,
                amount DECIMAL(38,18) NOT NULL,
                currency_id INT UNSIGNED NOT NULL,
                organization_id INT UNSIGNED NULL,
                note VARCHAR(255) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                expires_at DATE NULL,
                created_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                redeemed_at DATETIME NULL,
                redeemed_by_user_id INT UNSIGNED NULL,
                redeemed_organization_id INT UNSIGNED NULL,
                redeemed_wallet_id INT UNSIGNED NULL,
                UNIQUE KEY uniq_wallet_gift_codes_code (code),
                KEY idx_wallet_gift_codes_status (status, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "ALTER TABLE wallet_ledger MODIFY type ENUM('topup', 'withdrawal', 'transfer_in', 'transfer_out', 'transfer_fee',
                'adjustment_credit', 'adjustment_debit', 'reversal', 'gift_credit', 'gift_sent') NOT NULL"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS wallet_gift_codes');
        $pdo->exec(
            "ALTER TABLE wallet_ledger MODIFY type ENUM('topup', 'withdrawal', 'transfer_in', 'transfer_out', 'transfer_fee',
                'adjustment_credit', 'adjustment_debit', 'reversal') NOT NULL"
        );
    }
};
