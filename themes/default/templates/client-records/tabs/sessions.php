<?php
/**
 * Sessions tab: the visit history (everything except still-scheduled
 * visits, which live in the Appointments tab). Variables come from
 * show.php's scope.
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$history = array_values(array_filter($sessions, fn ($s) => $s['status'] !== 'scheduled'));
?>
<section class="card">
    <div class="card__header">
        <h2><?= icon('activity') ?><?= e(t('client_records.tab.sessions')) ?></h2>
        <?php if ($can['sessions']): ?>
            <a href="/client-records/<?= e($record['id']) ?>/sessions/create" class="btn btn--primary btn--sm"><?= icon('plus') ?><?= e(t('client_records.new_session')) ?></a>
        <?php endif; ?>
    </div>

    <?php if ($history === []): ?>
        <div class="empty-state">
            <span class="empty-state__icon"><?= icon('activity') ?></span>
            <p><?= e(t('client_records.no_sessions')) ?></p>
        </div>
    <?php else: ?>
        <ul class="event-list">
            <?php foreach ($history as $session): ?>
                <?php $quickStatus = false; include $theme->templatesPath() . '/client-records/_session.php'; ?>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
