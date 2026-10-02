<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * One row per organization, created lazily the first time an org
 * admin (or the system) needs it, always validated in
 * WalletSettingsService against wallet_platform_settings — an org's
 * min/max can never exceed the platform's own cap, only be tighter.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE wallet_org_settings (
                organization_id INT UNSIGNED PRIMARY KEY,
                min_topup DECIMAL(38,18) NOT NULL,
                max_topup DECIMAL(38,18) NOT NULL,
                min_withdrawal DECIMAL(38,18) NOT NULL DEFAULT 10000,
                max_withdrawal DECIMAL(38,18) NOT NULL DEFAULT 500000000,
                transfer_fee_fixed DECIMAL(38,18) NOT NULL DEFAULT 0,
                transfer_fee_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_wallet_org_settings_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS wallet_org_settings');
    }
};
