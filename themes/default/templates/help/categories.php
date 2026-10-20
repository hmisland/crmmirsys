<?php
/**
 * Super Admin → Help → Categories: the categories readers filter by.
 *
 * @var list<array{id: int, key: string, sort_order: int, always_listed: int, name: string, article_count: int}> $categories
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;
?>
<div class="page-head">
    <div>
        <h1><?= e(t('help.categories_admin.title')) ?></h1>
        <p><?= e(t('help.categories_admin.subtitle')) ?></p>
    </div>
    <div class="actions">
        <a href="/help" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('help.categories_admin.back')) ?></a>
        <a href="/admin/help-categories/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('help.categories_admin.new')) ?></a>
    </div>
</div>

<section class="card">
    <div class="table-responsive">
    <table class="table">
        <thead><tr>
            <th><?= e(t('help.categories_admin.name')) ?></th>
            <th><?= e(t('help.categories_admin.key')) ?></th>
            <th><?= e(t('help.sort_order')) ?></th>
            <th><?= e(t('help.categories_admin.articles')) ?></th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php if ($categories === []): ?>
            <tr><td colspan="5" class="muted"><?= e(t('help.categories_admin.empty')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($categories as $c): ?>
            <tr>
                <td>
                    <?= e($c['name']) ?>
                    <?php if ($c['always_listed'] === 1): ?><?= badge(t('help.categories_admin.always_listed_badge'), 'active') ?><?php endif; ?>
                </td>
                <td><code><?= e($c['key']) ?></code></td>
                <td><?= e((string) $c['sort_order']) ?></td>
                <td><a href="/help?category=<?= e(rawurlencode($c['key'])) ?>"><?= e((string) $c['article_count']) ?></a></td>
                <td class="nowrap">
                    <a href="/admin/help-categories/<?= e($c['id']) ?>/edit" class="link-button"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
                    <form method="post" action="/admin/help-categories/<?= e($c['id']) ?>/delete" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button link-button--danger" data-confirm="<?= e(t('help.categories_admin.delete_confirm', ['count' => (string) $c['article_count']])) ?>"><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
