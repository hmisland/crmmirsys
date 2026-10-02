<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * A Follow-up is deliberately its own entity, distinct from Activities
 * (logged via Notes/Timeline) and Tasks (general work items) — a
 * planned or automated action arising from an interaction, event, or
 * business rule. Reusable across Sales/Leads/Deals/Support/Marketing
 * via related_type/related_id, the same polymorphic pattern already
 * used by tasks (0004_create_tasks_table) rather than a new one.
 *
 * owner_id follows the exact convention already used by
 * proformas/invoices (Finance, Phase 12) for OWN-scope RBAC —
 * the person who manages this follow-up. assignee_user_id is who
 * must actually do it, which can differ (e.g. a manager creates it,
 * assigns it to a rep). Both nullable except owner_id.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE follow_ups (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                type ENUM('call', 'email', 'message', 'meeting', 'task', 'reminder', 'note', 'custom') NOT NULL DEFAULT 'task',
                status ENUM('pending', 'scheduled', 'in_progress', 'completed', 'cancelled', 'skipped') NOT NULL DEFAULT 'pending',
                priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
                owner_id INT UNSIGNED NOT NULL,
                assignee_user_id INT UNSIGNED NULL,
                team_id INT UNSIGNED NULL,
                department_id INT UNSIGNED NULL,
                due_at DATETIME NULL,
                completed_at DATETIME NULL,
                cancelled_at DATETIME NULL,
                timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Tehran',
                related_type VARCHAR(50) NULL,
                related_id INT UNSIGNED NULL,
                source_type VARCHAR(50) NULL,
                source_id INT UNSIGNED NULL,
                parent_follow_up_id INT UNSIGNED NULL,
                is_automated TINYINT(1) NOT NULL DEFAULT 0,
                is_recurring TINYINT(1) NOT NULL DEFAULT 0,
                created_by_user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_follow_ups_organization (organization_id),
                KEY idx_follow_ups_owner (owner_id),
                KEY idx_follow_ups_assignee (assignee_user_id),
                KEY idx_follow_ups_related (related_type, related_id),
                KEY idx_follow_ups_due (organization_id, status, due_at),
                CONSTRAINT fk_follow_ups_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_follow_ups_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_follow_ups_assignee FOREIGN KEY (assignee_user_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_follow_ups_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL,
                CONSTRAINT fk_follow_ups_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL,
                CONSTRAINT fk_follow_ups_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_follow_ups_parent FOREIGN KEY (parent_follow_up_id) REFERENCES follow_ups (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS follow_ups');
    }
};
