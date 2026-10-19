<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Custom task statuses (T8): each organization's own list — name, color
 * (a key of a fixed palette, never CSS), order, active flag — each mapped
 * to a fixed lifecycle category (open / in_progress / completed /
 * cancelled / archived) that alone decides behavior.
 *
 * tasks.status stays exactly as it was and is still written (kept in step
 * with the category), so every existing rule that reads it — workflow,
 * reopen, reminders, recurrence, lists — keeps working. tasks.status_id
 * (nullable) is the chosen custom status; NULL = a task not touched since,
 * shown through the organization's status for its legacy value
 * (task_statuses.system_key). No task row is rewritten here.
 *
 * Every existing organization gets the six statuses it has today, with
 * system_key = the legacy value and no name (shown translated until
 * renamed). Organizations created later get them on first use
 * (TaskStatusService).
 */
return new class implements MigrationInterface {
    private const DEFAULTS = [
        // system_key => [category, color, sort]
        'pending' => ['open', 'gray', 10],
        'in_progress' => ['in_progress', 'blue', 20],
        'on_hold' => ['in_progress', 'orange', 30],
        'completed' => ['completed', 'green', 40],
        'rejected' => ['cancelled', 'red', 50],
        'cancelled' => ['cancelled', 'gray', 60],
    ];

    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE task_statuses (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                name VARCHAR(50) NULL,
                color VARCHAR(20) NOT NULL DEFAULT 'gray',
                category VARCHAR(20) NOT NULL,
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                system_key VARCHAR(20) NULL,
                created_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_task_statuses_org_name (organization_id, name),
                UNIQUE KEY uniq_task_statuses_org_system (organization_id, system_key),
                KEY idx_task_statuses_org_order (organization_id, sort_order),
                CONSTRAINT fk_task_statuses_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_task_statuses_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            'ALTER TABLE tasks
                ADD COLUMN status_id INT UNSIGNED NULL AFTER status,
                ADD INDEX idx_tasks_org_status (organization_id, status_id),
                ADD CONSTRAINT fk_tasks_status FOREIGN KEY (status_id) REFERENCES task_statuses (id) ON DELETE SET NULL'
        );

        $insert = $pdo->prepare(
            'INSERT INTO task_statuses (organization_id, name, color, category, sort_order, is_active, system_key)
             SELECT o.id, NULL, :color, :category, :sort, 1, :system_key FROM organizations o'
        );
        foreach (self::DEFAULTS as $key => [$category, $color, $sort]) {
            $insert->execute(['color' => $color, 'category' => $category, 'sort' => $sort, 'system_key' => $key]);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE tasks DROP FOREIGN KEY fk_tasks_status, DROP INDEX idx_tasks_org_status, DROP COLUMN status_id');
        $pdo->exec('DROP TABLE IF EXISTS task_statuses');
    }
};
