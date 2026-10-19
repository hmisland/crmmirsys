<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Task reminders (T6) and the Task Manager's own in-app notifications.
 *
 * tasks.remind_at is the organization's local wall clock, exactly like
 * start_at / due_at (never converted to UTC); remind_all_day = 1 for a
 * date without a time (the reminder is due from 00:00 that day).
 * remind_seq numbers the reminder's occurrences: it goes up every time
 * the reminder is set or changed, and remind_fired_seq records the
 * occurrence the processor (modules/Sales/bin/task-reminders.php) has
 * already handled — so each occurrence fires once, and a changed
 * reminder fires again.
 *
 * task_notifications: one row per recipient and occurrence (UNIQUE key =
 * the dedupe). created_at / read_at are UTC instants (C1/C2); remind_at
 * is a copy of the occurrence's local wall-clock value, for display.
 * There was no per-user in-app notification store to extend (the
 * Notification module sends paid Bale/Telegram messages; announcements
 * are Super Admin broadcasts), so this one stays inside Sales.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE tasks
                ADD COLUMN remind_at DATETIME NULL AFTER due_all_day,
                ADD COLUMN remind_all_day TINYINT(1) NOT NULL DEFAULT 1 AFTER remind_at,
                ADD COLUMN remind_seq INT UNSIGNED NOT NULL DEFAULT 0 AFTER remind_all_day,
                ADD COLUMN remind_fired_seq INT UNSIGNED NOT NULL DEFAULT 0 AFTER remind_seq,
                ADD INDEX idx_tasks_reminder_due (organization_id, remind_at)'
        );
        $pdo->exec(
            "CREATE TABLE task_notifications (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                task_id INT UNSIGNED NOT NULL,
                kind VARCHAR(20) NOT NULL DEFAULT 'reminder',
                occurrence INT UNSIGNED NOT NULL,
                remind_at DATETIME NULL,
                remind_all_day TINYINT(1) NOT NULL DEFAULT 1,
                read_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_task_notifications_occurrence (task_id, user_id, kind, occurrence),
                KEY idx_task_notifications_inbox (organization_id, user_id, read_at),
                CONSTRAINT fk_task_notifications_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_task_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_task_notifications_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS task_notifications');
        $pdo->exec('ALTER TABLE tasks DROP INDEX idx_tasks_reminder_due, DROP COLUMN remind_fired_seq, DROP COLUMN remind_seq, DROP COLUMN remind_all_day, DROP COLUMN remind_at');
    }
};
