<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Bearer access tokens for non-browser clients (Android, future iOS, scripts).
 *
 * Why a new table instead of reusing auth_tokens (0002): auth_tokens.type is
 * ENUM('email_verification','password_reset') and its lifecycle is "issue once,
 * mark used_at, expire". An access token is used many times, is bound to a
 * device label, can be revoked individually and records last use — none of that
 * fits without changing the meaning of an existing, security-sensitive table.
 *
 * Only the SHA-256 hash of the token is stored (the raw token is shown to the
 * client once, at issuance). All timestamps are written by PHP in UTC
 * (storage convention since 0083) and deliberately have no
 * DEFAULT CURRENT_TIMESTAMP, so no column here depends on the MySQL session zone.
 * Additive; rollback drops the table (every issued token stops working).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE api_access_tokens (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                device_name VARCHAR(100) NOT NULL,
                user_agent VARCHAR(255) NULL,
                created_ip VARCHAR(45) NULL,
                last_used_ip VARCHAR(45) NULL,
                created_at DATETIME NOT NULL,
                last_used_at DATETIME NULL,
                expires_at DATETIME NOT NULL,
                revoked_at DATETIME NULL,
                UNIQUE KEY uniq_api_access_tokens_hash (token_hash),
                KEY idx_api_access_tokens_user (user_id, revoked_at, expires_at),
                CONSTRAINT fk_api_access_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS api_access_tokens');
    }
};
