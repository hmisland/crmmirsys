<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Per Phase 0 §12–13/§7: an organization's profile + defaults.
 * Currency/plan/entitlement fields from the Phase 0 currency/plans
 * architecture are intentionally NOT added here yet — those belong to
 * their own phases (Currency Management, Billing) and would just be
 * unused columns until then.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE organizations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL,
                default_language VARCHAR(10) NOT NULL DEFAULT 'fa',
                default_timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
                status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_organizations_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS organizations');
    }
};
