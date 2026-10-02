<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Per Phase 0 §17: scope is stored per role-permission pair.
 * `permission_key` is a code-defined key from
 * App\Infrastructure\Authorization\Permission (not DB-editable) —
 * this table only says which scope a role has for a given key, never
 * introduces new keys.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE role_permissions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                role_id INT UNSIGNED NOT NULL,
                permission_key VARCHAR(100) NOT NULL,
                scope ENUM('own', 'team', 'department', 'organization', 'all') NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_role_permission (role_id, permission_key),
                CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS role_permissions');
    }
};
