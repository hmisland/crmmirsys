<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Expenses (docs/project-finance-design.md, Phase 4 — E1…E12, D-P1…D-P3).
 *
 * An expense is an ACTUAL cost to the company: one positive amount that
 * already excludes recoverable tax (D4 / E3). It never feeds revenue,
 * payments, credit notes, installments or contract figures.
 *
 * - project_id: the canonical (and only) project attribution — at most one
 *   project per expense by construction (E1). No FK: Projects may be absent;
 *   validated through RecordTypeRegistry::find('project') when set.
 * - Corrections (E8): a separate row with entry_type = 'correction',
 *   correction_of_id = the ORIGINAL expense and correction_direction =
 *   increase | decrease; amount stays a positive magnitude. The service keeps
 *   entry_type = 'expense' ⇔ correction_of_id IS NULL ⇔ correction_direction
 *   IS NULL, and the net of an original (original ± its recorded corrections)
 *   never below zero.
 * - Payee (E4): a contact OR a company of the organization, never both.
 * - No physical delete: status recorded → void, with a reason.
 * Purely additive: no existing table is altered.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE finance_expenses (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                entry_type ENUM('expense', 'correction') NOT NULL DEFAULT 'expense',
                correction_of_id INT UNSIGNED NULL,
                correction_direction ENUM('increase', 'decrease') NULL,
                category ENUM('labor', 'procurement', 'contractor', 'shipping', 'services', 'other') NOT NULL,
                description VARCHAR(255) NOT NULL,
                reference_number VARCHAR(100) NULL,
                expense_date DATE NOT NULL,
                amount DECIMAL(38,18) NOT NULL,
                currency_id INT UNSIGNED NOT NULL,
                project_id INT UNSIGNED NULL,
                payee_contact_id INT UNSIGNED NULL,
                payee_company_id INT UNSIGNED NULL,
                owner_id INT UNSIGNED NOT NULL,
                team_id INT UNSIGNED NULL,
                department_id INT UNSIGNED NULL,
                status ENUM('recorded', 'void') NOT NULL DEFAULT 'recorded',
                voided_at DATETIME NULL,
                voided_by_user_id INT UNSIGNED NULL,
                void_reason VARCHAR(500) NULL,
                created_by_user_id INT UNSIGNED NULL,
                updated_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_finance_expenses_date (organization_id, expense_date),
                KEY idx_finance_expenses_project (organization_id, project_id, status),
                KEY idx_finance_expenses_category (organization_id, status, category),
                KEY idx_finance_expenses_correction (organization_id, correction_of_id),
                CONSTRAINT fk_finance_expenses_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_finance_expenses_correction FOREIGN KEY (correction_of_id) REFERENCES finance_expenses (id) ON DELETE RESTRICT,
                CONSTRAINT fk_finance_expenses_currency FOREIGN KEY (currency_id) REFERENCES currencies (id),
                CONSTRAINT fk_finance_expenses_payee_contact FOREIGN KEY (payee_contact_id) REFERENCES contacts (id) ON DELETE SET NULL,
                CONSTRAINT fk_finance_expenses_payee_company FOREIGN KEY (payee_company_id) REFERENCES companies (id) ON DELETE SET NULL,
                CONSTRAINT fk_finance_expenses_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_finance_expenses_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL,
                CONSTRAINT fk_finance_expenses_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL,
                CONSTRAINT fk_finance_expenses_voided_by FOREIGN KEY (voided_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_finance_expenses_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_finance_expenses_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS finance_expenses');
    }
};
