<?php
/**
 * Body of the "Project costs" panel on a project page (Phase 4, E10) —
 * separate from the Stage 3-B revenue panel. Net actual cost of the expenses
 * this user may view, per currency only (no cross-currency total, no FX, no
 * profit or margin); restricted expenses are neither listed nor summed.
 * Data: ExpenseService::projectPanel().
 *
 * @var array<int, string> $totals currency id => net actual cost
 * @var array $recent latest originals
 * @var bool $canCreate "New expense" (D-P1) only when the server would accept it
 * @var int $projectId
 * @var array<int, string> $codes
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\help_link;

$money = fn ($amount, ?string $code = null): string => \App\Infrastructure\Theme\money($amount, $code); // currency display policy (L2)
?>
<div class="actions">
<?php if ($canCreate): ?>
    <a href="/finance/expenses/create?from_project=<?= e($projectId) ?>" class="btn btn--sm"><?= icon('plus') ?><?= e(t('finance.expense.new')) ?></a>
<?php endif; ?>
    <?= help_link('finance-expenses') ?>
</div>
<dl class="fact-row">
    <?php if ($totals === []): ?>
        <div class="fact"><dt><?= icon('coins') ?><?= e(t('finance.expense.actual_cost')) ?></dt><dd>—</dd></div>
    <?php endif; ?>
    <?php foreach ($totals as $currencyId => $total): ?>
        <div class="fact"><dt><?= icon('coins') ?><?= e(t('finance.expense.actual_cost')) ?></dt><dd><?= e($money($total, $codes[(int) $currencyId] ?? null)) ?> <span class="ltr"><?= e($codes[(int) $currencyId] ?? '') ?></span></dd></div>
    <?php endforeach; ?>
</dl>
<div class="table-responsive">
<table class="table table--compact">
    <thead><tr>
        <th><?= e(t('finance.expense.date')) ?></th>
        <th><?= e(t('finance.expense.category')) ?></th>
        <th><?= e(t('finance.expense.description')) ?></th>
        <th class="num"><?= e(t('finance.expense.amount')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($recent as $x): ?>
        <tr>
            <td class="nowrap"><?= e($x['expense_date']) ?></td>
            <td><?= e(t('finance.expense.category.' . $x['category'])) ?></td>
            <td><a href="/finance/expenses/<?= e($x['id']) ?>"><?= e($x['description']) ?></a></td>
            <td class="num nowrap"><?= e($money($x['amount'], $codes[(int) $x['currency_id']] ?? null)) ?> <span class="ltr"><?= e($codes[(int) $x['currency_id']] ?? '') ?></span></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($recent === []): ?>
        <tr><td colspan="4" class="muted"><?= e(t('finance.expense.project_empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<p class="field-hint"><?= e(t('finance.expense.project_hint')) ?> <a href="/finance/expenses?project_id=<?= e($projectId) ?>"><?= e(t('finance.expense.view_all')) ?></a></p>
