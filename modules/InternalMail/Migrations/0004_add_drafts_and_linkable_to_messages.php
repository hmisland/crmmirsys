<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Closes two gaps against the original architecture spec (§20):
 * "inbox/sent/drafts/trash/threads model" (drafts was missing) and
 * "each message optionally polymorphically linked (linkable_type,
 * linkable_id) ... surfaced in that entity's Timeline" (the columns
 * never existed at all).
 *
 * draft_recipients_json holds the intended to/cc/bcc while a message
 * is still a draft — deliberately NOT real message_recipients rows
 * yet, so a draft can never accidentally appear in someone's inbox
 * before it's actually sent. sendDraft() converts this JSON into
 * real message_recipients rows at send time (see MessageService).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec("ALTER TABLE messages ADD COLUMN status ENUM('draft', 'sent') NOT NULL DEFAULT 'sent' AFTER thread_id");
        $pdo->exec('ALTER TABLE messages ADD COLUMN draft_recipients_json TEXT NULL AFTER body');
        $pdo->exec('ALTER TABLE messages ADD COLUMN linkable_type VARCHAR(50) NULL AFTER draft_recipients_json');
        $pdo->exec('ALTER TABLE messages ADD COLUMN linkable_id INT UNSIGNED NULL AFTER linkable_type');
        $pdo->exec('ALTER TABLE messages ADD COLUMN deleted_by_sender_at DATETIME NULL AFTER linkable_id');
        $pdo->exec('ALTER TABLE messages ADD KEY idx_messages_linkable (linkable_type, linkable_id)');
        $pdo->exec("ALTER TABLE messages ADD KEY idx_messages_sender_status (sender_user_id, status)");
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE messages DROP KEY idx_messages_sender_status');
        $pdo->exec('ALTER TABLE messages DROP KEY idx_messages_linkable');
        $pdo->exec('ALTER TABLE messages DROP COLUMN deleted_by_sender_at');
        $pdo->exec('ALTER TABLE messages DROP COLUMN linkable_id');
        $pdo->exec('ALTER TABLE messages DROP COLUMN linkable_type');
        $pdo->exec('ALTER TABLE messages DROP COLUMN draft_recipients_json');
        $pdo->exec('ALTER TABLE messages DROP COLUMN status');
    }
};
