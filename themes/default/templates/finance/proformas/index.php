<?php
/** @var array $proformas */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\help_link;
?>
<section class="card">
    <h1><?= e(t('proformas.title')) ?><?= help_link('finance-proformas-invoices') ?></h1>
    <div class="actions">
        <a href="/finance/proformas/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('proformas.new')) ?></a>
    </div>

    <form method="get" action="/finance/proformas" class="inline-form-row filter-bar">
        <input type="text" name="q" value="<?= e($filters['search']) ?>" placeholder="<?= e(t('filters.search_proforma')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
        <select name="status" aria-label="<?= e(t('deals.status')) ?>">
            <option value=""><?= e(t('filters.all_statuses')) ?></option>
            <?php foreach (['draft', 'sent', 'accepted', 'rejected', 'expired', 'converted'] as $s): ?>
                <option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e(t('proformas.status.' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <label><?= e(t('filters.date_from')) ?></label>
        <input type="text" class="crm-datepicker" name="from" value="<?= e($filters['date_from']) ?>" aria-label="<?= e(t('filters.date_from')) ?>">
        <label><?= e(t('filters.date_to')) ?></label>
        <input type="text" class="crm-datepicker" name="to" value="<?= e($filters['date_to']) ?>" aria-label="<?= e(t('filters.date_to')) ?>">
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/finance/proformas" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../../partials/pagination.php'; ?>

    <?php $bulkAction = '/finance/proformas/bulk-delete'; include __DIR__ . '/../../partials/bulk-bar.php'; ?>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th class="cell-check"><input type="checkbox" data-bulk-all aria-label="<?= e(t('bulk.select_page')) ?>" title="<?= e(t('bulk.select_page')) ?>"></th><th><?= e(t('proformas.number')) ?></th><th><?= e(t('common.contact')) ?></th><th><?= e(t('wallet.date')) ?></th><th><?= e(t('deals.value')) ?></th><th><?= e(t('deals.status')) ?></th><th><?= e(t('proformas.owner')) ?></th><th><span class="visually-hidden"><?= e(t('contacts.row_actions')) ?></span></th></tr></thead>
        <tbody>
        <?php foreach ($proformas as $p): ?>
            <?php $actions = $rowActions[(int) $p['id']] ?? ['edit' => false, 'delete' => false]; ?>
            <tr>
                <td class="cell-check"><input type="checkbox" name="ids[]" value="<?= e($p['id']) ?>" form="bulk-form" data-bulk-item aria-label="<?= e(t('bulk.select_row')) ?>" <?= $actions['delete'] ? '' : 'disabled' ?>></td>
                <td><a href="/finance/proformas/<?= e($p['id']) ?>"><?= !empty($p['custom_number']) ? e($p['custom_number']) : '#' . e($p['id']) ?></a></td>
                <td><?= e($p['contact_name'] !== null ? trim($p['contact_name']) : '—') ?></td>
                <td class="muted"><?= e($p['issue_date']) ?></td>
                <td><?= e(\App\Infrastructure\Theme\money($p['grand_total'], $p['currency_code'])) ?> <?= e($p['currency_code']) ?></td>
                <td><?= badge(t('proformas.status.' . $p['status']), $p['status']) ?></td>
                <td class="muted"><?= e(trim((string) $p['owner_name'])) ?></td>
                <td class="actions-cell"><?php $editUrl = '/finance/proformas/' . $p['id'] . '/edit'; $deleteUrl = '/finance/proformas/' . $p['id'] . '/delete'; include __DIR__ . '/../../partials/row-actions.php'; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($proformas === []): ?>
            <tr><td colspan="8" class="muted"><?= e(t('proformas.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
