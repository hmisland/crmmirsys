<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Generic, per-organization document numbering (decision D2): one row
 * per (organization, document_type), e.g. "contract" -> CTR-0001.
 * Used by modules that need numbers without depending on Finance;
 * Finance keeps its own invoice/credit-note series untouched.
 * Rows are created lazily on first use (NumberSeriesRepository::next()).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE number_series (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                document_type VARCHAR(50) NOT NULL,
                prefix VARCHAR(20) NOT NULL DEFAULT '',
                padding TINYINT UNSIGNED NOT NULL DEFAULT 4,
                next_sequence INT UNSIGNED NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_number_series_org_type (organization_id, document_type),
                CONSTRAINT fk_number_series_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS number_series');
    }
};
