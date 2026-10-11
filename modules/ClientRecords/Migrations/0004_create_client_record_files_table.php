<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Metadata over a Core attachment — the file itself is stored by the
 * existing AttachmentService (randomized name, outside the web root,
 * real MIME check), never by this module. kind = media (before/after/
 * progress/other photos) or document (consent, identity, client-
 * provided, treatment, other); category is a short code validated in
 * ClientFileService, kept as VARCHAR so an organization-defined
 * category list can replace the built-in one later without DDL.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE client_record_files (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                client_record_id INT UNSIGNED NOT NULL,
                attachment_id INT UNSIGNED NOT NULL,
                kind ENUM('media', 'document') NOT NULL,
                category VARCHAR(30) NOT NULL DEFAULT 'other',
                service_record_id INT UNSIGNED NULL,
                session_id INT UNSIGNED NULL,
                taken_at DATE NULL,
                notes VARCHAR(500) NULL,
                uploaded_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_crf_attachment (attachment_id),
                KEY idx_crf_record_kind (client_record_id, kind),
                KEY idx_crf_session (session_id),
                KEY idx_crf_service (service_record_id),
                CONSTRAINT fk_crf_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_crf_record FOREIGN KEY (client_record_id) REFERENCES client_records (id) ON DELETE CASCADE,
                CONSTRAINT fk_crf_attachment FOREIGN KEY (attachment_id) REFERENCES attachments (id) ON DELETE CASCADE,
                CONSTRAINT fk_crf_service FOREIGN KEY (service_record_id) REFERENCES client_record_services (id) ON DELETE SET NULL,
                CONSTRAINT fk_crf_session FOREIGN KEY (session_id) REFERENCES client_record_sessions (id) ON DELETE SET NULL,
                CONSTRAINT fk_crf_uploaded_by FOREIGN KEY (uploaded_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS client_record_files');
    }
};
