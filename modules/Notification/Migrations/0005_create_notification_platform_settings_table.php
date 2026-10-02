<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Single row (id always 1) — Super Admin sets the cost per
 * notification for each channel; org admins' "charge the module"
 * top-ups are denominated in these same units, deducted per send at
 * whatever this currently says (not locked in at top-up time).
 * bale_cost defaults to 0 (Bale's own Bot API is free to call); the
 * column still exists in case a future pricing model wants to charge
 * for it anyway. sms_cost has a placeholder non-zero default since a
 * real SMS provider will cost per message once one is connected.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE notification_platform_settings (
                id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
                bale_cost DECIMAL(38,18) NOT NULL DEFAULT 0,
                sms_cost DECIMAL(38,18) NOT NULL DEFAULT 500,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT chk_notification_platform_settings_single_row CHECK (id = 1)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec('INSERT INTO notification_platform_settings (id, bale_cost, sms_cost) VALUES (1, 0, 500)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS notification_platform_settings');
    }
};
