<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * One Bale (Safir) account per organization: the Safir api-access-key
 * (encrypted with SecretBox, never stored in plain text) + last 4 chars
 * hint, the sending bot's numeric id, and the integration state.
 *
 * status: disconnected | configured (saved, not verified) | verified
 * (Safir accepted a message with it) | rejected (Safir refused the key).
 * paused_until / pause_reason: org-level back-off after a rate limit,
 * "payment required" or "contact limit" answer from Safir.
 *
 * Rollback drops the table: stored keys are lost and must be re-entered.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE organization_bale_settings (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                bot_id BIGINT UNSIGNED NULL,
                api_key_encrypted TEXT NULL,
                api_key_hint VARCHAR(12) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'disconnected',
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                last_success_at DATETIME NULL,
                last_error VARCHAR(255) NULL,
                paused_until DATETIME NULL,
                pause_reason VARCHAR(60) NULL,
                updated_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_org_bale_settings_org (organization_id),
                CONSTRAINT fk_org_bale_settings_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_org_bale_settings_user FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS organization_bale_settings');
    }
};
