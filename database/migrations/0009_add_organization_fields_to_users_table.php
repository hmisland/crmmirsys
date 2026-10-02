<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Adds what Phase 2's users table deliberately left out (per its own
 * migration's docblock): the organization FK constraint itself, plus
 * department/team/role assignment and the Super Admin flag.
 *
 * is_super_admin is a separate boolean rather than "just another
 * role" because Super Admin is explicitly a platform-level concept
 * outside any organization (Phase 0 §15), while every row in `roles`
 * is either a system template or belongs to one specific
 * organization — there is no natural "roles" row that means
 * "no organization at all."
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE users ADD COLUMN department_id INT UNSIGNED NULL AFTER organization_id');
        $pdo->exec('ALTER TABLE users ADD COLUMN team_id INT UNSIGNED NULL AFTER department_id');
        $pdo->exec('ALTER TABLE users ADD COLUMN role_id INT UNSIGNED NULL AFTER team_id');
        $pdo->exec('ALTER TABLE users ADD COLUMN is_super_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER role_id');

        $pdo->exec(
            'ALTER TABLE users ADD CONSTRAINT fk_users_organization
             FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE SET NULL'
        );
        $pdo->exec(
            'ALTER TABLE users ADD CONSTRAINT fk_users_department
             FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL'
        );
        $pdo->exec(
            'ALTER TABLE users ADD CONSTRAINT fk_users_team
             FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL'
        );
        $pdo->exec(
            'ALTER TABLE users ADD CONSTRAINT fk_users_role
             FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE SET NULL'
        );

        $pdo->exec('ALTER TABLE users ADD INDEX idx_users_department (department_id)');
        $pdo->exec('ALTER TABLE users ADD INDEX idx_users_team (team_id)');
        $pdo->exec('ALTER TABLE users ADD INDEX idx_users_role (role_id)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE users DROP FOREIGN KEY fk_users_role');
        $pdo->exec('ALTER TABLE users DROP FOREIGN KEY fk_users_team');
        $pdo->exec('ALTER TABLE users DROP FOREIGN KEY fk_users_department');
        $pdo->exec('ALTER TABLE users DROP FOREIGN KEY fk_users_organization');
        $pdo->exec('ALTER TABLE users DROP COLUMN is_super_admin');
        $pdo->exec('ALTER TABLE users DROP COLUMN role_id');
        $pdo->exec('ALTER TABLE users DROP COLUMN team_id');
        $pdo->exec('ALTER TABLE users DROP COLUMN department_id');
    }
};
