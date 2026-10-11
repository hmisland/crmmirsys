<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Optional final invoice rounding (Stage 5.2 finalization, L2/L3).
 *
 * - financial_profiles.invoice_final_rounding: the organization default,
 *   OFF (0) for every existing and new organization.
 * - invoices: the snapshot taken AT ISSUANCE, never recomputed later:
 *     final_rounding       1 = rounded, 0 = not rounded, NULL = draft or
 *                          issued before this feature (no decision recorded);
 *     rounding_precision   the target decimals used (IRR 0 = whole rial);
 *     unrounded_total      the grand total before the final rounding;
 *     rounding_adjustment  grand_total − unrounded_total (may be negative).
 *   grand_total itself holds the FINAL payable, so every existing consumer
 *   (payments, outstanding, credit notes, print, reports, profit) counts the
 *   adjustment exactly once without any change.
 *
 * Purely additive, no backfill: existing invoices keep their values and get
 * NULL / 0 snapshot columns. down() drops only the new columns (losing the
 * snapshot of invoices issued with rounding; their grand_total stays).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE financial_profiles ADD COLUMN invoice_final_rounding TINYINT(1) NOT NULL DEFAULT 0 AFTER pricing_mode');
        $pdo->exec(
            'ALTER TABLE invoices
                ADD COLUMN final_rounding TINYINT(1) NULL AFTER grand_total,
                ADD COLUMN rounding_precision TINYINT UNSIGNED NULL AFTER final_rounding,
                ADD COLUMN unrounded_total DECIMAL(38,18) NULL AFTER rounding_precision,
                ADD COLUMN rounding_adjustment DECIMAL(38,18) NOT NULL DEFAULT 0 AFTER unrounded_total'
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE invoices DROP COLUMN rounding_adjustment, DROP COLUMN unrounded_total, DROP COLUMN rounding_precision, DROP COLUMN final_rounding');
        $pdo->exec('ALTER TABLE financial_profiles DROP COLUMN invoice_final_rounding');
    }
};
