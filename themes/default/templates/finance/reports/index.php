<?php
/** @var array $summary */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
?>
<section class="card">
    <h1><?= e(t('reports.title')) ?></h1>

    <form method="get" action="/finance/reports" class="inline-form-row">
        <label for="start_date" style="margin:0"><?= e(t('reports.from')) ?></label>
        <input type="text" class="crm-datepicker" id="start_date" name="start_date" value="<?= e($summary['start_date']) ?>">
        <label for="end_date" style="margin:0"><?= e(t('reports.to')) ?></label>
        <input type="text" class="crm-datepicker" id="end_date" name="end_date" value="<?= e($summary['end_date']) ?>">
        <button type="submit" class="btn btn--primary"><?= e(t('reports.apply')) ?></button>
    </form>
</section>

<div class="dashboard-grid">
    <section class="card dashboard-tile">
        <h2><?= e(t('reports.invoice_count')) ?></h2>
        <p class="dashboard-tile__number"><?= e(number_format($summary['invoice_count'])) ?></p>
    </section>

    <section class="card dashboard-tile">
        <h2><?= e(t('reports.gross_revenue')) ?></h2>
        <p class="dashboard-tile__number"><?= e(number_format((float) $summary['gross_revenue'], 0)) ?></p>
    </section>

    <section class="card dashboard-tile">
        <h2><?= e(t('credit_notes.total_credited')) ?></h2>
        <p class="dashboard-tile__number"><?= e(number_format((float) $summary['total_credited'], 0)) ?></p>
    </section>

    <section class="card dashboard-tile">
        <h2><?= e(t('reports.net_revenue')) ?></h2>
        <p class="dashboard-tile__number"><?= e(number_format((float) $summary['net_revenue'], 0)) ?></p>
    </section>

    <section class="card dashboard-tile">
        <h2><?= e(t('proformas.tax_total')) ?></h2>
        <p class="dashboard-tile__number"><?= e(number_format((float) $summary['total_tax'], 0)) ?></p>
    </section>

    <section class="card dashboard-tile">
        <h2><?= e(t('reports.total_paid')) ?></h2>
        <p class="dashboard-tile__number"><?= e(number_format((float) $summary['total_paid'], 0)) ?></p>
    </section>

    <section class="card dashboard-tile">
        <h2><?= e(t('payments.outstanding')) ?></h2>
        <p class="dashboard-tile__number"><?= e(number_format((float) $summary['outstanding'], 0)) ?></p>
    </section>
</div>
<p class="muted"><?= e(t('reports.currency_note')) ?></p>
