<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Per Phase 0 §18: a unified activity feed per record. Populated by
 * TimelineService::record(), called from Contact/Company/Lead
 * Services (and NoteService) after a meaningful action — never
 * written to directly by a controller.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE timeline_events (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                subject_type VARCHAR(50) NOT NULL,
                subject_id INT UNSIGNED NOT NULL,
                type VARCHAR(50) NOT NULL,
                description VARCHAR(500) NOT NULL,
                actor_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_timeline_subject (subject_type, subject_id, created_at),
                CONSTRAINT fk_timeline_events_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_timeline_events_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS timeline_events');
    }
};
