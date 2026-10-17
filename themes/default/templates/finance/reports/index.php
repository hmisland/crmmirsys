<?php
/**
 * Period performance report (remediation P1, decision D1 = A): invoices
 * ISSUED in the period, one row per invoice currency — amounts in different
 * currencies are never added together (no FX). Credited / paid / outstanding
 * are those invoices' own figures as of today, not a balance at the period end.
 *
 * @var array{start_date: string, end_date: string, invoice_count: int, currencies: array<int, array>} $summary
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\help_link;

$money = fn (string $amount, ?string $code = null): string => \App\Infrastructure\Theme\money($amount, $code, $fmt); // currency display policy (L2)
?>
<section class="card">
    <h1><?= e(t('reports.title')) ?><?= help_link('finance-reports') ?></h1>

    <form method="get" action="/finance/reports" class="inline-form-row">
        <label for="start_date" style="margin:0"><?= e(t('reports.from')) ?></label>
        <input type="text" class="crm-datepicker" id="start_date" name="start_date" value="<?= e($summary['start_date']) ?>">
        <label for="end_date" style="margin:0"><?= e(t('reports.to')) ?></label>
        <input type="text" class="crm-datepicker" id="end_date" name="end_date" value="<?= e($summary['end_date']) ?>">
        <button type="submit" class="btn btn--primary"><?= icon('filter') ?><?= e(t('reports.apply')) ?></button>
    </form>
</section>

<div class="dashboard-grid">
    <section class="card dashboard-tile">
        <h2><?= e(t('reports.invoice_count')) ?></h2>
        <p class="dashboard-tile__number"><?= e($fmt->number((string) $summary['invoice_count'])) ?></p>
    </section>
</div>

<section class="card">
    <div class="table-responsive">
    <table class="table table--compact">
        <thead><tr>
            <th><?= e(t('reports.currency')) ?></th>
            <th class="num"><?= e(t('reports.invoice_count')) ?></th>
            <th class="num"><?= e(t('reports.gross_revenue')) ?></th>
            <th class="num"><?= e(t('credit_notes.total_credited')) ?></th>
            <th class="num"><?= e(t('reports.net_revenue')) ?></th>
            <th class="num"><?= e(t('proformas.tax_total')) ?></th>
            <th class="num"><?= e(t('reports.total_paid')) ?></th>
            <th class="num"><?= e(t('reports.outstanding_today')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($summary['currencies'] as $row): ?>
            <tr>
                <td class="ltr"><?= e($row['currency_code']) ?></td>
                <td class="num"><?= e($fmt->number((string) $row['invoice_count'])) ?></td>
                <td class="num nowrap"><?= e($money($row['gross_revenue'], $row['currency_code'])) ?></td>
                <td class="num nowrap"><?= e($money($row['total_credited'], $row['currency_code'])) ?></td>
                <td class="num nowrap"><?= e($money($row['net_revenue'], $row['currency_code'])) ?></td>
                <td class="num nowrap"><?= e($money($row['total_tax'], $row['currency_code'])) ?></td>
                <td class="num nowrap"><?= e($money($row['total_paid'], $row['currency_code'])) ?></td>
                <td class="num nowrap"><?= e($money($row['outstanding'], $row['currency_code'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($summary['currencies'] === []): ?>
            <tr><td colspan="8" class="muted"><?= e(t('reports.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    <p class="field-hint"><?= icon('info') ?> <?= e(t('reports.period_basis')) ?></p>
</section>
