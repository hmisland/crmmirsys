<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * `stored_path` is a server-side path AttachmentService controls
 * (randomized filename, outside any web-executable convention) —
 * never derived from the user-supplied filename, which is kept only
 * in `filename` for display/download purposes. Per Phase 0 §44: no
 * executable upload without explicit controls — enforced in
 * AttachmentService, not by this table.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE attachments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                attachable_type VARCHAR(50) NOT NULL,
                attachable_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NULL,
                filename VARCHAR(255) NOT NULL,
                stored_path VARCHAR(500) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                size INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_attachments_subject (attachable_type, attachable_id),
                CONSTRAINT fk_attachments_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_attachments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS attachments');
    }
};
