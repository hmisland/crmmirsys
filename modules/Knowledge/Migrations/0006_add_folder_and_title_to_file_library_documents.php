<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * D1 + D2: a document lives in one folder (NULL = top level) and has
 * an optional display title. Existing rows keep folder_id NULL and
 * title NULL (the list falls back to the filename without extension).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE file_library_documents
                ADD COLUMN folder_id INT UNSIGNED NULL AFTER organization_id,
                ADD COLUMN title VARCHAR(255) NULL AFTER original_filename,
                ADD KEY idx_file_library_documents_folder (folder_id),
                ADD CONSTRAINT fk_file_library_documents_folder FOREIGN KEY (folder_id) REFERENCES file_library_folders (id) ON DELETE RESTRICT'
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE file_library_documents DROP FOREIGN KEY fk_file_library_documents_folder');
        $pdo->exec('ALTER TABLE file_library_documents DROP KEY idx_file_library_documents_folder, DROP COLUMN folder_id, DROP COLUMN title');
    }
};
