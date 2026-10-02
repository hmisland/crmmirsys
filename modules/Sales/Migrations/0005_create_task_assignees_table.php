<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * tasks.assigned_to_user_id (0004) is kept as the "primary" assignee —
 * used for simple listing ("my upcoming tasks") without a join — while
 * this pivot is the actual source of truth for "who is this task
 * assigned to" when there's more than one person. Every assignee,
 * including the primary one, gets a row here; the primary column is
 * a denormalized convenience, not a separate concept.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE task_assignees (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                task_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_task_assignee (task_id, user_id),
                CONSTRAINT fk_task_assignees_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE,
                CONSTRAINT fk_task_assignees_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS task_assignees');
    }
};
