<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Company profile: category, brand (the name the company is known by),
 * national ID, economic code, registration number, CEO name and a
 * free-text description.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE companies
                ADD COLUMN category_id INT UNSIGNED NULL AFTER name,
                ADD COLUMN brand VARCHAR(150) NULL AFTER category_id,
                ADD COLUMN national_id VARCHAR(30) NULL AFTER brand,
                ADD COLUMN economic_code VARCHAR(30) NULL AFTER national_id,
                ADD COLUMN registration_number VARCHAR(50) NULL AFTER economic_code,
                ADD COLUMN ceo_name VARCHAR(150) NULL AFTER registration_number,
                ADD COLUMN description TEXT NULL AFTER employees_count'
        );
        $pdo->exec(
            'ALTER TABLE companies ADD CONSTRAINT fk_companies_category
             FOREIGN KEY (category_id) REFERENCES company_categories (id) ON DELETE SET NULL'
        );
        $pdo->exec('CREATE INDEX idx_companies_category ON companies (category_id)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE companies DROP FOREIGN KEY fk_companies_category');
        $pdo->exec(
            'ALTER TABLE companies
                DROP COLUMN category_id, DROP COLUMN brand, DROP COLUMN national_id,
                DROP COLUMN economic_code, DROP COLUMN registration_number,
                DROP COLUMN ceo_name, DROP COLUMN description'
        );
    }
};
