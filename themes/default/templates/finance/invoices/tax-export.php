<?php
/**
 * Invoices → CSV for the tax system: issued invoices to pick from.
 *
 * @var list<array> $invoices
 * @var array{search: string, date_from: string, date_to: string} $filters
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;
?>
<section class="card">
    <h1><?= e(t('tax_export.title')) ?></h1>
    <p class="field-hint"><?= e(t('tax_export.intro')) ?></p>
    <div class="actions">
        <a href="/finance/invoices" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('tax_export.back')) ?></a>
    </div>

    <form method="get" action="/finance/invoices/tax-export" class="inline-form-row filter-bar">
        <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="<?= e(t('filters.search_invoice')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <label><?= e(t('filters.date_from')) ?></label>
        <input type="text" class="crm-datepicker" name="date_from" value="<?= e($filters['date_from']) ?>" aria-label="<?= e(t('filters.date_from')) ?>">
        <label><?= e(t('filters.date_to')) ?></label>
        <input type="text" class="crm-datepicker" name="date_to" value="<?= e($filters['date_to']) ?>" aria-label="<?= e(t('filters.date_to')) ?>">
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
    </form>

    <form method="get" action="/finance/invoices/tax-export/download">
        <div class="table-responsive">
        <table class="table">
            <thead><tr>
                <th class="cell-check"><span class="visually-hidden"><?= e(t('bulk.select_row')) ?></span></th>
                <th><?= e(t('invoices.number')) ?></th><th><?= e(t('common.contact')) ?></th><th><?= e(t('wallet.date')) ?></th><th><?= e(t('deals.value')) ?></th>
            </tr></thead>
            <tbody>
            <?php if ($invoices === []): ?>
                <tr><td colspan="5" class="muted"><?= e(t('tax_export.empty')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td class="cell-check"><input type="checkbox" name="ids[]" value="<?= e($inv['id']) ?>" aria-label="<?= e(t('bulk.select_row')) ?>"></td>
                    <td><a href="/finance/invoices/<?= e($inv['id']) ?>"><span class="ltr"><?= e($inv['custom_number'] ?: $inv['invoice_number']) ?></span></a></td>
                    <td><?= e($inv['contact_name'] ?? '') ?></td>
                    <td><?= e($inv['issue_date']) ?></td>
                    <td><span class="ltr"><?= e(rtrim(rtrim((string) $inv['grand_total'], '0'), '.')) ?> <?= e($inv['currency_code'] ?? '') ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="actions">
            <button type="submit" class="btn btn--primary"><?= icon('download') ?><?= e(t('tax_export.download')) ?></button>
        </div>
    </form>
</section>
