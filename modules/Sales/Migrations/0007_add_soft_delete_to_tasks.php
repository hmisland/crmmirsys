<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Task Manager Phase 0: deleting a task is a soft delete. deleted_at /
 * deleted_by_user_id mark it; the row, its assignees, comments and
 * attachments stay for audit and recovery. Every TaskRepository read
 * skips deleted rows. Additive only — no existing row changes.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "ALTER TABLE tasks
                ADD COLUMN deleted_at DATETIME NULL AFTER archived_by_user_id,
                ADD COLUMN deleted_by_user_id INT UNSIGNED NULL AFTER deleted_at,
                ADD KEY idx_tasks_deleted (organization_id, deleted_at),
                ADD CONSTRAINT fk_tasks_deleted_by FOREIGN KEY (deleted_by_user_id) REFERENCES users (id) ON DELETE SET NULL"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec(
            "ALTER TABLE tasks
                DROP FOREIGN KEY fk_tasks_deleted_by,
                DROP KEY idx_tasks_deleted,
                DROP COLUMN deleted_by_user_id,
                DROP COLUMN deleted_at"
        );
    }
};
