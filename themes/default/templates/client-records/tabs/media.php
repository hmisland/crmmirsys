<?php
/**
 * Before/after media tab. Thumbnails are served by the module's own
 * checked route (view_sensitive, tenant, ownership) with no-store
 * caching — never a public URL. Optional ?category= filter.
 * Variables come from show.php's scope.
 *
 * @var array $files
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$kind = 'media';
$categories = ['before', 'after', 'progress', 'other'];
$filter = $mediaFilter ?? '';
$shown = $filter === '' ? $files : array_values(array_filter($files, fn ($f) => $f['category'] === $filter));
$base = '/client-records/' . (int) $record['id'];
?>
<div class="record-layout">
    <div>
        <section class="card">
            <div class="card__header">
                <h2><?= icon('image') ?><?= e(t('client_records.tab.media')) ?></h2>
                <?php if ($can['sensitive'] && $files !== []): ?>
                    <nav class="segmented" aria-label="<?= e(t('client_records.category')) ?>">
                        <a class="btn btn--sm" href="<?= e($base) ?>?tab=media"<?= $filter === '' ? ' aria-current="page"' : '' ?>><?= e(t('client_records.all')) ?></a>
                        <?php foreach ($categories as $c): ?>
                            <a class="btn btn--sm" href="<?= e($base) ?>?tab=media&amp;category=<?= e($c) ?>"<?= $filter === $c ? ' aria-current="page"' : '' ?>><?= e(t('client_records.media_category.' . $c)) ?></a>
                        <?php endforeach; ?>
                    </nav>
                <?php endif; ?>
            </div>

            <?php if (!$can['sensitive']): ?>
                <p class="locked"><?= icon('lock') ?><?= e(t('client_records.files_locked')) ?></p>
            <?php elseif ($shown === []): ?>
                <div class="empty-state">
                    <span class="empty-state__icon"><?= icon('image') ?></span>
                    <p><?= e(t('client_records.no_media')) ?></p>
                </div>
            <?php else: ?>
                <div class="media-grid">
                    <?php foreach ($shown as $file): ?>
                        <?php $url = $base . '/files/' . (int) $file['id']; ?>
                        <figure class="media-card">
                            <a class="media-card__thumb" href="<?= e($url) ?>" target="_blank" rel="noopener">
                                <?php if ($file['is_image']): ?>
                                    <img src="<?= e($url) ?>" alt="<?= e(t('client_records.media_category.' . $file['category']) . ' — ' . ($file['taken_at'] ?? '')) ?>" loading="lazy">
                                <?php else: ?>
                                    <?= icon('image', 'icon--xl') ?>
                                <?php endif; ?>
                            </a>
                            <figcaption class="media-card__meta">
                                <span class="badge badge--brand"><?= e(t('client_records.media_category.' . $file['category'])) ?></span>
                                <span class="nowrap ltr"><?= e($file['taken_at'] ?? substr((string) $file['created_at'], 0, 10)) ?></span>
                                <?php if ($file['session_id'] !== null): ?><span><?= icon('activity', 'icon--sm') ?></span><?php endif; ?>
                            </figcaption>
                            <?php if (!empty($file['notes'])): ?><p class="media-card__notes"><?= e($file['notes']) ?></p><?php endif; ?>
                            <?php if ($can['media']): ?>
                                <form method="post" action="<?= e($url) ?>/delete" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="kind" value="media">
                                    <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                                </form>
                            <?php endif; ?>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <div>
        <?php if ($can['media']): ?>
            <?php include $theme->templatesPath() . '/client-records/_upload.php'; ?>
        <?php endif; ?>
    </div>
</div>
