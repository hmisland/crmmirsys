<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * This phase only: a payment is always recorded manually by an
 * internal staff member against an already-Issued invoice (cash,
 * bank transfer, card entered after the fact) — there is no customer
 * portal and no online gateway integration yet (see
 * docs/phase12-progress.md's "DEFERRED — Customer Portal" note).
 * 'gateway' and 'wallet' are left out of the ENUM for now; adding
 * them later is a migration, not a redesign, per the architecture's
 * explicit rule that payment_method must stay extensible.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE payments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                invoice_id INT UNSIGNED NOT NULL,
                amount DECIMAL(38,18) NOT NULL,
                payment_method ENUM('cash', 'bank_transfer', 'card', 'other') NOT NULL DEFAULT 'bank_transfer',
                payment_reference VARCHAR(100) NULL,
                payment_date DATE NOT NULL,
                recorded_by_user_id INT UNSIGNED NOT NULL,
                notes VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_payments_organization (organization_id),
                KEY idx_payments_invoice (invoice_id),
                CONSTRAINT fk_payments_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS payments');
    }
};
