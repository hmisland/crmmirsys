<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Architecture-ready but deferred per the amendment's §25: the table
 * exists so recurrence is representable, but no scheduled job
 * actually generates the next occurrence yet — is_recurring on
 * follow_ups stays false for everything created this phase.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE follow_up_recurrences (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                follow_up_id INT UNSIGNED NOT NULL,
                frequency ENUM('daily', 'weekly', 'monthly', 'custom') NOT NULL,
                interval_count INT UNSIGNED NOT NULL DEFAULT 1,
                start_at DATE NOT NULL,
                end_at DATE NULL,
                max_occurrences INT UNSIGNED NULL,
                timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Tehran',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_follow_up_recurrences_follow_up FOREIGN KEY (follow_up_id) REFERENCES follow_ups (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS follow_up_recurrences');
    }
};
