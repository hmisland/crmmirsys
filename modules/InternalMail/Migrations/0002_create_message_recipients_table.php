<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * One row per (message, recipient) — the same table serves an
 * ordinary 1-to-1 message, a message to several colleagues, AND an
 * Org Admin's "broadcast to the whole team" (just a message with
 * every org user as a recipient row). Read status is necessarily
 * per-recipient, not per-message, since a message to 3 people can be
 * read by one and unread by the others.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE message_recipients (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                message_id INT UNSIGNED NOT NULL,
                recipient_user_id INT UNSIGNED NOT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                read_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_message_recipient (message_id, recipient_user_id),
                KEY idx_message_recipients_recipient (recipient_user_id, is_read),
                CONSTRAINT fk_message_recipients_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_message_recipients_message FOREIGN KEY (message_id) REFERENCES messages (id) ON DELETE CASCADE,
                CONSTRAINT fk_message_recipients_user FOREIGN KEY (recipient_user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS message_recipients');
    }
};
