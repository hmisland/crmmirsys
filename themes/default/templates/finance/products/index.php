<?php
/** @var array $products */
/** @var string $q */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
?>
<section class="card">
    <h1><?= e(t('products.title')) ?></h1>

    <div class="actions">
        <a href="/finance/products/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('products.new')) ?></a>
    </div>

    <form method="get" action="/finance/products" class="inline-form-row filter-bar">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('products.search_placeholder')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/finance/products" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../../partials/pagination.php'; ?>

    <?php $bulkAction = '/finance/products/bulk-delete'; include __DIR__ . '/../../partials/bulk-bar.php'; ?>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th class="cell-check"><input type="checkbox" data-bulk-all aria-label="<?= e(t('bulk.select_page')) ?>" title="<?= e(t('bulk.select_page')) ?>"></th><th><?= e(t('products.sku')) ?></th><th><?= e(t('common.name')) ?></th><th><?= e(t('products.price')) ?></th><th><?= e(t('deals.status')) ?></th><th><span class="visually-hidden"><?= e(t('contacts.row_actions')) ?></span></th></tr></thead>
        <tbody>
        <?php foreach ($products as $p): ?>
            <?php $actions = $rowActions[(int) $p['id']] ?? ['edit' => false, 'delete' => false]; ?>
            <tr>
                <td class="cell-check"><input type="checkbox" name="ids[]" value="<?= e($p['id']) ?>" form="bulk-form" data-bulk-item aria-label="<?= e(t('bulk.select_row')) ?>" <?= $actions['delete'] ? '' : 'disabled' ?>></td>
                <td class="muted"><?= e($p['sku'] ?? '—') ?></td>
                <td><a href="/finance/products/<?= e($p['id']) ?>/edit"><?= e($p['name']) ?></a></td>
                <td><?= e(\App\Infrastructure\Theme\money($p['price'], $p['currency_code'])) ?> <?= e($p['currency_code']) ?></td>
                <td><?= $p['is_active'] ? e(t('products.active')) : e(t('products.inactive')) ?></td>
                <td class="actions-cell"><?php $editUrl = '/finance/products/' . $p['id'] . '/edit'; $deleteUrl = '/finance/products/' . $p['id'] . '/delete'; include __DIR__ . '/../../partials/row-actions.php'; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($products === []): ?>
            <tr><td colspan="6" class="muted"><?= e(t('products.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
