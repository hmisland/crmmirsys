<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Project expense budgets (docs/project-finance-design.md, Stage 5).
 *
 * One row per project × expense category × currency: the planned COST
 * (expense budget only — no planned revenue, no committed cost, no
 * versioning). Amounts in different currencies are never added together.
 *
 * - project_id: no FK, as finance_expenses (Stage 4): Projects may be absent;
 *   the project is validated through RecordTypeRegistry::find('project').
 * - category: the same six values as finance_expenses.category (Stage 4 E5).
 * - amount ≥ 0 (zero allowed), checked by the service and by a CHECK
 *   constraint where the server enforces it.
 * - UNIQUE (organization_id, project_id, category, currency_id): one line per
 *   category × currency.
 * Purely additive: no existing table is altered.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE finance_budget_lines (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id INT UNSIGNED NOT NULL,
                project_id INT UNSIGNED NOT NULL,
                category ENUM('labor', 'procurement', 'contractor', 'shipping', 'services', 'other') NOT NULL,
                currency_id INT UNSIGNED NOT NULL,
                amount DECIMAL(38,18) NOT NULL,
                notes VARCHAR(255) NULL,
                created_by_user_id INT UNSIGNED NULL,
                updated_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_finance_budget_lines_line (organization_id, project_id, category, currency_id),
                KEY idx_finance_budget_lines_project (organization_id, project_id),
                CONSTRAINT chk_finance_budget_lines_amount CHECK (amount >= 0),
                CONSTRAINT fk_finance_budget_lines_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_finance_budget_lines_currency FOREIGN KEY (currency_id) REFERENCES currencies (id),
                CONSTRAINT fk_finance_budget_lines_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_finance_budget_lines_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS finance_budget_lines');
    }
};
