<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Per Phase 0 §24: tasks can optionally relate to any CRM record
 * (Contact, Company, Lead, Deal, ...) — related_type/related_id follow
 * the same polymorphic pattern as notes/tags/attachments (Phase 5),
 * left nullable so a task can also stand alone with no related record.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE tasks (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                due_at DATETIME NULL,
                priority ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
                status ENUM('pending', 'completed') NOT NULL DEFAULT 'pending',
                assigned_to_user_id INT UNSIGNED NULL,
                related_type VARCHAR(50) NULL,
                related_id INT UNSIGNED NULL,
                created_by_user_id INT UNSIGNED NULL,
                completed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_tasks_organization (organization_id),
                KEY idx_tasks_assigned (assigned_to_user_id),
                KEY idx_tasks_related (related_type, related_id),
                CONSTRAINT fk_tasks_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_tasks_assigned FOREIGN KEY (assigned_to_user_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_tasks_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS tasks');
    }
};
