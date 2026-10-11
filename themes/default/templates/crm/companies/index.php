<?php
/** @var array $companies */
/** @var string $q */
/** @var \App\Infrastructure\Pagination\Pagination $pagination */
/** @var array $categories */
/** @var int $categoryId */
/** @var bool $canManageCategories */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$categoryNames = array_column($categories, 'name', 'id');
?>
<section class="card">
    <h1><?= e(t('companies.title')) ?></h1>

    <div class="actions">
        <a href="/companies/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('companies.new')) ?></a>
        <?php if ($canManageCategories): ?>
            <a href="/companies/categories" class="btn"><?= icon('tag') ?><?= e(t('companies.categories_title')) ?></a>
        <?php endif; ?>
    </div>

    <form method="get" action="/companies" class="inline-form-row filter-bar">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('companies.search_placeholder')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <?php if ($categories !== []): ?>
            <select name="category_id" aria-label="<?= e(t('companies.category')) ?>">
                <option value=""><?= e(t('companies.all_categories')) ?></option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= e($cat['id']) ?>"<?= $categoryId === (int) $cat['id'] ? ' selected' : '' ?>><?= e($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <label class="check"><input type="checkbox" name="mine" value="1" <?= ($mine ?? false) ? 'checked' : '' ?>> <?= e(t('filters.only_mine')) ?></label>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/companies" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../../partials/pagination.php'; ?>

    <?php $bulkAction = '/companies/bulk-delete'; include __DIR__ . '/../../partials/bulk-bar.php'; ?>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th class="cell-check"><input type="checkbox" data-bulk-all aria-label="<?= e(t('bulk.select_page')) ?>" title="<?= e(t('bulk.select_page')) ?>"></th><th><?= e(t('common.name')) ?></th><th><?= e(t('companies.category')) ?></th><th><?= e(t('companies.website')) ?></th><th><?= e(t('companies.industry')) ?></th><th><?= e(t('companies.city')) ?></th><th><span class="visually-hidden"><?= e(t('contacts.row_actions')) ?></span></th></tr></thead>
        <tbody>
        <?php foreach ($companies as $company): ?>
            <?php $actions = $rowActions[(int) $company['id']] ?? ['edit' => false, 'delete' => false]; ?>
            <tr>
                <td class="cell-check"><input type="checkbox" name="ids[]" value="<?= e($company['id']) ?>" form="bulk-form" data-bulk-item aria-label="<?= e(t('bulk.select_row')) ?>" <?= $actions['delete'] ? '' : 'disabled' ?>></td>
                <td><a href="/companies/<?= e($company['id']) ?>"><?= e($company['name']) ?></a><?php if (!empty($company['brand'])): ?><div class="muted text-sm"><?= e($company['brand']) ?></div><?php endif; ?></td>
                <td><?= e($categoryNames[(int) ($company['category_id'] ?? 0)] ?? '—') ?></td>
                <td><?= e($company['website'] ?? '—') ?></td>
                <td><?= e($company['industry'] ?? '—') ?></td>
                <td><?= e($company['city'] ?? '—') ?></td>
                <td class="actions-cell"><?php $editUrl = '/companies/' . $company['id'] . '/edit'; $deleteUrl = '/companies/' . $company['id'] . '/delete'; include __DIR__ . '/../../partials/row-actions.php'; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($companies === []): ?>
            <tr><td colspan="7" class="muted"><?= e(t('companies.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
