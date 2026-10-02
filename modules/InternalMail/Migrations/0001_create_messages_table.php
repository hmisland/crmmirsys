<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * `thread_id` is NULL for the first message in a conversation and
 * points at that root message's id for every reply — a whole thread
 * is just "every message where id = thread_id OR thread_id = that
 * root's id", read together in `MessageService::thread()`.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE messages (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                sender_user_id INT UNSIGNED NOT NULL,
                thread_id INT UNSIGNED NULL,
                subject VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_messages_organization (organization_id),
                KEY idx_messages_thread (thread_id),
                KEY idx_messages_sender (sender_user_id),
                CONSTRAINT fk_messages_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_messages_sender FOREIGN KEY (sender_user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_messages_thread FOREIGN KEY (thread_id) REFERENCES messages (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS messages');
    }
};
