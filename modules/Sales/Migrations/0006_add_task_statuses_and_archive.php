<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Tasks get a real workflow instead of a pending/completed toggle:
 * pending → in_progress / on_hold → completed | rejected | cancelled.
 *
 * Archiving is deliberately NOT a status: archived_at is a separate
 * flag so an archived task keeps the outcome it ended with (completed,
 * rejected, ...) and can be restored as-is. Existing rows keep their
 * current status (both old values exist in the new ENUM).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "ALTER TABLE tasks
                MODIFY status ENUM('pending', 'in_progress', 'on_hold', 'completed', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
                ADD COLUMN archived_at DATETIME NULL AFTER completed_at,
                ADD COLUMN archived_by_user_id INT UNSIGNED NULL AFTER archived_at,
                ADD KEY idx_tasks_status (organization_id, status),
                ADD KEY idx_tasks_archived (organization_id, archived_at),
                ADD CONSTRAINT fk_tasks_archived_by FOREIGN KEY (archived_by_user_id) REFERENCES users (id) ON DELETE SET NULL"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("UPDATE tasks SET status = 'pending' WHERE status IN ('in_progress', 'on_hold')");
        $pdo->exec("UPDATE tasks SET status = 'completed' WHERE status IN ('rejected', 'cancelled')");
        $pdo->exec(
            "ALTER TABLE tasks
                DROP FOREIGN KEY fk_tasks_archived_by,
                DROP KEY idx_tasks_archived,
                DROP KEY idx_tasks_status,
                DROP COLUMN archived_by_user_id,
                DROP COLUMN archived_at,
                MODIFY status ENUM('pending', 'completed') NOT NULL DEFAULT 'pending'"
        );
    }
};
