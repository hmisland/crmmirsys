<?php
/** @var array $article  body is already sanitized HTML */
/** @var bool $canManage */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;
?>
<section class="card">
    <div class="page-head">
        <div>
            <h1><?= e($article['title']) ?></h1>
            <?php if (!empty($article['summary'])): ?>
                <p><?= e($article['summary']) ?></p>
            <?php endif; ?>
        </div>
        <div class="actions">
            <a href="/help" class="btn"><?= icon('arrow-left') ?><?= e(t('help.back')) ?></a>
            <?php if ($canManage): ?>
                <a href="/admin/help/<?= e($article['id']) ?>/edit" class="btn"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
                <form method="post" action="/admin/help/<?= e($article['id']) ?>/delete" class="inline-form">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn--danger" data-confirm="<?= e(t('help.delete_confirm')) ?>"><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($canManage && !$article['is_published']): ?>
        <p><?= badge(t('help.status.draft'), 'pending') ?> <span class="muted"><?= e(t('help.draft_hint')) ?></span></p>
    <?php endif; ?>
</section>

<section class="card">
    <div class="help-content">
        <?= $article['body'] ?>
    </div>
</section>
