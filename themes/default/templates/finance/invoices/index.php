<?php
/** @var array $invoices */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\help_link;
?>
<section class="card">
    <h1><?= e(t('invoices.title')) ?><?= help_link('finance-proformas-invoices') ?></h1>
    <div class="actions">
        <a href="/finance/invoices/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('invoices.new')) ?></a>
        <a href="/finance/invoices/tax-export" class="btn"><?= icon('download') ?><?= e(t('tax_export.title')) ?></a>
    </div>

    <form method="get" action="/finance/invoices" class="inline-form-row filter-bar">
        <input type="text" name="q" value="<?= e($filters['search']) ?>" placeholder="<?= e(t('filters.search_invoice')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
        <select name="status" aria-label="<?= e(t('deals.status')) ?>">
            <option value=""><?= e(t('filters.all_statuses')) ?></option>
            <?php foreach (['draft', 'issued', 'cancelled'] as $s): ?>
                <option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e(t('invoices.status.' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <label><?= e(t('filters.date_from')) ?></label>
        <input type="text" class="crm-datepicker" name="from" value="<?= e($filters['date_from']) ?>" aria-label="<?= e(t('filters.date_from')) ?>">
        <label><?= e(t('filters.date_to')) ?></label>
        <input type="text" class="crm-datepicker" name="to" value="<?= e($filters['date_to']) ?>" aria-label="<?= e(t('filters.date_to')) ?>">
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/finance/invoices" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../../partials/pagination.php'; ?>

    <?php $bulkAction = '/finance/invoices/bulk-delete'; include __DIR__ . '/../../partials/bulk-bar.php'; ?>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th class="cell-check"><input type="checkbox" data-bulk-all aria-label="<?= e(t('bulk.select_page')) ?>" title="<?= e(t('bulk.select_page')) ?>"></th><th><?= e(t('invoices.number')) ?></th><th><?= e(t('common.contact')) ?></th><th><?= e(t('wallet.date')) ?></th><th><?= e(t('deals.value')) ?></th><th><?= e(t('deals.status')) ?></th><th><?= e(t('proformas.owner')) ?></th><th><span class="visually-hidden"><?= e(t('contacts.row_actions')) ?></span></th></tr></thead>
        <tbody>
        <?php foreach ($invoices as $inv): ?>
            <?php $label = !empty($inv['custom_number']) ? $inv['custom_number'] : ($inv['invoice_number'] ?? ('#' . $inv['id'] . ' (' . t('invoices.not_issued_yet') . ')')); ?>
            <?php $actions = $rowActions[(int) $inv['id']] ?? ['edit' => false, 'delete' => false]; ?>
            <tr>
                <td class="cell-check"><input type="checkbox" name="ids[]" value="<?= e($inv['id']) ?>" form="bulk-form" data-bulk-item aria-label="<?= e(t('bulk.select_row')) ?>" <?= $actions['delete'] ? '' : 'disabled' ?>></td>
                <td><a href="/finance/invoices/<?= e($inv['id']) ?>"><?= e($label) ?></a></td>
                <td><?= e($inv['contact_name'] !== null ? trim($inv['contact_name']) : '—') ?></td>
                <td class="muted"><?= e($inv['issue_date']) ?></td>
                <td><?= e(\App\Infrastructure\Theme\money($inv['grand_total'], $inv['currency_code'])) ?> <?= e($inv['currency_code']) ?></td>
                <td><?= badge(t('invoices.status.' . $inv['status']), $inv['status']) ?></td>
                <td class="muted"><?= e(trim((string) $inv['owner_name'])) ?></td>
                <td class="actions-cell"><?php $editUrl = '/finance/invoices/' . $inv['id'] . '/edit'; $deleteUrl = '/finance/invoices/' . $inv['id'] . '/delete'; include __DIR__ . '/../../partials/row-actions.php'; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($invoices === []): ?>
            <tr><td colspan="8" class="muted"><?= e(t('invoices.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
