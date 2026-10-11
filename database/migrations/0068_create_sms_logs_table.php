<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Log of every SMS attempt made through SmsService (Kavenegar), from any
 * module. Template tokens (one-time codes) are never stored; free-text
 * messages are, for support/audit.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE sms_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NULL,
                user_id INT UNSIGNED NULL,
                provider VARCHAR(30) NOT NULL,
                receptor VARCHAR(30) NOT NULL,
                kind VARCHAR(20) NOT NULL DEFAULT 'text',
                template VARCHAR(100) NULL,
                message TEXT NULL,
                context VARCHAR(100) NULL,
                status VARCHAR(20) NOT NULL,
                provider_message_id VARCHAR(50) NULL,
                cost INT UNSIGNED NOT NULL DEFAULT 0,
                error VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sms_logs_org (organization_id, id),
                KEY idx_sms_logs_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS sms_logs');
    }
};
