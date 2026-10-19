<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Task labels (T4): an organization's own colored labels and their links
 * to tasks. Separate from the shared contact/company tags (`tags`,
 * `taggables`), which are not touched.
 *
 * `color` is a key of a fixed palette (TaskLabelService::COLORS), never
 * CSS. Deleting a label removes its links (CASCADE) — never a task.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE task_labels (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                name VARCHAR(50) NOT NULL,
                color VARCHAR(20) NOT NULL DEFAULT 'gray',
                created_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_task_labels_org_name (organization_id, name),
                CONSTRAINT fk_task_labels_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_task_labels_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE task_label_links (
                task_id INT UNSIGNED NOT NULL,
                label_id INT UNSIGNED NOT NULL,
                organization_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (task_id, label_id),
                KEY idx_task_label_links_label (label_id),
                KEY idx_task_label_links_organization (organization_id),
                CONSTRAINT fk_task_label_links_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE,
                CONSTRAINT fk_task_label_links_label FOREIGN KEY (label_id) REFERENCES task_labels (id) ON DELETE CASCADE,
                CONSTRAINT fk_task_label_links_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS task_label_links');
        $pdo->exec('DROP TABLE IF EXISTS task_labels');
    }
};
