<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Users who belong to a project besides its manager (projects.owner_id).
 * Membership grants VIEW of that project only — never editing; adding or
 * removing members needs projects.manage_members on the project.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE project_members (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                project_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                added_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_project_members (project_id, user_id),
                KEY idx_project_members_user (organization_id, user_id),
                CONSTRAINT fk_project_members_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_project_members_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT fk_project_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_project_members_added_by FOREIGN KEY (added_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS project_members');
    }
};
