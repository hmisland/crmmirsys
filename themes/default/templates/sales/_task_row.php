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
$statusIcons = ['pending' => 'clock', 'in_progress' => 'activity', 'on_hold' => 'ban', 'completed' => 'check-circle', 'rejected' => 'x-circle', 'cancelled' => 'x'];
$act = ($taskActions ?? [])[(int) $task['id']] ?? ['statuses' => [], 'toggle' => null, 'archive' => false, 'delete' => false];
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
        <a href="<?= e($base) ?>" class="task-row__title"><?= e($task['title']) ?></a>
        <span class="item-list__meta">
            <?php if ($task['due_at']): ?><?= e(t('tasks.due')) ?> <span class="ltr"><?= e(substr((string) $task['due_at'], 0, 10)) ?></span><?php else: ?><?= e(t('tasks.no_due_date')) ?><?php endif; ?>
            · <?= e(t('tasks.priority.' . $task['priority'])) ?> <?= e(t('tasks.priority_suffix')) ?>
            <?= $task['related_type'] ? ' · ' . e(t('tasks.related.' . $task['related_type'])) . ' #' . e($task['related_id']) : '' ?>
        </span>
    </div>

    <?= badge(t('tasks.status.' . $task['status']), $task['status']) ?>

    <?php if ($act['statuses'] !== [] || $act['archive'] || $act['delete']): ?>
        <details class="dropdown">
            <summary class="btn btn--ghost btn--icon btn--sm" aria-label="<?= e(t('tasks.actions')) ?>" title="<?= e(t('tasks.actions')) ?>"><?= icon('sliders') ?></summary>
            <div class="dropdown__panel">
                <?php if ($act['statuses'] !== []): ?>
                    <div class="dropdown__header"><strong><?= e(t('tasks.change_status')) ?></strong></div>
                    <?php foreach ($act['statuses'] as $status): ?>
                        <form method="post" action="<?= e($base) ?>/status">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="status" value="<?= e($status) ?>">
                            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                            <button type="submit" class="dropdown__item"><?= icon($statusIcons[$status]) ?><?= e(t('tasks.status.' . $status)) ?></button>
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
