<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Same storage architecture as File Library (Knowledge module):
 * files live outside the public webroot under a random hex filename
 * and are only ever served back out through a controlled
 * Content-Disposition: attachment response — never requested
 * directly by path, so even an uploaded .php/.exe is inert bytes on
 * disk, never executed. That's what makes accepting any file type
 * here safe.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE message_attachments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                message_id INT UNSIGNED NOT NULL,
                filename VARCHAR(255) NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                mime_type VARCHAR(150) NOT NULL,
                size BIGINT UNSIGNED NOT NULL,
                uploaded_by_user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_message_attachments_message (message_id),
                CONSTRAINT fk_message_attachments_message FOREIGN KEY (message_id) REFERENCES messages (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS message_attachments');
    }
};
