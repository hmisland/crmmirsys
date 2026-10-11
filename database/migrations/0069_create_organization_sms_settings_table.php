<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Per-organization SMS choice:
 *  - mode 'platform' (default, also when no row exists): sent with the
 *    platform's Kavenegar account, each SMS paid from the organization's
 *    prepaid SMS/notification credit (charged from Wallet);
 *  - mode 'own': sent with the organization's own Kavenegar API key —
 *    nothing is charged here, they top up their Kavenegar account
 *    themselves. The key is stored encrypted (SecretBox).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE organization_sms_settings (
                organization_id INT UNSIGNED NOT NULL PRIMARY KEY,
                mode VARCHAR(20) NOT NULL DEFAULT 'platform',
                api_key_encrypted TEXT NULL,
                sender VARCHAR(30) NULL,
                updated_by_user_id INT UNSIGNED NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_organization_sms_settings_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS organization_sms_settings');
    }
};
