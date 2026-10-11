<?php
/**
 * Expenses list (Phase 4). Rows are already restricted to the user's
 * finance.expenses.view scope. Amounts per row in the row's own currency;
 * no cross-currency total.
 *
 * @var array $expenses
 * @var \App\Infrastructure\Pagination\Pagination $pagination
 * @var array $filters
 * @var array<int, string> $codes
 * @var array $currencies active currencies (filter)
 * @var bool $canCreate
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;

$money = fn ($amount, ?string $code = null): string => \App\Infrastructure\Theme\money($amount, $code); // currency display policy (L2)
?>
<section class="card">
    <h1><?= e(t('finance.expense.title')) ?></h1>
    <?php if ($canCreate): ?>
    <div class="actions">
        <a href="/finance/expenses/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('finance.expense.new')) ?></a>
    </div>
    <?php endif; ?>

    <form method="get" action="/finance/expenses" class="inline-form-row filter-bar">
        <select name="category" aria-label="<?= e(t('finance.expense.category')) ?>">
            <option value=""><?= e(t('finance.expense.all_categories')) ?></option>
            <?php foreach (\Modules\Finance\Services\ExpenseService::CATEGORIES as $c): ?>
                <option value="<?= e($c) ?>" <?= $filters['category'] === $c ? 'selected' : '' ?>><?= e(t('finance.expense.category.' . $c)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" aria-label="<?= e(t('deals.status')) ?>">
            <option value=""><?= e(t('filters.all_statuses')) ?></option>
            <?php foreach (['recorded', 'void'] as $s): ?>
                <option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e(t('finance.expense.status.' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="currency_id" aria-label="<?= e(t('finance.expense.currency')) ?>">
            <option value=""><?= e(t('finance.expense.all_currencies')) ?></option>
            <?php foreach ($currencies as $cur): ?>
                <option value="<?= e($cur['id']) ?>" <?= (string) $filters['currency_id'] === (string) $cur['id'] ? 'selected' : '' ?>><?= e($cur['code']) ?></option>
            <?php endforeach; ?>
        </select>
        <label><?= e(t('filters.date_from')) ?></label>
        <input type="text" class="crm-datepicker" name="from" value="<?= e($filters['date_from']) ?>" aria-label="<?= e(t('filters.date_from')) ?>">
        <label><?= e(t('filters.date_to')) ?></label>
        <input type="text" class="crm-datepicker" name="to" value="<?= e($filters['date_to']) ?>" aria-label="<?= e(t('filters.date_to')) ?>">
        <?php if ($filters['project_id'] !== ''): ?><input type="hidden" name="project_id" value="<?= e($filters['project_id']) ?>"><?php endif; ?>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/finance/expenses" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../../partials/pagination.php'; ?>

    <div class="table-responsive">
    <table class="table">
        <thead><tr>
            <th><?= e(t('finance.expense.date')) ?></th>
            <th><?= e(t('finance.expense.category')) ?></th>
            <th><?= e(t('finance.expense.description')) ?></th>
            <th class="num"><?= e(t('finance.expense.amount')) ?></th>
            <th><?= e(t('deals.status')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($expenses as $x): ?>
            <?php $isCorrection = $x['entry_type'] === 'correction'; ?>
            <tr>
                <td class="nowrap"><?= e($x['expense_date']) ?></td>
                <td><?= e(t('finance.expense.category.' . $x['category'])) ?></td>
                <td>
                    <a href="/finance/expenses/<?= e($x['id']) ?>"><?= e($x['description']) ?></a>
                    <?php if ($isCorrection): ?> <?= badge(t('finance.expense.correction'), 'info') ?><?php endif; ?>
                </td>
                <td class="num nowrap"><?= e(($isCorrection && $x['correction_direction'] === 'decrease' ? '−' : '') . $money($x['amount'], $codes[(int) $x['currency_id']] ?? null)) ?> <span class="ltr"><?= e($codes[(int) $x['currency_id']] ?? '') ?></span></td>
                <td><?= badge(t('finance.expense.status.' . $x['status']), $x['status'] === 'void' ? 'cancelled' : 'active') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($expenses === []): ?>
            <tr><td colspan="5" class="muted"><?= e(t('finance.expense.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    <p class="field-hint"><?= e(t('finance.expense.list_hint')) ?></p>
</section>
