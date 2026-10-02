<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * A dedicated multi-value table rather than reusing the general
 * `tags`/`taggables` tables (Phase 5) — Interests is its own concept
 * on the Details tab of the contact form, separate from the
 * open-ended organizational Tags section already on the contact
 * detail page. "Stored as tags" (per the request) means: multiple
 * discrete short values per contact, not one free-text blob — this
 * table is that; the UI displays them as pill-styled chips.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE contact_interests (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                contact_id INT UNSIGNED NOT NULL,
                interest VARCHAR(100) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_contact_interests_contact (contact_id),
                CONSTRAINT fk_contact_interests_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_contact_interests_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS contact_interests');
    }
};
