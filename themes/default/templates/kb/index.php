<?php
/** @var array $articles */
/** @var array $categories */
/** @var string $q */
/** @var string $categoryId */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
?>
<section class="card">
    <h1><?= e(t('kb.title')) ?></h1>

    <div class="actions">
        <a href="/kb/articles/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('kb.new_article')) ?></a>
        <a href="/kb/categories" class="btn"><?= icon('tag') ?><?= e(t('tickets.categories')) ?></a>
    </div>

    <form method="get" action="/kb/articles" class="inline-form-row filter-bar">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('kb.search_placeholder')) ?>">
        <select aria-label="<?= e(t('contacts.category')) ?>" name="category_id">
            <option value=""><?= e(t('kb.all_categories')) ?></option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= e($cat['id']) ?>" <?= $categoryId === (string) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/kb/articles" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../partials/pagination.php'; ?>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('kb.article_title')) ?></th><th><?= e(t('tickets.category')) ?></th><th><?= e(t('kb.author')) ?></th><th><?= e(t('kb.updated')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($articles as $article): ?>
            <tr>
                <td><a href="/kb/articles/<?= e($article['id']) ?>"><?= e($article['title']) ?></a></td>
                <td><?= e($article['category_name'] ?? '—') ?></td>
                <td><?= e($article['author_name'] ?? '—') ?></td>
                <td class="muted"><?= e($article['updated_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($articles === []): ?>
            <tr><td colspan="4" class="muted"><?= e(t('kb.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
