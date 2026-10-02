<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Correction to 0001: a financial profile isn't always a company
 * (حقوقی/legal entity) — it can be a natural person / sole proprietor
 * (حقیقی). `legal_name` stays for legal entities; `first_name`/
 * `last_name` are added for natural persons. `tax_identification_number`
 * is kept as one shared column either way — a legal entity's
 * tax/economic code or a natural person's national ID — exactly the
 * "same column, different UI label per context" pattern already used
 * for jurisdiction-specific identifier labels in the architecture.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec("ALTER TABLE financial_profiles ADD COLUMN entity_type ENUM('legal', 'natural') NOT NULL DEFAULT 'legal' AFTER organization_id");
        $pdo->exec('ALTER TABLE financial_profiles ADD COLUMN first_name VARCHAR(100) NULL AFTER legal_name');
        $pdo->exec('ALTER TABLE financial_profiles ADD COLUMN last_name VARCHAR(100) NULL AFTER first_name');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE financial_profiles DROP COLUMN entity_type');
        $pdo->exec('ALTER TABLE financial_profiles DROP COLUMN first_name');
        $pdo->exec('ALTER TABLE financial_profiles DROP COLUMN last_name');
    }
};
