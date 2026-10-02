<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Per Phase 0 §12–13, users belong to an organization. organization_id
 * is added here as NULLABLE rather than waiting for Phase 3 to add the
 * column: Phase 2 (Authentication) needs a working users table before
 * Organizations exist at all, and adding a nullable column now is a
 * cheap, non-destructive way to avoid altering a populated table
 * later. Phase 3 adds the actual `organizations` table, the foreign
 * key constraint, and enforces NOT NULL for every non-Super-Admin
 * user (Super Admins are the one kind of user with no organization).
 *
 * Email is UNIQUE platform-wide (not per-organization): per Phase 0
 * §12–13 a user belongs to exactly one organization via a single
 * organization_id column, not a many-to-many membership table, so one
 * email = one account = one organization for now. Someone working
 * with two separate client organizations needs two separate accounts
 * under this model — a deliberate simplicity trade-off for the MVP,
 * called out here rather than left implicit.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NULL,
                email VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                first_name VARCHAR(100) NULL,
                last_name VARCHAR(100) NULL,
                language VARCHAR(10) NOT NULL DEFAULT 'fa',
                timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
                status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
                email_verified_at DATETIME NULL,
                last_login_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_users_email (email),
                KEY idx_users_organization (organization_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS users');
    }
};
