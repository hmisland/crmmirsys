<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Status machine for this sub-phase: Draft -> Issued -> Cancelled.
 * Paid/Partially Paid/Overdue are added once Payments exist (12.6)
 * — this phase has no payment concept yet, so those statuses would
 * be meaningless here. Once Issued, an invoice is immutable (no
 * further UPDATE from this phase's services) — a correction is
 * always a Credit Note (12.7), never an edit, per the architecture's
 * explicit rule.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE invoices (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                contact_id INT UNSIGNED NULL,
                proforma_id INT UNSIGNED NULL,
                owner_id INT UNSIGNED NOT NULL,
                invoice_number VARCHAR(50) NULL,
                issue_date DATE NOT NULL,
                due_date DATE NULL,
                currency_id INT UNSIGNED NOT NULL,
                pricing_mode ENUM('tax_inclusive', 'tax_exclusive') NOT NULL DEFAULT 'tax_exclusive',
                tax_profile_id INT UNSIGNED NULL,
                invoice_discount DECIMAL(38,18) NOT NULL DEFAULT 0,
                subtotal DECIMAL(38,18) NOT NULL DEFAULT 0,
                tax_total DECIMAL(38,18) NOT NULL DEFAULT 0,
                grand_total DECIMAL(38,18) NOT NULL DEFAULT 0,
                paid_total DECIMAL(38,18) NOT NULL DEFAULT 0,
                status ENUM('draft', 'issued', 'cancelled') NOT NULL DEFAULT 'draft',
                issued_by_user_id INT UNSIGNED NULL,
                finalized_at DATETIME NULL,
                terms TEXT NULL,
                notes TEXT NULL,
                tax_snapshot TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_invoices_organization (organization_id),
                KEY idx_invoices_owner (owner_id),
                CONSTRAINT fk_invoices_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_invoices_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL,
                CONSTRAINT fk_invoices_proforma FOREIGN KEY (proforma_id) REFERENCES proformas (id) ON DELETE SET NULL,
                CONSTRAINT fk_invoices_currency FOREIGN KEY (currency_id) REFERENCES currencies (id),
                CONSTRAINT fk_invoices_tax_profile FOREIGN KEY (tax_profile_id) REFERENCES tax_profiles (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE invoice_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                invoice_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NULL,
                description VARCHAR(255) NOT NULL,
                quantity DECIMAL(18,4) NOT NULL DEFAULT 1,
                unit_price DECIMAL(38,18) NOT NULL DEFAULT 0,
                discount_type ENUM('none', 'percentage', 'fixed') NOT NULL DEFAULT 'none',
                discount_value DECIMAL(38,18) NOT NULL DEFAULT 0,
                line_total DECIMAL(38,18) NOT NULL DEFAULT 0,
                position INT UNSIGNED NOT NULL DEFAULT 0,
                CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
                CONSTRAINT fk_invoice_items_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE invoice_number_series (
                organization_id INT UNSIGNED PRIMARY KEY,
                prefix VARCHAR(20) NOT NULL DEFAULT 'INV-',
                next_sequence INT UNSIGNED NOT NULL DEFAULT 1,
                current_fiscal_year INT UNSIGNED NULL,
                CONSTRAINT fk_invoice_number_series_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS invoice_number_series');
        $pdo->exec('DROP TABLE IF EXISTS invoice_items');
        $pdo->exec('DROP TABLE IF EXISTS invoices');
    }
};
