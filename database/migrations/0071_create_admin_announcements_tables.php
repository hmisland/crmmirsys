<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * One-way messages from the Super Admin to organization admins.
 * Each announcement gets one recipient row per org admin at send time;
 * the row records when the admin marked it seen and when they removed
 * it from their bell (soft delete, so the Super Admin report keeps it).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE admin_announcements (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(200) NOT NULL,
                body TEXT NOT NULL,
                audience VARCHAR(10) NOT NULL DEFAULT 'all',
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_announcements_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE admin_announcement_recipients (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                announcement_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                organization_id INT UNSIGNED NULL,
                seen_at DATETIME NULL,
                deleted_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_announcement_user (announcement_id, user_id),
                KEY idx_recipient_user (user_id, deleted_at, seen_at),
                CONSTRAINT fk_ann_rcpt_announcement FOREIGN KEY (announcement_id) REFERENCES admin_announcements (id) ON DELETE CASCADE,
                CONSTRAINT fk_ann_rcpt_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS admin_announcement_recipients');
        $pdo->exec('DROP TABLE IF EXISTS admin_announcements');
    }
};
