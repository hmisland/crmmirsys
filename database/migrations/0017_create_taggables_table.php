<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Polymorphic tag assignment: `taggable_type` is a short entity key
 * ('contact', 'company', 'lead', ...), not a PHP class name — keeps
 * this table stable across refactors of the actual classes. No
 * organization_id here: a tag's own row already carries it, and a
 * taggable's own row carries it too, so this pivot's authorization is
 * inherited, not duplicated.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE taggables (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tag_id INT UNSIGNED NOT NULL,
                taggable_type VARCHAR(50) NOT NULL,
                taggable_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_taggable (tag_id, taggable_type, taggable_id),
                KEY idx_taggables_subject (taggable_type, taggable_id),
                CONSTRAINT fk_taggables_tag FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS taggables');
    }
};
