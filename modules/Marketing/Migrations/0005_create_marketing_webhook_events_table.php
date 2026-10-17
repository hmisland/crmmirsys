<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Provider events already processed — UNIQUE (provider, event_key)
 * makes webhook handling idempotent: a replayed event is ignored.
 * Only the event key, type and provider message id are kept.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE marketing_webhook_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                provider VARCHAR(30) NOT NULL,
                event_key CHAR(64) NOT NULL,
                event_type VARCHAR(30) NOT NULL,
                provider_message_id VARCHAR(255) NULL,
                received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_marketing_webhook_event (provider, event_key),
                CONSTRAINT fk_marketing_webhook_events_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS marketing_webhook_events');
    }
};
