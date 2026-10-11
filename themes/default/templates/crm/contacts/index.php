<?php
/** @var array $contacts */
/** @var string $q */
/** @var array $categories */
/** @var string $categoryId */
/** @var array<int, array{edit: bool, delete: bool}> $rowActions */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$rowActions = $rowActions ?? [];
?>
<section class="card">
    <h1><?= e(t('contacts.title')) ?></h1>

    <div class="actions">
        <a href="/contacts/create" class="btn btn--primary"><?= icon('user-plus') ?><?= e(t('contacts.new')) ?></a>
        <a href="/contacts/categories" class="btn"><?= icon('tag') ?><?= e(t('contacts.categories_title')) ?></a>
        <?php if ($canImport ?? false): ?>
            <a href="/contacts/import" class="btn"><?= icon('upload') ?><?= e(t('common.import_csv')) ?></a>
        <?php endif; ?>
        <?php if ($canExport ?? false): ?>
            <a href="/contacts/export<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>" class="btn"><?= icon('download') ?><?= e(t('common.export_csv')) ?></a>
        <?php endif; ?>
    </div>

    <form method="get" action="/contacts" class="inline-form-row filter-bar">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('contacts.search_placeholder')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <select aria-label="<?= e(t('contacts.category')) ?>" name="category_id">
            <option value=""><?= e(t('contacts.all_categories')) ?></option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= e($cat['id']) ?>" <?= $categoryId === (string) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="check"><input type="checkbox" name="mine" value="1" <?= ($mine ?? false) ? 'checked' : '' ?>> <?= e(t('filters.only_mine')) ?></label>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($q !== '' || $categoryId !== '' || ($mine ?? false)): ?>
            <a href="/contacts" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a>
        <?php endif; ?>
    </form>

    <?php include __DIR__ . '/../../partials/pagination.php'; ?>

    <?php $bulkAction = '/contacts/bulk-delete'; include __DIR__ . '/../../partials/bulk-bar.php'; ?>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th class="cell-check"><input type="checkbox" data-bulk-all aria-label="<?= e(t('bulk.select_page')) ?>" title="<?= e(t('bulk.select_page')) ?>"></th><th><?= e(t('common.name')) ?></th><th><?= e(t('common.email')) ?></th><th><?= e(t('common.phone')) ?></th><th><?= e(t('common.company')) ?></th><th><?= e(t('contacts.category')) ?></th><th><span class="visually-hidden"><?= e(t('contacts.row_actions')) ?></span></th></tr></thead>
        <tbody>
        <?php foreach ($contacts as $contact): ?>
            <?php $actions = $rowActions[(int) $contact['id']] ?? ['edit' => false, 'delete' => false]; ?>
            <tr>
                <td class="cell-check"><input type="checkbox" name="ids[]" value="<?= e($contact['id']) ?>" form="bulk-form" data-bulk-item aria-label="<?= e(t('bulk.select_row')) ?>" <?= $actions['delete'] ? '' : 'disabled' ?>></td>
                <td><a href="/contacts/<?= e($contact['id']) ?>"><?= e(trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''))) ?></a></td>
                <td><span class="ltr"><?= e($contact['email'] ?? '—') ?></span></td>
                <td><span class="ltr"><?= e($contact['phone'] ?? '—') ?></span></td>
                <td><?= e($contact['company_name'] ?? '—') ?></td>
                <td><?= e($contact['category_name'] ?? '—') ?></td>
                <td class="actions-cell">
                    <?php if ($actions['edit']): ?>
                        <a href="/contacts/<?= e($contact['id']) ?>/edit" class="btn btn--sm"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
                    <?php else: ?>
                        <span data-tooltip="<?= e(t('contacts.not_your_contact')) ?>"><button type="button" class="btn btn--sm" disabled><?= icon('pencil') ?><?= e(t('common.edit')) ?></button></span>
                    <?php endif; ?>
                    <?php if ($actions['delete']): ?>
                        <form method="post" action="/contacts/<?= e($contact['id']) ?>/delete" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--sm btn--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                        </form>
                    <?php else: ?>
                        <span data-tooltip="<?= e(t('contacts.not_your_contact')) ?>"><button type="button" class="btn btn--sm btn--danger" disabled><?= icon('trash') ?><?= e(t('common.delete')) ?></button></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($contacts === []): ?>
            <tr><td colspan="7" class="muted"><?= e(t('contacts.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
