<?php
/** @var array $articles */
/** @var array $categories */
/** @var string $q */
/** @var string $categoryId */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
?>
<section class="card">
    <h1><?= e(t('kb.title')) ?></h1>

    <form method="get" action="/kb/articles" class="inline-form-row">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('kb.search_placeholder')) ?>">
        <select name="category_id">
            <option value=""><?= e(t('kb.all_categories')) ?></option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= e($cat['id']) ?>" <?= $categoryId === (string) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= e(t('common.search')) ?></button>
        <a href="/kb/categories" class="btn"><?= e(t('tickets.categories')) ?></a>
        <a href="/kb/articles/create" class="btn btn--primary"><?= e(t('kb.new_article')) ?></a>
    </form>

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
