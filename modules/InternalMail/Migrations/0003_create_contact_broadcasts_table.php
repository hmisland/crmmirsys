<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * A log of "send to all customers" broadcasts, not a per-recipient
 * delivery tracker (no open/click tracking, no per-contact status —
 * that's real Marketing-phase territory, Phase 14). `recipient_count`
 * is a simple snapshot of how many contacts had an email address at
 * send time, recorded for the admin's own reference.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE contact_broadcasts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                sent_by_user_id INT UNSIGNED NULL,
                subject VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
                failed_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_contact_broadcasts_organization (organization_id),
                CONSTRAINT fk_contact_broadcasts_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS contact_broadcasts');
    }
};
