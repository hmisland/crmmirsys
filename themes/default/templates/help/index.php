<?php
/** @var array $articles */
/** @var string $search */
/** @var bool $canManage */
/** @var bool $canSendTicket */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;
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
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('help.search')) ?></button>
        <?php if ($search !== ''): ?>
            <a href="/help" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a>
        <?php endif; ?>
    </form>
</section>

<?php if ($articles === []): ?>
    <section class="card">
        <p class="muted"><?= e(t($search !== '' ? 'help.no_results' : 'help.empty')) ?></p>
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
