<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Public customer-experience links (docs/integrations-architecture.md):
 * login-free pages such as a satisfaction survey, reached at /p/{token}.
 *
 * Only the SHA-256 hash of the token is stored — the token itself is
 * handed out once (inside the message the customer receives) and cannot
 * be recovered from the database. The URL carries no organization,
 * customer, order or row id; subject_type/subject_id stay server-side.
 *
 * purpose selects the module handler (e.g. "cx_survey"); status:
 * active | revoked | used. A link stops working when revoked, used up
 * (use_count >= max_uses) or past expires_at.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE public_links (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                purpose VARCHAR(40) NOT NULL,
                subject_type VARCHAR(40) NOT NULL,
                subject_id BIGINT UNSIGNED NOT NULL,
                locale VARCHAR(5) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                expires_at DATETIME NULL,
                max_uses INT UNSIGNED NULL,
                use_count INT UNSIGNED NOT NULL DEFAULT 0,
                first_opened_at DATETIME NULL,
                last_used_at DATETIME NULL,
                revoked_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_public_links_token (token_hash),
                KEY idx_public_links_subject (organization_id, subject_type, subject_id),
                KEY idx_public_links_status (status, expires_at),
                CONSTRAINT fk_public_links_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS public_links');
    }
};
