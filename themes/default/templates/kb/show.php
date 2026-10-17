<?php
/** @var array $article */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e($article['title']) ?></h1>
    <p class="muted">
        <?= $article['category_name'] ? e($article['category_name']) . ' · ' : '' ?>
        <?= e($article['author_name'] ?? '') ?> · <?= e(t('kb.updated_lc')) ?> <?= e(local_time($article['updated_at'])) ?>
    </p>

    <div><?= nl2br(e($article['body'])) ?></div>

    <p style="margin-top:1rem">
        <a href="/kb/articles/<?= e($article['id']) ?>/edit" class="btn"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
        <form method="post" action="/kb/articles/<?= e($article['id']) ?>/delete" class="inline-form">
            <?= Csrf::field() ?>
            <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
        </form>
        <a href="/kb/articles" class="btn"><?= icon('arrow-left') ?><?= e(t('kb.back_to_list')) ?></a>
    </p>
</section>
