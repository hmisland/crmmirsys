<?php
/** @var array $messages */
/** @var \App\Infrastructure\Localization\LocaleFormatter $fmt */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;

$returnPath = '/announcements';
?>
<section class="card">
    <h1><?= e(t('announcements.inbox_title')) ?></h1>
    <p class="muted"><?= e(t('announcements.inbox_subtitle')) ?></p>
</section>

<section class="card">
    <?php if ($messages === []): ?>
        <p class="muted"><?= e(t('announcements.inbox_empty')) ?></p>
    <?php endif; ?>
    <div class="announcement-list">
        <?php foreach ($messages as $m): ?>
            <?php include __DIR__ . '/../partials/announcement-item.php'; ?>
        <?php endforeach; ?>
    </div>
</section>
