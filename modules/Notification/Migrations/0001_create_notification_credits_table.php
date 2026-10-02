<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * A separate credit balance from the organization's Wallet, on
 * purpose — "charging the Notification module" (per the explicit
 * requirement) is a deliberate transfer FROM the org's Wallet INTO
 * this balance, not the Wallet balance itself being spent per
 * notification. Keeps notification usage accounting (cost per
 * message, a running balance that only this module touches)
 * completely separate from general wallet activity.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE notification_credits (
                organization_id INT UNSIGNED PRIMARY KEY,
                balance DECIMAL(38,18) NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_notification_credits_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS notification_credits');
    }
};
