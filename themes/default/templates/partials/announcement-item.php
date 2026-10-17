<?php
/**
 * One Super Admin announcement in the bell panel or the inbox page,
 * with "I've seen it" (until seen) and "Delete".
 *
 * @var array $m id, title, body, created_at, seen_at
 * @var string $returnPath page to come back to after the action
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
?>
<article class="announcement<?= $m['seen_at'] === null ? ' announcement--unread' : '' ?>">
    <header class="announcement__head">
        <strong><?= e($m['title']) ?></strong>
        <span class="muted text-sm"><?= e($fmt->date(local_time($m['created_at']))) ?></span>
    </header>
    <div class="announcement-body"><?= nl2br(e($m['body'])) ?></div>
    <div class="announcement__actions">
        <?php if ($m['seen_at'] === null): ?>
            <form method="post" action="/announcements/<?= e($m['id']) ?>/seen" class="inline-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="return" value="<?= e($returnPath) ?>">
                <button type="submit" class="btn btn--sm btn--primary"><?= icon('check') ?><?= e(t('announcements.mark_seen')) ?></button>
            </form>
        <?php else: ?>
            <span class="muted text-sm"><?= icon('check-circle', 'icon--sm') ?><?= e(t('announcements.status_seen')) ?></span>
        <?php endif; ?>
        <form method="post" action="/announcements/<?= e($m['id']) ?>/delete" class="inline-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="return" value="<?= e($returnPath) ?>">
            <button type="submit" class="btn btn--sm btn--danger" data-confirm="<?= e(t('announcements.remove_confirm')) ?>"><?= icon('trash') ?><?= e(t('announcements.delete')) ?></button>
        </form>
    </div>
</article>
