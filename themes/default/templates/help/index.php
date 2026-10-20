<?php
/** @var array $articles */
/** @var string $search */
/** @var bool $canManage */
/** @var bool $canSendTicket */
/** @var string $category selected category key ('' = all) */
/** @var array<string, array{label: string, count: int}> $categories the filter's options */
/** @var string $view 'grid' (cards) | 'list' (titles only) */
/** @var \App\Infrastructure\Pagination\Pagination $pagination */

use App\Application\Services\HelpCategories;
use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;

// Same filters, another view (back to the first page).
$viewUrl = fn (string $mode): string => '/help?' . http_build_query(array_filter(['q' => $search, 'category' => $category, 'view' => $mode === 'list' ? 'list' : null, 'per_page' => $pagination->perPage !== \App\Infrastructure\Pagination\Pagination::DEFAULT_PER_PAGE ? $pagination->perPage : null], fn ($v) => $v !== null && $v !== ''));
?>
<section class="card">
    <div class="page-head">
        <div>
            <h1><?= e(t('help.title')) ?></h1>
            <p><?= e(t('help.subtitle')) ?></p>
        </div>
        <div class="actions">
            <?php if (!empty($canSendTicket)): ?>
                <a href="/org/tickets/create" class="btn btn--primary"><?= icon('send') ?><?= e(t('platform_tickets.new')) ?></a>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <a href="/admin/help/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('help.new')) ?></a>
            <?php endif; ?>
        </div>
    </div>

    <form method="get" action="/help" class="inline-form-row filter-bar" role="search">
        <label for="help-q" class="visually-hidden"><?= e(t('help.search')) ?></label>
        <input type="search" id="help-q" name="q" value="<?= e($search) ?>" placeholder="<?= e(t('help.search_placeholder')) ?>">
        <label for="help-category" class="visually-hidden"><?= e(t('help.category_filter')) ?></label>
        <select id="help-category" name="category" data-autosubmit>
            <option value=""><?= e(t('help.all_categories')) ?></option>
            <?php foreach ($categories as $key => $option): ?>
                <option value="<?= e($key) ?>"<?= $category === $key ? ' selected' : '' ?>><?= e($option['label']) ?> (<?= e((string) $option['count']) ?>)</option>
            <?php endforeach; ?>
        </select>
        <?php if ($view === 'list'): ?><input type="hidden" name="view" value="list"><?php endif; ?>
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('help.search')) ?></button>
        <?php if ($search !== '' || $category !== ''): ?>
            <a href="<?= e($view === 'list' ? '/help?view=list' : '/help') ?>" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a>
        <?php endif; ?>
        <div class="toolbar__end" role="group" aria-label="<?= e(t('help.view_mode')) ?>">
            <a href="<?= e($viewUrl('grid')) ?>" class="btn btn--sm<?= $view === 'grid' ? ' btn--primary' : '' ?>"<?= $view === 'grid' ? ' aria-current="true"' : '' ?>><?= icon('grid') ?><?= e(t('help.view_grid')) ?></a>
            <a href="<?= e($viewUrl('list')) ?>" class="btn btn--sm<?= $view === 'list' ? ' btn--primary' : '' ?>"<?= $view === 'list' ? ' aria-current="true"' : '' ?>><?= icon('list') ?><?= e(t('help.view_list')) ?></a>
        </div>
    </form>

    <?php include __DIR__ . '/../partials/pagination.php'; ?>
</section>

<?php if ($articles === []): ?>
    <section class="card">
        <p class="muted"><?= e(t($search !== '' ? 'help.no_results' : ($category !== '' ? 'help.category_empty' : 'help.empty'))) ?></p>
    </section>
<?php elseif ($view === 'list'): ?>
    <section class="card">
        <ul class="help-list">
            <?php foreach ($articles as $a): ?>
                <li>
                    <a href="/help/<?= e(rawurlencode($a['slug'])) ?>"><?= icon('book-open') ?><span><?= e($a['title']) ?></span></a>
                    <?php if ($canManage && !$a['is_published']): ?><?= badge(t('help.status.draft'), 'pending') ?><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php else: ?>
    <div class="help-grid">
        <?php foreach ($articles as $a): ?>
            <article class="card help-card">
                <h2 class="help-card__title">
                    <a href="/help/<?= e(rawurlencode($a['slug'])) ?>"><?= icon('book-open') ?><span><?= e($a['title']) ?></span></a>
                </h2>
                <?php if (!empty($a['summary'])): ?>
                    <p class="help-card__summary"><?= e($a['summary']) ?></p>
                <?php endif; ?>
                <?php if ($a['categories'] !== []): ?>
                    <p class="help-card__categories"><?= icon('tag') ?><?= e(implode(' · ', array_map(fn (string $k): string => HelpCategories::label($k), HelpCategories::normalize($a['categories'])))) ?></p>
                <?php endif; ?>
                <?php if ($canManage): ?>
                    <div class="help-card__admin">
                        <?php if (!$a['is_published']): ?>
                            <?= badge(t('help.status.draft'), 'pending') ?>
                        <?php endif; ?>
                        <span class="muted ltr"><?= e(strtoupper(str_replace(',', ' · ', (string) $a['locales']))) ?></span>
                        <span class="help-card__spacer"></span>
                        <a href="/admin/help/<?= e($a['id']) ?>/edit" class="link-button"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
                        <form method="post" action="/admin/help/<?= e($a['id']) ?>/delete" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button link-button--danger" data-confirm="<?= e(t('help.delete_confirm')) ?>"><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                        </form>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
