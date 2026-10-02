<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Minimal for this sub-phase (12.1 — Foundation) — enough fields to
 * pick a jurisdiction/currency and start numbering invoices. Fields
 * from the fuller architecture spec (registration numbers, fiscal
 * year, rounding policy, etc.) are added in later sub-phases as the
 * features that need them are actually built, not speculatively now.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE financial_profiles (
                organization_id INT UNSIGNED PRIMARY KEY,
                legal_name VARCHAR(255) NULL,
                tax_identification_number VARCHAR(50) NULL,
                country_id INT UNSIGNED NULL,
                tax_jurisdiction_id INT UNSIGNED NULL,
                base_currency_id INT UNSIGNED NULL,
                invoice_prefix VARCHAR(20) NOT NULL DEFAULT 'INV-',
                pricing_mode ENUM('tax_inclusive', 'tax_exclusive') NOT NULL DEFAULT 'tax_exclusive',
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_financial_profiles_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_financial_profiles_country FOREIGN KEY (country_id) REFERENCES countries (id) ON DELETE SET NULL,
                CONSTRAINT fk_financial_profiles_jurisdiction FOREIGN KEY (tax_jurisdiction_id) REFERENCES tax_jurisdictions (id) ON DELETE SET NULL,
                CONSTRAINT fk_financial_profiles_currency FOREIGN KEY (base_currency_id) REFERENCES currencies (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS financial_profiles');
    }
};
