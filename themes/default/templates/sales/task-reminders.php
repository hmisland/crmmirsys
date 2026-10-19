<?php
/**
 * The user's task reminders (T6): in-app notifications created by the
 * reminder processor, newest first. Only tasks the user may still see are
 * listed (TaskReminderService::inbox()); opening a task marks its
 * reminders read.
 *
 * @var array<int, array{notification: array, task: array}> $items
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 * @var \App\Infrastructure\Theme\ThemeLoader $theme
 */

use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;

// remind_at is the organization's wall clock (shown as stored); created_at
// is a stored UTC instant, so it goes through local_time() first.
$pointLabel = fn (?string $value, mixed $allDay): string => $value === null || $value === ''
    ? ''
    : $fmt->date($value) . (!((bool) $allDay) || substr($value, 11, 5) !== '00:00' ? ' ' . $fmt->digits(substr($value, 11, 5)) : '');
$hasUnread = array_filter($items, fn (array $i) => $i['notification']['read_at'] === null) !== [];
?>
<section class="card">
    <div class="card__header">
        <div>
            <h1><?= e(t('tasks.reminders')) ?></h1>
            <p class="muted"><?= e(t('tasks.reminders_hint')) ?></p>
        </div>
        <div class="actions">
            <?php if ($hasUnread): ?>
                <form method="post" action="/tasks/reminders/read-all" class="inline-form">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn"><?= icon('check') ?><?= e(t('tasks.reminders_read_all')) ?></button>
                </form>
            <?php endif; ?>
            <a class="btn" href="/tasks"><?= icon('arrow-left') ?><?= e(t('nav.tasks')) ?></a>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th><?= e(t('tasks.field_title')) ?></th>
                    <th><?= e(t('tasks.reminder')) ?></th>
                    <th><?= e(t('tasks.reminder_received')) ?></th>
                    <th><?= e(t('tasks.status_label')) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as ['notification' => $n, 'task' => $task]): ?>
                    <?php $unread = $n['read_at'] === null; ?>
                    <tr>
                        <td><a href="/tasks/<?= e($task['id']) ?>"><?= icon('bell', 'icon--sm') ?><?= e($task['title']) ?></a></td>
                        <td><span class="ltr"><?= e($pointLabel($n['remind_at'], $n['remind_all_day'] ?? 1)) ?></span></td>
                        <td><span class="ltr"><?= e($pointLabel(local_time($n['created_at']), 0)) ?></span></td>
                        <td><?= $unread ? badge(t('tasks.reminder_unread'), 'new') : badge(t('tasks.reminder_read'), 'read') ?></td>
                        <td>
                            <?php if ($unread): ?>
                                <form method="post" action="/tasks/reminders/<?= e($n['id']) ?>/read" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="btn btn--ghost btn--sm"><?= icon('check') ?><?= e(t('tasks.reminder_mark_read')) ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($items === []): ?>
                    <tr><td colspan="5" class="muted"><?= e(t('tasks.reminders_empty')) ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
