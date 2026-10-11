<?php
/** @var array $article  body is already sanitized HTML — loaded into #help-dialog */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
?>
<h3 class="help-fragment__title"><?= e($article['title']) ?></h3>
<div class="help-content">
    <?= $article['body'] ?>
</div>
<p class="help-fragment__more">
    <a href="/help/<?= e(rawurlencode($article['slug'])) ?>" class="btn btn--sm"><?= icon('book-open') ?><?= e(t('help.open_full')) ?></a>
</p>
