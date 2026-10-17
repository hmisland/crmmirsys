<?php
/**
 * Appointments tab. There is no Appointment module in this product, so
 * planned visits are sessions with status "scheduled" (see
 * docs/client-records-module.md §6). Grouped: upcoming, past-due
 * (scheduled but the time has passed — needs an outcome), and the
 * visit outcomes (completed / no-show / cancelled).
 * Variables come from show.php's scope.
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_now;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$now = local_now(); // session_at is local wall clock
$byTime = function (array $list, bool $asc): array {
    usort($list, fn ($a, $b) => $asc ? strcmp($a['session_at'], $b['session_at']) : strcmp($b['session_at'], $a['session_at']));

    return $list;
};

$groups = [
    'upcoming' => ['icon' => 'calendar', 'tone' => '', 'rows' => $byTime(array_filter($sessions, fn ($s) => $s['status'] === 'scheduled' && $s['session_at'] >= $now), true)],
    'overdue' => ['icon' => 'alert-triangle', 'tone' => 'warning', 'rows' => $byTime(array_filter($sessions, fn ($s) => $s['status'] === 'scheduled' && $s['session_at'] < $now), false)],
    'completed' => ['icon' => 'check-circle', 'tone' => '', 'rows' => array_filter($sessions, fn ($s) => $s['status'] === 'completed')],
    'no_show' => ['icon' => 'ban', 'tone' => 'warning', 'rows' => array_filter($sessions, fn ($s) => $s['status'] === 'no_show')],
    'cancelled' => ['icon' => 'x-circle', 'tone' => 'danger', 'rows' => array_filter($sessions, fn ($s) => $s['status'] === 'cancelled')],
];
?>
<section class="card">
    <div class="card__header">
        <h2><?= icon('calendar') ?><?= e(t('client_records.tab.appointments')) ?></h2>
        <?php if ($can['sessions']): ?>
            <a href="/client-records/<?= e($record['id']) ?>/sessions/create?status=scheduled" class="btn btn--primary btn--sm"><?= icon('plus') ?><?= e(t('client_records.schedule_visit')) ?></a>
        <?php endif; ?>
    </div>
    <p class="field-hint"><?= e(t('client_records.appointments_hint')) ?></p>

    <?php foreach ($groups as $key => $group): ?>
        <?php if ($group['rows'] === [] && $key !== 'upcoming') { continue; } ?>
        <div class="task-group">
            <h3 class="task-group__title"><?= icon($group['icon'], 'icon--sm') ?><?= e(t('client_records.visits.' . $key)) ?>
                <span class="count<?= $group['tone'] !== '' && $group['rows'] !== [] ? ' count--' . e($group['tone']) : '' ?>"><?= e(count($group['rows'])) ?></span></h3>
            <?php if ($group['rows'] === []): ?>
                <p class="item-list--empty"><?= e(t('client_records.no_upcoming')) ?></p>
            <?php else: ?>
                <ul class="event-list">
                    <?php foreach ($group['rows'] as $session): ?>
                        <?php $quickStatus = true; include $theme->templatesPath() . '/client-records/_session.php'; ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
