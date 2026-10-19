<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * T7: email reminders and recurring tasks.
 *
 * tasks.remind_email: the reminder also sends an email (opt-in, default
 * off); the in-app reminder (T6) works with or without it.
 *
 * Recurrence, on each occurrence (a task): recur_type daily / weekly /
 * monthly (NULL = none), every recur_interval units, until recur_until
 * (a local DATE, optional), recur_active on/off. recur_anchor is the
 * series' origin (the local wall-clock start, else due, it was set
 * from) and recur_index this occurrence's step from it — so monthly
 * dates never drift (Jan 31 → Feb 28 → Mar 31). series_id is the
 * original task, recur_parent_id the occurrence this one was generated
 * from; UNIQUE(recur_parent_id) = at most one next occurrence per
 * task, whatever the worker does.
 *
 * task_notifications.email_*: per recipient and occurrence — 'none'
 * (no email wanted), 'pending' (to send / retry at email_next_at),
 * 'sent', 'failed' (gave up) or 'skipped' (no longer eligible).
 * email_lock / email_locked_at: the sending claim. Times are UTC.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE tasks
                ADD COLUMN remind_email TINYINT(1) NOT NULL DEFAULT 0 AFTER remind_fired_seq,
                ADD COLUMN recur_type VARCHAR(10) NULL AFTER remind_email,
                ADD COLUMN recur_interval SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER recur_type,
                ADD COLUMN recur_until DATE NULL AFTER recur_interval,
                ADD COLUMN recur_active TINYINT(1) NOT NULL DEFAULT 0 AFTER recur_until,
                ADD COLUMN recur_anchor DATETIME NULL AFTER recur_active,
                ADD COLUMN recur_index INT UNSIGNED NOT NULL DEFAULT 0 AFTER recur_anchor,
                ADD COLUMN series_id INT UNSIGNED NULL AFTER recur_index,
                ADD COLUMN recur_parent_id INT UNSIGNED NULL AFTER series_id,
                ADD UNIQUE KEY uniq_tasks_recur_parent (recur_parent_id),
                ADD INDEX idx_tasks_recurring (organization_id, recur_active),
                ADD INDEX idx_tasks_series (series_id),
                ADD CONSTRAINT fk_tasks_recur_parent FOREIGN KEY (recur_parent_id) REFERENCES tasks (id) ON DELETE SET NULL'
        );
        $pdo->exec(
            "ALTER TABLE task_notifications
                ADD COLUMN email_status VARCHAR(10) NOT NULL DEFAULT 'none' AFTER read_at,
                ADD COLUMN email_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER email_status,
                ADD COLUMN email_next_at DATETIME NULL AFTER email_attempts,
                ADD COLUMN email_sent_at DATETIME NULL AFTER email_next_at,
                ADD COLUMN email_error VARCHAR(255) NULL AFTER email_sent_at,
                ADD COLUMN email_lock VARCHAR(32) NULL AFTER email_error,
                ADD COLUMN email_locked_at DATETIME NULL AFTER email_lock,
                ADD INDEX idx_task_notifications_email (organization_id, email_status)"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE task_notifications DROP INDEX idx_task_notifications_email,
                DROP COLUMN email_locked_at, DROP COLUMN email_lock, DROP COLUMN email_error, DROP COLUMN email_sent_at,
                DROP COLUMN email_next_at, DROP COLUMN email_attempts, DROP COLUMN email_status'
        );
        $pdo->exec(
            'ALTER TABLE tasks DROP FOREIGN KEY fk_tasks_recur_parent, DROP INDEX uniq_tasks_recur_parent, DROP INDEX idx_tasks_recurring, DROP INDEX idx_tasks_series,
                DROP COLUMN recur_parent_id, DROP COLUMN series_id, DROP COLUMN recur_index, DROP COLUMN recur_anchor, DROP COLUMN recur_active,
                DROP COLUMN recur_until, DROP COLUMN recur_interval, DROP COLUMN recur_type, DROP COLUMN remind_email'
        );
    }
};
