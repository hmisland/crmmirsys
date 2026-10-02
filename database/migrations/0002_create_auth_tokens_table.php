<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * One table for both email-verification and password-reset tokens
 * (distinguished by `type`) rather than two near-identical tables —
 * they have the exact same shape and lifecycle (issue, use once,
 * expire). Only the hash is stored, never the raw token (per Phase 0
 * §32: never log/store secrets in reusable form) — see
 * AuthTokenRepository for how it's generated/compared.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE auth_tokens (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                type ENUM('email_verification', 'password_reset') NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_auth_tokens_hash (token_hash),
                KEY idx_auth_tokens_user_type (user_id, type),
                CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS auth_tokens');
    }
};
