<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Delay alerts already handled (Phase 3 follow-up): one row per overdue
 * item and date, so the project manager is emailed ONCE when a project
 * passes its expected end date or a planned milestone passes its date —
 * never again every minute. A new date (the item was rescheduled) is a
 * new row, so a later delay alerts again. The UNIQUE key is also the
 * claim: two overlapping scheduler runs can't both send the same alert.
 * status: sent, skipped (manager inactive / no Projects access) or failed.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE project_alerts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                project_id INT UNSIGNED NOT NULL,
                kind ENUM('project_overdue', 'milestone_overdue') NOT NULL,
                ref_id INT UNSIGNED NOT NULL,
                due_date DATE NOT NULL,
                user_id INT UNSIGNED NULL,
                status ENUM('sent', 'skipped', 'failed') NOT NULL DEFAULT 'sent',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_project_alerts (project_id, kind, ref_id, due_date),
                KEY idx_project_alerts_org (organization_id, created_at),
                CONSTRAINT fk_project_alerts_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_project_alerts_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
                CONSTRAINT fk_project_alerts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS project_alerts');
    }
};
