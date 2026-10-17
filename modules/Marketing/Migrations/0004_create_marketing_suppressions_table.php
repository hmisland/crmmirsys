<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Opt-outs and suppressed addresses, per organization and channel.
 * reason: unsubscribed | bounced | complaint | blocked | manual.
 * source: link | webhook | delivery_report | user.
 * Marketing sends skip every suppressed address; transactional sends
 * skip all but "unsubscribed".
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE marketing_suppressions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                channel VARCHAR(10) NOT NULL,
                address VARCHAR(255) NOT NULL,
                contact_id INT UNSIGNED NULL,
                reason VARCHAR(30) NOT NULL,
                source VARCHAR(30) NOT NULL,
                created_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_marketing_suppressions (organization_id, channel, address),
                CONSTRAINT fk_marketing_suppressions_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_marketing_suppressions_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL,
                CONSTRAINT fk_marketing_suppressions_user FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS marketing_suppressions');
    }
};
