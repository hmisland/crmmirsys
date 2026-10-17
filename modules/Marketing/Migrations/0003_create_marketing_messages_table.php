<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * One row per campaign recipient — the send history AND the queue.
 *
 * status (our side): pending → processing → sent (accepted by the
 * provider) | failed | skipped | cancelled.
 * delivery_status (provider side, only from delivery reports/webhooks):
 * queued, sent, delivered, undelivered, failed, blocked, cancelled,
 * bounced, soft_bounced, complaint, invalid, deferred, unsubscribed.
 * "sent" never means delivered.
 *
 * Duplicate prevention: UNIQUE (campaign_id, channel, address) — one
 * row per address per campaign; a row is claimed with a lock token
 * before sending; SMS passes a per-row id as the provider's localid.
 * ambiguous = 1: the request may have reached the provider (timeout),
 * so it is never retried automatically.
 * The message body is not copied here; it is rendered from the campaign.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE marketing_messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                campaign_id INT UNSIGNED NOT NULL,
                contact_id INT UNSIGNED NULL,
                channel VARCHAR(10) NOT NULL,
                address VARCHAR(255) NOT NULL,
                recipient_name VARCHAR(200) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                delivery_status VARCHAR(20) NULL,
                provider VARCHAR(40) NULL,
                provider_message_id VARCHAR(255) NULL,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                ambiguous TINYINT(1) NOT NULL DEFAULT 0,
                next_attempt_at DATETIME NULL,
                locked_until DATETIME NULL,
                lock_token VARCHAR(32) NULL,
                error VARCHAR(500) NULL,
                skip_reason VARCHAR(40) NULL,
                sent_at DATETIME NULL,
                delivered_at DATETIME NULL,
                status_checked_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_marketing_messages_recipient (campaign_id, channel, address),
                KEY idx_marketing_messages_queue (status, next_attempt_at),
                KEY idx_marketing_messages_campaign_status (campaign_id, status),
                KEY idx_marketing_messages_provider_id (provider_message_id(64)),
                KEY idx_marketing_messages_contact (contact_id),
                KEY idx_marketing_messages_org_created (organization_id, created_at),
                KEY idx_marketing_messages_lock (lock_token),
                CONSTRAINT fk_marketing_messages_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_marketing_messages_campaign FOREIGN KEY (campaign_id) REFERENCES marketing_campaigns (id) ON DELETE CASCADE,
                CONSTRAINT fk_marketing_messages_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS marketing_messages');
    }
};
