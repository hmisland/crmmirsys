<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * channel is extensible by design (per the amendment's §8): only
 * 'in_app' is actually active this phase — it means "appears in the
 * Follow-ups list/dashboard as due", no separate notification UI.
 * 'email'/'sms'/'push' are valid ENUM values so the schema doesn't
 * need to change when those channels are wired up later, but nothing
 * sends through them yet (the project's Notification module has no
 * real email channel either — only Bale/SMS gateways for a different
 * purpose).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE follow_up_reminders (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                follow_up_id INT UNSIGNED NOT NULL,
                channel ENUM('in_app', 'email', 'sms', 'push') NOT NULL DEFAULT 'in_app',
                offset_minutes INT NOT NULL DEFAULT 0,
                scheduled_at DATETIME NOT NULL,
                sent_at DATETIME NULL,
                status ENUM('pending', 'sent', 'skipped', 'failed') NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_follow_up_reminders_follow_up (follow_up_id),
                KEY idx_follow_up_reminders_due (status, scheduled_at),
                CONSTRAINT fk_follow_up_reminders_follow_up FOREIGN KEY (follow_up_id) REFERENCES follow_ups (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS follow_up_reminders');
    }
};
