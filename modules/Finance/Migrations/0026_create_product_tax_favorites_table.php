<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * "Frequently used" entries of the national product list, per organization
 * (shared by its users): starred in the tax-catalog search, removed again
 * with the star or once a product is created from them. A copy of the
 * list's title / VAT / taxable text, so the list shows without kala.db.
 * New table only; rollback drops it.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE product_tax_favorites (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                tax_product_code VARCHAR(13) NOT NULL,
                title VARCHAR(500) NOT NULL,
                vat SMALLINT NULL,
                taxable VARCHAR(60) NULL,
                created_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_product_tax_favorites (organization_id, tax_product_code),
                CONSTRAINT fk_product_tax_favorites_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_product_tax_favorites_user FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS product_tax_favorites');
    }
};
