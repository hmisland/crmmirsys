<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * conditions and action_configuration are JSON, per the amendment's
 * explicit instruction to use the project's established JSON
 * convention for this rather than a new generic workflow engine.
 * event_type is a plain string (e.g. "DealStageChanged") matching
 * the concrete Event class's short name — FollowUpRuleEngine matches
 * on this when it receives a dispatched event.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE follow_up_rules (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                name VARCHAR(150) NOT NULL,
                description VARCHAR(255) NULL,
                event_type VARCHAR(100) NOT NULL,
                conditions_json TEXT NULL,
                action_type VARCHAR(50) NOT NULL DEFAULT 'create_follow_up',
                action_configuration_json TEXT NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                priority INT NOT NULL DEFAULT 0,
                created_by_user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_follow_up_rules_organization_event (organization_id, event_type, enabled),
                CONSTRAINT fk_follow_up_rules_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS follow_up_rules');
    }
};
