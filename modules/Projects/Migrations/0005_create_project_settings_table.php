<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Per-organization Projects settings (one row per organization, created
 * on first save). auto_create_on_deal_won: when a deal is won, create a
 * draft project linked to it — OFF by default (decision D4), at most one
 * project per deal, and only ever effective while Projects is entitled to
 * the organization and Sales is installed (the event comes from Sales).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE project_settings (
                organization_id INT UNSIGNED NOT NULL PRIMARY KEY,
                auto_create_on_deal_won TINYINT(1) NOT NULL DEFAULT 0,
                updated_by_user_id INT UNSIGNED NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_project_settings_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_project_settings_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS project_settings');
    }
};
