<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * recipient_type distinguishes To/Cc/Bcc per recipient row — a Bcc
 * row is visible to its own recipient and to the sender, never to
 * other recipients (enforced in MessageRecipientRepository's read
 * methods, not here). Trash is per-recipient (is_deleted/deleted_at),
 * matching how is_read already works — one person trashing their
 * copy of a shared message never affects anyone else's.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec("ALTER TABLE message_recipients ADD COLUMN recipient_type ENUM('to', 'cc', 'bcc') NOT NULL DEFAULT 'to' AFTER recipient_user_id");
        $pdo->exec('ALTER TABLE message_recipients ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER read_at');
        $pdo->exec('ALTER TABLE message_recipients ADD COLUMN deleted_at DATETIME NULL AFTER is_deleted');
        $pdo->exec('ALTER TABLE message_recipients ADD KEY idx_message_recipients_trash (recipient_user_id, is_deleted)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE message_recipients DROP KEY idx_message_recipients_trash');
        $pdo->exec('ALTER TABLE message_recipients DROP COLUMN deleted_at');
        $pdo->exec('ALTER TABLE message_recipients DROP COLUMN is_deleted');
        $pdo->exec('ALTER TABLE message_recipients DROP COLUMN recipient_type');
    }
};
