<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * event_identifier is a deterministic string the listener builds from
 * the event's own data (e.g. "deal:42:stage:proposal" for a
 * DealStageChanged event) — NOT a random id. Re-delivery of the exact
 * same event, a retried request, or a crashed-and-retried process all
 * produce the identical string, so the UNIQUE constraint below makes
 * re-processing a true no-op at the database level rather than relying
 * on application-level "check then insert" (which would itself race).
 * A genuinely new occurrence of the same event type against the same
 * entity (e.g. the deal moves to PROPOSAL again later) gets a new
 * identifier and is free to fire again — this is an intentional
 * choice, not a limitation: "already handled this transition" is the
 * idempotency boundary, not "never fire this rule for this entity
 * again".
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE follow_up_rule_executions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                rule_id INT UNSIGNED NOT NULL,
                event_identifier VARCHAR(190) NOT NULL,
                related_type VARCHAR(50) NULL,
                related_id INT UNSIGNED NULL,
                status ENUM('created', 'skipped', 'failed') NOT NULL DEFAULT 'created',
                created_follow_up_id INT UNSIGNED NULL,
                executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_rule_execution (rule_id, event_identifier),
                CONSTRAINT fk_follow_up_rule_executions_rule FOREIGN KEY (rule_id) REFERENCES follow_up_rules (id) ON DELETE CASCADE,
                CONSTRAINT fk_follow_up_rule_executions_follow_up FOREIGN KEY (created_follow_up_id) REFERENCES follow_ups (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS follow_up_rule_executions');
    }
};
