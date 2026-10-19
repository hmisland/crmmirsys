<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Task scheduling (T2): an optional start, and an "all day" flag for the
 * start and the due date.
 *
 * start_at, like due_at, is the organization's local wall-clock time (C3,
 * docs/organization-timezone-design.md): stored as typed, never converted.
 * *_all_day = 1 means "a date without a time" — the stored time part is
 * then 00:00:00 by convention and is not shown; 0 means the time is real
 * (so an explicit 00:00 is midnight, not "no time").
 *
 * Existing due dates with a time other than 00:00 stay timed; all others
 * (the date-only ones) become all-day.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "ALTER TABLE tasks
                ADD COLUMN start_at DATETIME NULL AFTER description,
                ADD COLUMN start_all_day TINYINT(1) NOT NULL DEFAULT 1 AFTER start_at,
                ADD COLUMN due_all_day TINYINT(1) NOT NULL DEFAULT 1 AFTER due_at"
        );
        $pdo->exec("UPDATE tasks SET due_all_day = 0 WHERE due_at IS NOT NULL AND TIME(due_at) <> '00:00:00'");
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec(
            "ALTER TABLE tasks
                DROP COLUMN due_all_day,
                DROP COLUMN start_all_day,
                DROP COLUMN start_at"
        );
    }
};
