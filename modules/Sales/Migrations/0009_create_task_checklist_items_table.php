<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Task checklist (T3): items of one task, in a stable order (sort_order,
 * then id). Access always goes through the task (TaskService): the same
 * tasks.view / tasks.edit rules, tenant scope and soft-delete filter.
 * Deleting a task row (never done in the app — tasks are soft-deleted)
 * removes its items.
 *
 * completed_at is a system instant (UTC, written by PHP).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE task_checklist_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                task_id INT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                is_done TINYINT(1) NOT NULL DEFAULT 0,
                completed_at DATETIME NULL,
                completed_by_user_id INT UNSIGNED NULL,
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                created_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_task_checklist_task (task_id, sort_order, id),
                KEY idx_task_checklist_organization (organization_id),
                CONSTRAINT fk_task_checklist_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_task_checklist_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE,
                CONSTRAINT fk_task_checklist_completed_by FOREIGN KEY (completed_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_task_checklist_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS task_checklist_items');
    }
};
