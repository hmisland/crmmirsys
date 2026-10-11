<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Folders for the file library (docs/file-library-folders-thumbnails-design.md
 * D1). parent_id NULL = top level. RESTRICT on the self-FK so a folder
 * can never be dropped from under its children — the service only
 * deletes empty folders anyway. MySQL treats NULL parent_ids as
 * distinct in the unique key, so top-level name uniqueness is also
 * checked in FileLibraryService.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE file_library_folders (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                parent_id INT UNSIGNED NULL,
                name VARCHAR(190) NOT NULL,
                created_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_file_library_folders_name (organization_id, parent_id, name),
                KEY idx_file_library_folders_parent (parent_id),
                CONSTRAINT fk_file_library_folders_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_file_library_folders_parent FOREIGN KEY (parent_id) REFERENCES file_library_folders (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS file_library_folders');
    }
};
