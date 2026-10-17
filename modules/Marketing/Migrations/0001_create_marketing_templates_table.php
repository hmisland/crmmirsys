<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/** Reusable message templates per organization and channel (sms | email). */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE marketing_templates (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                channel VARCHAR(10) NOT NULL,
                name VARCHAR(150) NOT NULL,
                subject VARCHAR(255) NULL,
                body_text TEXT NULL,
                body_html MEDIUMTEXT NULL,
                owner_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_marketing_templates_org_channel (organization_id, channel),
                CONSTRAINT fk_marketing_templates_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_marketing_templates_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS marketing_templates');
    }
};
