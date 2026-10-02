<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE wallet_withdrawal_requests (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                wallet_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                amount DECIMAL(38,18) NOT NULL,
                destination_info TEXT NOT NULL,
                status ENUM('pending', 'approved', 'rejected', 'paid', 'cancelled') NOT NULL DEFAULT 'pending',
                admin_note VARCHAR(500) NULL,
                requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                processed_at DATETIME NULL,
                processed_by_user_id INT UNSIGNED NULL,
                KEY idx_wallet_withdrawals_wallet (wallet_id),
                KEY idx_wallet_withdrawals_organization (organization_id, status),
                CONSTRAINT fk_wallet_withdrawals_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_wallet_withdrawals_wallet FOREIGN KEY (wallet_id) REFERENCES wallets (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS wallet_withdrawal_requests');
    }
};
