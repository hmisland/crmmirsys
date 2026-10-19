<?php
/**
 * One task row, shared by "My tasks" and a deal's task list.
 * Expects in scope: $task, $taskActions (TaskService::actionsFor(), keyed
 * by task id), $returnTo (where actions redirect back to).
 *
 * The checkbox marks the task done (or back to pending) and the row
 * stays put, struck through — it doesn't disappear. Status, archive and
 * delete controls only render when the user may use them on THIS task
 * (same rule the server enforces); the services re-check every action anyway.
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;
use App\Infrastructure\Security\Csrf;

$isDone = $task['status'] === 'completed';
$isClosed = in_array($task['status'], ['completed', 'rejected', 'cancelled'], true);
$isArchived = ($task['archived_at'] ?? null) !== null;
$base = '/tasks/' . (int) $task['id'];
$act = ($taskActions ?? [])[(int) $task['id']] ?? ['statuses' => [], 'status_options' => [], 'toggle' => null, 'archive' => false, 'delete' => false];
$act['status_options'] ??= [];
// The custom statuses' icons follow their fixed lifecycle category (T8).
$categoryIcons = ['open' => 'clock', 'in_progress' => 'activity', 'completed' => 'check-circle', 'cancelled' => 'x-circle', 'archived' => 'archive'];
$fmt ??= new \App\Infrastructure\Localization\LocaleFormatter('en');

// Description preview: plain text (escaped below), whitespace collapsed,
// cut at 140 characters; CSS clamps it to two lines. Empty → nothing.
$preview = trim((string) preg_replace('/\s+/u', ' ', (string) ($task['description'] ?? '')));
if (mb_strlen($preview) > 140) {
    $preview = rtrim(mb_substr($preview, 0, 140)) . '…';
}

// start_at / due_at are the organization's local wall clock: shown as stored
// (never converted), in the user's calendar and digits. The time only for a
// timed value (*_all_day = 0, so an explicit 00:00 shows) or a stored time
// other than 00:00; an all-day date shows no time.
$pointLabel = function (?string $value, mixed $allDay) use ($fmt): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    $time = substr($value, 11, 5);

    return $fmt->date($value) . (!((bool) ($allDay ?? true)) || ($time !== '' && $time !== '00:00') ? ' ' . $fmt->digits($time) : '');
};
$startLabel = $pointLabel($task['start_at'] ?? null, $task['start_all_day'] ?? 1);
$dueLabel = $pointLabel($task['due_at'] ?? null, $task['due_all_day'] ?? 1);
// The reminder (same wall clock): a bell and its time, muted once it fired.
$remindLabel = $pointLabel($task['remind_at'] ?? null, $task['remind_all_day'] ?? 1);
$remindFired = $remindLabel !== null && (int) ($task['remind_fired_seq'] ?? 0) >= (int) ($task['remind_seq'] ?? 0);
// Checklist progress (TaskService::checklistProgressFor()); absent = no items.
$progress = ($taskProgress ?? [])[(int) $task['id']] ?? null;
?>
<li class="task-row<?= $isClosed ? ' task-row--closed' : '' ?><?= $isDone ? ' task-row--done' : '' ?>">
    <?php if ($act['toggle'] !== null): ?>
        <form method="post" action="<?= e($base) ?>/toggle" class="inline-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
            <button type="submit" class="checkbox-toggle<?= $isDone ? ' is-checked' : '' ?>" role="checkbox" aria-checked="<?= $isDone ? 'true' : 'false' ?>"
                    aria-label="<?= e(t($isDone ? 'tasks.mark_pending' : 'tasks.mark_complete')) ?>" data-tooltip="<?= e(t($isDone ? 'tasks.mark_pending' : 'tasks.mark_complete')) ?>">
                <?php if ($isDone): ?><?= icon('check', 'icon--sm') ?><?php endif; ?>
            </button>
        </form>
    <?php endif; ?>

    <div class="item-list__main">
        <div class="task-row__head">
            <a href="<?= e($base) ?>" class="task-row__title"><?= e($task['title']) ?></a>
            <?php $labelRows = ($taskLabels ?? [])[(int) $task['id']] ?? []; include $theme->templatesPath() . '/sales/_task_labels.php'; ?>
        </div>
        <?php if ($preview !== ''): ?><p class="task-row__desc"><?= e($preview) ?></p><?php endif; ?>
        <span class="item-list__meta">
            <?php if ($startLabel !== null): ?><?= e(t('tasks.start')) ?> <span class="ltr"><?= e($startLabel) ?></span> · <?php endif; ?>
            <?php if ($dueLabel !== null): ?><?= e(t('tasks.due')) ?> <span class="ltr"><?= e($dueLabel) ?></span><?php else: ?><?= e(t('tasks.no_due_date')) ?><?php endif; ?>
            <?php if ($remindLabel !== null): ?> · <span class="task-row__reminder<?= $remindFired ? ' task-row__reminder--fired' : '' ?>" title="<?= e(t('tasks.reminder') . ' — ' . t($remindFired ? 'tasks.reminder_state.delivered' : 'tasks.reminder_state.scheduled')) ?>"><?= icon('bell', 'icon--sm') ?><span class="ltr"><?= e($remindLabel) ?></span></span><?php endif; ?>
            <?php if (!empty($task['recur_type'])): ?> · <?php include $theme->templatesPath() . '/sales/_task_recurrence.php'; ?><?php endif; ?>
            · <?= e(t('tasks.priority.' . $task['priority'])) ?> <?= e(t('tasks.priority_suffix')) ?>
            <?php $relatedInfo = ($taskRelated ?? [])[(int) $task['id']] ?? null; if ($relatedInfo !== null): ?> · <?php include $theme->templatesPath() . '/sales/_task_related.php'; ?><?php endif; ?>
            <?php if ($progress !== null): ?> · <span class="task-row__checklist"><?= icon('check-square', 'icon--sm') ?><?= e($fmt->digits(t('tasks.checklist_progress', $progress))) ?></span><?php endif; ?>
        </span>
        <?php if ($progress !== null): ?><progress class="progress task-row__progress<?= $progress['done'] === $progress['total'] ? ' progress--done' : '' ?>" max="<?= $progress['total'] ?>" value="<?= $progress['done'] ?>" aria-label="<?= e(t('tasks.checklist')) ?>"></progress><?php endif; ?>
    </div>

    <?php include $theme->templatesPath() . '/sales/_task_status.php'; ?>

    <?php if ($act['status_options'] !== [] || $act['archive'] || $act['delete']): ?>
        <details class="dropdown">
            <summary class="btn btn--ghost btn--icon btn--sm" aria-label="<?= e(t('tasks.actions')) ?>" title="<?= e(t('tasks.actions')) ?>"><?= icon('sliders') ?></summary>
            <div class="dropdown__panel">
                <?php if ($act['status_options'] !== []): ?>
                    <div class="dropdown__header"><strong><?= e(t('tasks.change_status')) ?></strong></div>
                    <?php foreach ($act['status_options'] as $option): ?>
                        <form method="post" action="<?= e($base) ?>/status">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="status_id" value="<?= e($option['id']) ?>">
                            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                            <button type="submit" class="dropdown__item"><?= icon($categoryIcons[$option['category']] ?? 'clock') ?><?= e($option['label']) ?></button>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($act['archive']): ?>
                    <form method="post" action="<?= e($base) ?>/<?= $isArchived ? 'unarchive' : 'archive' ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                        <button type="submit" class="dropdown__item"><?= icon($isArchived ? 'undo' : 'archive') ?><?= e(t($isArchived ? 'tasks.unarchive' : 'tasks.archive')) ?></button>
                    </form>
                <?php endif; ?>
                <?php if ($act['delete']): ?>
                    <form method="post" action="<?= e($base) ?>/delete">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                        <button type="submit" class="dropdown__item dropdown__item--danger" data-confirm><?= icon('trash') ?><?= e(t('tasks.delete_task')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </details>
    <?php endif; ?>
</li>
