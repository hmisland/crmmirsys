<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * A credit note never modifies the invoice it's against — grand_total
 * stays frozen forever, per the architecture's explicit immutability
 * rule. The invoice's "effective" total (what's really owed) is
 * computed at read time as grand_total - SUM(credit_notes.amount),
 * exactly the same "derived, never stored" pattern already used for
 * payment_status in 12.6, so it can never drift out of sync.
 *
 * Deliberately minimal for this sub-phase: one flat amount + reason
 * per credit note, no line-item breakdown — a full itemized credit
 * note is a natural future extension if ever needed, not built now.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE credit_notes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                invoice_id INT UNSIGNED NOT NULL,
                credit_note_number VARCHAR(50) NOT NULL,
                issue_date DATE NOT NULL,
                reason VARCHAR(255) NOT NULL,
                amount DECIMAL(38,18) NOT NULL,
                issued_by_user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_credit_notes_organization (organization_id),
                KEY idx_credit_notes_invoice (invoice_id),
                CONSTRAINT fk_credit_notes_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_credit_notes_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE credit_note_number_series (
                organization_id INT UNSIGNED PRIMARY KEY,
                prefix VARCHAR(20) NOT NULL DEFAULT 'CN-',
                next_sequence INT UNSIGNED NOT NULL DEFAULT 1,
                CONSTRAINT fk_credit_note_series_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS credit_note_number_series');
        $pdo->exec('DROP TABLE IF EXISTS credit_notes');
    }
};
