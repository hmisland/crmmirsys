<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Contract installment schedule (docs/project-finance-design.md, Stage 2).
 * Finance owns it: a planned amount + due date per contract, NOT a
 * financial posting — no revenue, receivable or payment comes from a row
 * here. Its status is derived from the linked proforma / invoice
 * (finance_document_links, linked_type = 'installment'); only `void` is
 * stored.
 *
 * Purely additive: no existing table is altered.
 * - linked_id has NO FK: Contracts may not be installed. The contract is
 *   validated through RecordTypeRegistry::find() on every write.
 * - UNIQUE (organization_id, linked_type, linked_id, sequence): a voided
 *   installment keeps its number; two concurrent "add" requests compute the
 *   same next number, so the second one fails here instead of slipping in
 *   (it also keeps the schedule currency rule race-free).
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE finance_installments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                linked_type VARCHAR(50) NOT NULL,
                linked_id INT UNSIGNED NOT NULL,
                sequence SMALLINT UNSIGNED NOT NULL,
                due_date DATE NOT NULL,
                amount DECIMAL(38,18) NOT NULL,
                currency_id INT UNSIGNED NOT NULL,
                description VARCHAR(255) NULL,
                voided_at DATETIME NULL,
                voided_by_user_id INT UNSIGNED NULL,
                void_reason VARCHAR(500) NULL,
                created_by_user_id INT UNSIGNED NULL,
                updated_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_finance_installments_sequence (organization_id, linked_type, linked_id, sequence),
                KEY idx_finance_installments_due (organization_id, linked_type, linked_id, due_date),
                CONSTRAINT fk_finance_installments_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_finance_installments_currency FOREIGN KEY (currency_id) REFERENCES currencies (id),
                CONSTRAINT fk_finance_installments_voided_by FOREIGN KEY (voided_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_finance_installments_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_finance_installments_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS finance_installments');
    }
};
