<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * "Proforma" = the architecture spec's "Quote" entity, named to
 * match the client's own terminology directly. Free CRUD by its own
 * creator — no approval gate, unlike Invoice (12.5). The only
 * built-in restriction: once converted to an Invoice (12.5),
 * converted_invoice_id is set and the Proforma becomes read-only —
 * not a permission restriction, just "this is now historical".
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE proformas (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                contact_id INT UNSIGNED NULL,
                owner_id INT UNSIGNED NOT NULL,
                issue_date DATE NOT NULL,
                expiry_date DATE NULL,
                currency_id INT UNSIGNED NOT NULL,
                pricing_mode ENUM('tax_inclusive', 'tax_exclusive') NOT NULL DEFAULT 'tax_exclusive',
                tax_profile_id INT UNSIGNED NULL,
                invoice_discount DECIMAL(38,18) NOT NULL DEFAULT 0,
                subtotal DECIMAL(38,18) NOT NULL DEFAULT 0,
                tax_total DECIMAL(38,18) NOT NULL DEFAULT 0,
                grand_total DECIMAL(38,18) NOT NULL DEFAULT 0,
                status ENUM('draft', 'sent', 'accepted', 'rejected', 'expired', 'converted') NOT NULL DEFAULT 'draft',
                converted_invoice_id INT UNSIGNED NULL,
                terms TEXT NULL,
                notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_proformas_organization (organization_id),
                KEY idx_proformas_owner (owner_id),
                CONSTRAINT fk_proformas_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_proformas_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL,
                CONSTRAINT fk_proformas_currency FOREIGN KEY (currency_id) REFERENCES currencies (id),
                CONSTRAINT fk_proformas_tax_profile FOREIGN KEY (tax_profile_id) REFERENCES tax_profiles (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE proforma_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                proforma_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NULL,
                description VARCHAR(255) NOT NULL,
                quantity DECIMAL(18,4) NOT NULL DEFAULT 1,
                unit_price DECIMAL(38,18) NOT NULL DEFAULT 0,
                discount_type ENUM('none', 'percentage', 'fixed') NOT NULL DEFAULT 'none',
                discount_value DECIMAL(38,18) NOT NULL DEFAULT 0,
                line_total DECIMAL(38,18) NOT NULL DEFAULT 0,
                position INT UNSIGNED NOT NULL DEFAULT 0,
                CONSTRAINT fk_proforma_items_proforma FOREIGN KEY (proforma_id) REFERENCES proformas (id) ON DELETE CASCADE,
                CONSTRAINT fk_proforma_items_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS proforma_items');
        $pdo->exec('DROP TABLE IF EXISTS proformas');
    }
};
