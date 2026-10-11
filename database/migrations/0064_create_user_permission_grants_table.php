<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Per-user permission grants on top of the role. Only a short
 * allowlist of keys (Permission::USER_GRANTABLE — currently contacts
 * CSV import/export) can ever be granted this way; everything else
 * stays purely role-based. An Organization Admin grants/revokes them
 * from the team members page.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE user_permission_grants (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                permission_key VARCHAR(100) NOT NULL,
                granted_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_permission_grant (user_id, permission_key),
                KEY idx_user_permission_grants_org (organization_id),
                CONSTRAINT fk_user_permission_grants_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_user_permission_grants_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS user_permission_grants');
    }
};
