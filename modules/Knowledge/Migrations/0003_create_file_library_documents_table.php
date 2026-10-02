<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Any file type is accepted here (per the explicit requirement — a
 * company's ID photos, licenses, general documents don't fit a
 * narrow allow-list the way Phase 5's AttachmentService deliberately
 * restricts uploads to). Tags are NOT a column here — they use the
 * existing `tags`/`taggables` tables from Phase 5 (subject type
 * 'file_library_document'), reused rather than rebuilt.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE file_library_documents (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                filename VARCHAR(255) NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                mime_type VARCHAR(150) NOT NULL,
                size BIGINT UNSIGNED NOT NULL,
                description VARCHAR(500) NULL,
                uploaded_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_file_library_documents_organization (organization_id),
                CONSTRAINT fk_file_library_documents_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS file_library_documents');
    }
};
