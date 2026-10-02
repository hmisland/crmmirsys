<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE pipeline_stages (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                pipeline_id INT UNSIGNED NOT NULL,
                name VARCHAR(255) NOT NULL,
                position INT UNSIGNED NOT NULL DEFAULT 0,
                probability TINYINT UNSIGNED NOT NULL DEFAULT 0,
                is_won TINYINT(1) NOT NULL DEFAULT 0,
                is_lost TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_pipeline_stages_organization (organization_id),
                KEY idx_pipeline_stages_pipeline (pipeline_id),
                CONSTRAINT fk_pipeline_stages_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_pipeline_stages_pipeline FOREIGN KEY (pipeline_id) REFERENCES pipelines (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS pipeline_stages');
    }
};
