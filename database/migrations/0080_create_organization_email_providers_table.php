<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * An organization's own email provider accounts (Sender, Brevo), edited
 * at Organization settings → Messaging providers. One row per provider.
 *
 * - secret_encrypted: the API token/key, SecretBox (AES-256-GCM), never
 *   shown again after saving; secret_hint keeps the last 4 characters.
 * - status: connected | error | disconnected; last_tested_at /
 *   last_success_at / last_error record the latest connection test.
 * - webhook_key: random public id used in our webhook URL;
 *   webhook_secret_encrypted: bearer token the provider must send back.
 *
 * Additive only: no existing table changes. Rollback drops the table
 * (stored credentials are lost; nothing else depends on it).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE organization_email_providers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                provider VARCHAR(30) NOT NULL,
                secret_encrypted TEXT NULL,
                secret_hint VARCHAR(12) NULL,
                sender_email VARCHAR(255) NULL,
                sender_name VARCHAR(150) NULL,
                reply_to VARCHAR(255) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'disconnected',
                sender_verified TINYINT(1) NULL,
                last_tested_at DATETIME NULL,
                last_success_at DATETIME NULL,
                last_error VARCHAR(500) NULL,
                webhook_key VARCHAR(64) NULL,
                webhook_secret_encrypted TEXT NULL,
                webhook_registered_at DATETIME NULL,
                updated_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_org_email_provider (organization_id, provider),
                UNIQUE KEY uniq_org_email_webhook_key (webhook_key),
                CONSTRAINT fk_org_email_providers_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_org_email_providers_user FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS organization_email_providers');
    }
};
