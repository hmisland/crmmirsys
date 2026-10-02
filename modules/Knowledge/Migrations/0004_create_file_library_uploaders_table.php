<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Per the explicit requirement — upload/delete access to the file
 * library is "determined and managed by org admin," for some or all
 * users, not just a fixed role. org_admin can always manage files;
 * presence of a row here grants that same ability to a specific
 * member, individually, on top of whatever their role alone allows.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE file_library_uploaders (
                organization_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                granted_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (organization_id, user_id),
                CONSTRAINT fk_file_library_uploaders_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_file_library_uploaders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS file_library_uploaders');
    }
};
