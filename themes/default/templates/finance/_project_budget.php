<?php
/**
 * Body of the "Budget" panel on a project page (Stage 5, order 47). Expense
 * budget vs actual, per currency × category — never a cross-currency total,
 * no FX. Actual = Stage 4 net actual cost of the expenses this user may view
 * (only with finance.expenses.view). Where no budget line exists, Remaining /
 * Variance / Variance % are "—". Data: BudgetService::panel().
 *
 * @var array<int, array{rows: array, total: array}> $currencies currency id => rows + currency total
 * @var bool $hasBudget
 * @var bool $showActual
 * @var bool $canEdit "Define / Edit budget" only when the server would accept it
 * @var bool $readOnly completed / cancelled project
 * @var int $projectId
 * @var array<int, string> $codes currency id => code
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\help_link;

$money = function (?string $amount, ?string $code = null) use ($fmt): string {
    if ($amount === null) {
        return '—';
    }

    return \App\Infrastructure\Theme\money($amount, $code, $fmt); // currency display policy (L2)
};
$pct = fn (?string $value): string => $value === null ? '—' : $fmt->number($value, 1) . '%';
$columns = $showActual ? 7 : 3;
?>
<div class="actions">
<?php if ($canEdit): ?>
    <a href="/finance/projects/<?= e($projectId) ?>/budget" class="btn btn--sm"><?= icon($hasBudget ? 'pencil' : 'plus') ?><?= e(t($hasBudget ? 'finance.budget.edit' : 'finance.budget.define')) ?></a>
<?php endif; ?>
    <?= help_link('finance-project-finance') ?>
</div>
<?php if (!$hasBudget): ?>
    <p class="muted"><?= e(t('finance.budget.none')) ?></p>
<?php endif; ?>
<?php if ($readOnly): ?>
    <p class="field-hint"><?= icon('lock') ?> <?= e(t('finance.budget.read_only')) ?></p>
<?php endif; ?>
<div class="table-responsive">
<table class="table table--compact">
    <thead><tr>
        <th><?= e(t('finance.budget.currency')) ?></th>
        <th><?= e(t('finance.budget.category')) ?></th>
        <th class="num"><?= e(t('finance.budget.budget')) ?></th>
        <?php if ($showActual): ?>
            <th class="num"><?= e(t('finance.budget.actual')) ?></th>
            <th class="num"><?= e(t('finance.budget.remaining')) ?></th>
            <th class="num"><?= e(t('finance.budget.variance')) ?></th>
            <th class="num"><?= e(t('finance.budget.variance_pct')) ?></th>
        <?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($currencies as $currencyId => $block): ?>
        <?php foreach ([...$block['rows'], $block['total']] as $row): ?>
            <tr>
                <td class="ltr"><?= e($codes[(int) $currencyId] ?? '') ?></td>
                <td><?= $row['category'] === null ? '<strong>' . e(t('finance.budget.total')) . '</strong>' : e(t('finance.expense.category.' . $row['category'])) ?></td>
                <td class="num nowrap"><?= e($money($row['budget'], $codes[(int) $currencyId] ?? null)) ?></td>
                <?php if ($showActual): ?>
                    <td class="num nowrap"><?= e($money($row['actual'], $codes[(int) $currencyId] ?? null)) ?></td>
                    <td class="num nowrap"><?= e($money($row['remaining'], $codes[(int) $currencyId] ?? null)) ?></td>
                    <td class="num nowrap"><?= e($money($row['variance'], $codes[(int) $currencyId] ?? null)) ?></td>
                    <td class="num nowrap"><?= e($pct($row['variance_pct'])) ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    <?php if ($currencies === []): ?>
        <tr><td colspan="<?= e($columns) ?>" class="muted"><?= e(t('finance.budget.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<p class="field-hint"><?= e(t($showActual ? 'finance.budget.hint' : 'finance.budget.hint_no_actual')) ?></p>
