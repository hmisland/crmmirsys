<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Generic integration framework (docs/integrations-architecture.md):
 *
 *  integration_connections — one row per configured external connection
 *    (e.g. one WooCommerce store). An organization may hold several per
 *    provider. Non-secret settings live in config_json; credentials are
 *    one SecretBox-encrypted JSON blob (never returned to the UI, only a
 *    short hint). webhook_key is the random, non-guessable part of the
 *    connection's public webhook URL (/webhooks/integrations/{key}) —
 *    it identifies the connection but does not authenticate a request:
 *    each provider verifies its own signature (webhook_secret_encrypted).
 *    sync_state_json: provider-owned incremental cursors.
 *
 *  integration_jobs — the lightweight MySQL work queue (no Redis/workers):
 *    webhook deliveries and sync batches are queued here and processed by
 *    the cron scheduler. dedupe_key (unique) makes enqueueing idempotent:
 *    a replayed webhook or a second "sync now" click is a no-op.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE integration_connections (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                provider VARCHAR(40) NOT NULL,
                name VARCHAR(120) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                status VARCHAR(20) NOT NULL DEFAULT 'configured',
                config_json TEXT NULL,
                credentials_encrypted TEXT NULL,
                credentials_hint VARCHAR(20) NULL,
                webhook_key CHAR(40) NOT NULL,
                webhook_secret_encrypted TEXT NULL,
                sync_state_json TEXT NULL,
                last_attempted_sync_at DATETIME NULL,
                last_successful_sync_at DATETIME NULL,
                last_webhook_at DATETIME NULL,
                last_error VARCHAR(255) NULL,
                last_error_at DATETIME NULL,
                created_by_user_id INT UNSIGNED NULL,
                updated_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_integration_webhook_key (webhook_key),
                KEY idx_integration_org_provider (organization_id, provider),
                KEY idx_integration_due (enabled, provider, last_attempted_sync_at),
                CONSTRAINT fk_integration_connections_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_integration_connections_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_integration_connections_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE integration_jobs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                connection_id INT UNSIGNED NOT NULL,
                job_type VARCHAR(20) NOT NULL,
                resource VARCHAR(40) NOT NULL,
                external_id VARCHAR(64) NULL,
                payload MEDIUMTEXT NULL,
                dedupe_key VARCHAR(191) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
                available_at DATETIME NOT NULL,
                locked_at DATETIME NULL,
                locked_by CHAR(32) NULL,
                last_error VARCHAR(255) NULL,
                finished_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_integration_jobs_dedupe (dedupe_key),
                KEY idx_integration_jobs_claim (status, available_at),
                KEY idx_integration_jobs_connection (connection_id, status),
                KEY idx_integration_jobs_lock (locked_by),
                KEY idx_integration_jobs_org (organization_id, status),
                CONSTRAINT fk_integration_jobs_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_integration_jobs_connection FOREIGN KEY (connection_id) REFERENCES integration_connections (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS integration_jobs');
        $pdo->exec('DROP TABLE IF EXISTS integration_connections');
    }
};
