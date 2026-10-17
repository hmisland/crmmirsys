<?php
/**
 * Body of the "Profitability" panel on a project page (Stage 5, order 48).
 * Per currency only — no FX, never a cross-currency total or margin. Rendered
 * only for users who pass all four visibility conditions (security decision
 * 9); nobody ever sees a partial profit. Data: ProjectProfitService::profitFor().
 *
 * Revenue here EXCLUDES tax (credit notes reduce it in each invoice's own
 * net / gross proportion), so it differs from "Revenue (incl. tax)" in the
 * Project revenue panel — the hint below says so.
 *
 * @var array<int, array{revenue: string, cost: string, profit: string, margin: ?string}> $profit
 * @var array<int, string> $codes currency id => code
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\help_link;

$money = fn (string $amount, ?string $code = null): string => \App\Infrastructure\Theme\money($amount, $code, $fmt); // currency display policy (L2)
?>
<div class="actions"><?= help_link('finance-project-finance') ?></div>
<div class="table-responsive">
<table class="table table--compact">
    <thead><tr>
        <th><?= e(t('finance.profit.currency')) ?></th>
        <th class="num"><?= e(t('finance.profit.revenue')) ?></th>
        <th class="num"><?= e(t('finance.profit.cost')) ?></th>
        <th class="num"><?= e(t('finance.profit.profit')) ?></th>
        <th class="num"><?= e(t('finance.profit.margin')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($profit as $currencyId => $p): ?>
        <tr>
            <td class="ltr"><?= e($codes[(int) $currencyId] ?? '') ?></td>
            <td class="num nowrap"><?= e($money($p['revenue'], $codes[(int) $currencyId] ?? null)) ?></td>
            <td class="num nowrap"><?= e($money($p['cost'], $codes[(int) $currencyId] ?? null)) ?></td>
            <td class="num nowrap"><?= e($money($p['profit'], $codes[(int) $currencyId] ?? null)) ?></td>
            <td class="num nowrap"><?= e($p['margin'] === null ? '—' : $fmt->number($p['margin'], 1) . '%') ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($profit === []): ?>
        <tr><td colspan="5" class="muted"><?= e(t('finance.profit.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<p class="field-hint"><?= e(t('finance.profit.hint')) ?></p>
