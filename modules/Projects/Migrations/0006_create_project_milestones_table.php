<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Milestones of a project (Phase 1): a name, a date and a simple status
 * (planned / completed / cancelled — no state machine). Tenant-scoped
 * like every Projects table; removed with their project. Writes need
 * projects.edit on the project (ProjectMilestoneService) — no new
 * permission key.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE project_milestones (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                project_id INT UNSIGNED NOT NULL,
                name VARCHAR(255) NOT NULL,
                date DATE NOT NULL,
                status ENUM('planned', 'completed', 'cancelled') NOT NULL DEFAULT 'planned',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_project_milestones_project (organization_id, project_id, date),
                CONSTRAINT fk_project_milestones_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_project_milestones_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS project_milestones');
    }
};
