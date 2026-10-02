<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE products (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                sku VARCHAR(50) NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                unit VARCHAR(30) NULL,
                currency_id INT UNSIGNED NOT NULL,
                price DECIMAL(38,18) NOT NULL DEFAULT 0,
                tax_category_id INT UNSIGNED NULL,
                tax_profile_id INT UNSIGNED NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_products_organization (organization_id),
                CONSTRAINT fk_products_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_products_currency FOREIGN KEY (currency_id) REFERENCES currencies (id),
                CONSTRAINT fk_products_tax_category FOREIGN KEY (tax_category_id) REFERENCES tax_categories (id) ON DELETE SET NULL,
                CONSTRAINT fk_products_tax_profile FOREIGN KEY (tax_profile_id) REFERENCES tax_profiles (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS products');
    }
};
