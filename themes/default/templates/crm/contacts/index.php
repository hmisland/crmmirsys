<?php
/** @var array $contacts */
/** @var string $q */
/** @var array $categories */
/** @var string $categoryId */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
?>
<section class="card">
    <h1><?= e(t('contacts.title')) ?></h1>

    <form method="get" action="/contacts" class="inline-form-row">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('contacts.search_placeholder')) ?>">
        <select name="category_id">
            <option value=""><?= e(t('contacts.all_categories')) ?></option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= e($cat['id']) ?>" <?= $categoryId === (string) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= e(t('common.search')) ?></button>
        <a href="/contacts/create" class="btn btn--primary"><?= e(t('contacts.new')) ?></a>
        <a href="/contacts/categories" class="btn"><?= e(t('contacts.categories_title')) ?></a>
        <a href="/contacts/import" class="btn"><?= e(t('common.import_csv')) ?></a>
        <a href="/contacts/export<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>" class="btn"><?= e(t('common.export_csv')) ?></a>
    </form>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('common.name')) ?></th><th><?= e(t('common.email')) ?></th><th><?= e(t('common.phone')) ?></th><th><?= e(t('common.company')) ?></th><th><?= e(t('contacts.category')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($contacts as $contact): ?>
            <tr>
                <td><a href="/contacts/<?= e($contact['id']) ?>"><?= e(trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''))) ?></a></td>
                <td><?= e($contact['email'] ?? '—') ?></td>
                <td><?= e($contact['phone'] ?? '—') ?></td>
                <td><?= e($contact['company_name'] ?? '—') ?></td>
                <td><?= e($contact['category_name'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($contacts === []): ?>
            <tr><td colspan="5" class="muted"><?= e(t('contacts.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
