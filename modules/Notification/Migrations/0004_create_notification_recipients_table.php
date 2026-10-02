<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * A separate table rather than columns on `users` — this module owns
 * its own data (same principle as every other module so far), and
 * `users` stays a Core concern. `bale_chat_id` is filled in by the
 * user themselves after starting a chat with the org's Bale bot (a
 * bot can only message a chat_id that has already messaged it first
 * — confirmed against Bale's own official Bot API docs, same
 * restriction as Telegram bots; there is no supported way to message
 * someone by phone number alone).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE notification_recipients (
                user_id INT UNSIGNED PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                bale_chat_id VARCHAR(100) NULL,
                phone_number VARCHAR(20) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_notification_recipients_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_notification_recipients_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS notification_recipients');
    }
};
