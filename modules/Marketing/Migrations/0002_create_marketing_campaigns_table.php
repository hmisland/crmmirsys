<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * One campaign = one channel, one message, one audience.
 *
 * status: draft → queued (approved, recipients snapshotted) → sending
 * (the worker picked it up) → completed | cancelled.
 * provider: email provider key (sender | brevo); NULL for SMS, which
 * always goes through the organization's SMS account (SmsService).
 * message_class: marketing | transactional — marketing honours every
 * opt-out; transactional ignores "unsubscribed" but never bounces.
 * audience_type: contacts | group | tag | all; audience_ids: JSON ids.
 * last_error: why the worker could not process it (module or provider
 * unavailable) — per-recipient errors live on marketing_messages.
 * Counters are computed from marketing_messages, never stored twice.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE marketing_campaigns (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                name VARCHAR(150) NOT NULL,
                channel VARCHAR(10) NOT NULL,
                provider VARCHAR(30) NULL,
                message_class VARCHAR(20) NOT NULL DEFAULT 'marketing',
                subject VARCHAR(255) NULL,
                body_text TEXT NULL,
                body_html MEDIUMTEXT NULL,
                audience_type VARCHAR(20) NOT NULL,
                audience_ids TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                total_recipients INT UNSIGNED NOT NULL DEFAULT 0,
                last_error VARCHAR(255) NULL,
                owner_id INT UNSIGNED NULL,
                queued_by_user_id INT UNSIGNED NULL,
                queued_at DATETIME NULL,
                started_at DATETIME NULL,
                finished_at DATETIME NULL,
                cancelled_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_marketing_campaigns_org_status (organization_id, status),
                KEY idx_marketing_campaigns_status (status),
                CONSTRAINT fk_marketing_campaigns_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_marketing_campaigns_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_marketing_campaigns_queued_by FOREIGN KEY (queued_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS marketing_campaigns');
    }
};
