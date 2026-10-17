<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Members of static segments. One row per (segment, contact).
 * A deleted contact disappears from every segment (ON DELETE CASCADE), so
 * it can never become an invalid recipient. created_at lets a campaign
 * approved at time T use exactly the members that existed at T.
 * Rollback drops the table.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE marketing_segment_contacts (
                segment_id INT UNSIGNED NOT NULL,
                contact_id INT UNSIGNED NOT NULL,
                organization_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (segment_id, contact_id),
                KEY idx_marketing_segment_contacts_contact (contact_id),
                KEY idx_marketing_segment_contacts_org (organization_id),
                CONSTRAINT fk_marketing_segment_contacts_segment FOREIGN KEY (segment_id) REFERENCES marketing_segments (id) ON DELETE CASCADE,
                CONSTRAINT fk_marketing_segment_contacts_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE,
                CONSTRAINT fk_marketing_segment_contacts_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS marketing_segment_contacts');
    }
};
