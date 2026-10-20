<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Phase B — provider delivery reports for transactional messages (the
 * same fields marketing_messages has): delivery_status is set only from
 * a provider report (SMS status polling, Brevo webhook) — "sent" in
 * status still means "accepted by the provider", never "delivered".
 *
 * Additive columns + indexes; rollback drops them.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE marketing_transactional_messages
                ADD COLUMN delivery_status VARCHAR(20) NULL AFTER provider_message_id,
                ADD COLUMN delivered_at DATETIME NULL AFTER sent_at,
                ADD COLUMN status_checked_at DATETIME NULL AFTER delivered_at,
                ADD INDEX idx_marketing_transactional_report (channel, status, sent_at),
                ADD INDEX idx_marketing_transactional_provider_id (provider_message_id(64))'
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE marketing_transactional_messages
                DROP INDEX idx_marketing_transactional_provider_id, DROP INDEX idx_marketing_transactional_report,
                DROP COLUMN status_checked_at, DROP COLUMN delivered_at, DROP COLUMN delivery_status'
        );
    }
};
