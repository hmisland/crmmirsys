<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * Contract REFERENCE on an expense (docs/project-finance-design.md, Stage 5.1).
 *
 * contract_id is informational only: no financial calculation reads it
 * (project costs, profit, margin, budget vs actual, revenue, contract billing,
 * installments, invoices, payments and reports all ignore it). Only
 * project_id allocates an expense to a project; a project is never derived
 * from the contract (Contract ↔ Project is N:N in project_links).
 *
 * No FK, like project_id: Contracts is an optional module; the contract is
 * validated through RecordTypeRegistry::find('contract') when set.
 * Purely additive: one nullable column and one index.
 */
return new class implements MigrationInterface {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE finance_expenses
                ADD COLUMN contract_id INT UNSIGNED NULL AFTER project_id,
                ADD KEY idx_finance_expenses_contract (organization_id, contract_id)'
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE finance_expenses
                DROP KEY idx_finance_expenses_contract,
                DROP COLUMN contract_id'
        );
    }
};
