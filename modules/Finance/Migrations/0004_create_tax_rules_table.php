<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Per the architecture's explicit rule: a rate change is always a NEW
 * row with its own effective_from, never an UPDATE of an existing
 * rule — so an invoice issued before a rate change keeps using the
 * rule it was calculated with.
 *
 * Seeded rate verified via web search at the time of this migration:
 * Iran's standard VAT (مالیات بر ارزش‌افزوده) is 10%, effective from
 * the 1404 budget law (raised from 9%), per multiple Iranian tax/
 * accounting sources, all converging on 10% for 1404. Effective date
 * set to the start of the Iranian year 1404 (2025-03-21 Gregorian).
 * This is real seed data, not a hardcoded value baked into any
 * Service's logic — it is a normal, editable row like any other, and
 * a Super Admin can add a new effective-dated row the same way
 * whenever the rate next changes.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE tax_rules (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tax_jurisdiction_id INT UNSIGNED NOT NULL,
                tax_category_id INT UNSIGNED NULL,
                name VARCHAR(100) NOT NULL,
                rate_type ENUM('fixed', 'percentage', 'zero', 'exempt') NOT NULL DEFAULT 'percentage',
                rate DECIMAL(10,4) NOT NULL DEFAULT 0,
                effective_from DATE NOT NULL,
                effective_to DATE NULL,
                KEY idx_tax_rules_jurisdiction (tax_jurisdiction_id, effective_from),
                CONSTRAINT fk_tax_rules_jurisdiction FOREIGN KEY (tax_jurisdiction_id) REFERENCES tax_jurisdictions (id) ON DELETE CASCADE,
                CONSTRAINT fk_tax_rules_category FOREIGN KEY (tax_category_id) REFERENCES tax_categories (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $jurisdictionId = $pdo->query("SELECT tj.id FROM tax_jurisdictions tj
            JOIN countries c ON c.id = tj.country_id WHERE c.iso_code = 'IRN' LIMIT 1")->fetchColumn();

        if ($jurisdictionId !== false) {
            $stmt = $pdo->prepare(
                "INSERT INTO tax_rules (tax_jurisdiction_id, name, rate_type, rate, effective_from)
                 VALUES (:jid, 'Standard VAT (Iran) — 10%', 'percentage', 10.0000, '2025-03-21')"
            );
            $stmt->execute(['jid' => $jurisdictionId]);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS tax_rules');
    }
};
