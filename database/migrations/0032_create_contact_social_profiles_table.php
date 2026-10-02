<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * `platform` covers the common named ones (facebook/instagram/youtube/
 * twitter/linkedin/telegram/whatsapp) plus 'custom' for anything else
 * the user wants to add, with custom_label holding the user-typed name
 * only in that case — per the request "یا انتخابی بتونه کاربر اضافه کنه".
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE contact_social_profiles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                contact_id INT UNSIGNED NOT NULL,
                platform ENUM('facebook', 'instagram', 'youtube', 'twitter', 'linkedin', 'telegram', 'whatsapp', 'custom') NOT NULL,
                custom_label VARCHAR(100) NULL,
                value VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_contact_social_contact (contact_id),
                CONSTRAINT fk_contact_social_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_contact_social_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS contact_social_profiles');
    }
};
