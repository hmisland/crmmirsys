<?php
/**
 * One checklist item. Used by the task page and by the checklist routes'
 * JSON answer (so a fetch() update renders exactly what a reload would).
 *
 * @var int   $taskId
 * @var array $item    a task_checklist_items row
 * @var bool  $canEdit tasks.edit on an active task (TaskService::actionsFor()['edit'])
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$done = (bool) $item['is_done'];
$itemBase = '/tasks/' . (int) $taskId . '/checklist/' . (int) $item['id'];
$toggleLabel = t($done ? 'tasks.checklist_mark_open' : 'tasks.checklist_mark_done');
?>
<li class="checklist__item<?= $done ? ' is-done' : '' ?>" data-checklist-item="<?= (int) $item['id'] ?>">
    <?php if ($canEdit): ?>
        <form method="post" action="<?= e($itemBase) ?>/toggle" class="inline-form" data-checklist-toggle>
            <?= Csrf::field() ?>
            <input type="hidden" name="done" value="<?= $done ? '0' : '1' ?>">
            <button type="submit" class="checkbox-toggle<?= $done ? ' is-checked' : '' ?>" role="checkbox" aria-checked="<?= $done ? 'true' : 'false' ?>"
                    aria-label="<?= e($toggleLabel) ?>" data-tooltip="<?= e($toggleLabel) ?>"><?php if ($done): ?><?= icon('check', 'icon--sm') ?><?php endif; ?></button>
        </form>
    <?php else: ?>
        <span class="checkbox-toggle<?= $done ? ' is-checked' : '' ?>" role="checkbox" aria-checked="<?= $done ? 'true' : 'false' ?>" aria-disabled="true"><?php if ($done): ?><?= icon('check', 'icon--sm') ?><?php endif; ?></span>
    <?php endif; ?>

    <span class="checklist__title"><?= e($item['title']) ?></span>

    <?php if ($canEdit): ?>
        <details class="dropdown">
            <summary class="btn btn--ghost btn--icon btn--sm" aria-label="<?= e(t('tasks.checklist_edit')) ?>" title="<?= e(t('tasks.checklist_edit')) ?>"><?= icon('pencil') ?></summary>
            <div class="dropdown__panel">
                <form method="post" action="<?= e($itemBase) ?>" class="form-stack">
                    <?= Csrf::field() ?>
                    <label for="checklist-title-<?= (int) $item['id'] ?>"><?= e(t('tasks.checklist_item_title')) ?></label>
                    <input type="text" id="checklist-title-<?= (int) $item['id'] ?>" name="title" value="<?= e($item['title']) ?>" required maxlength="255">
                    <button type="submit" class="btn btn--primary btn--sm"><?= icon('save') ?><?= e(t('common.save')) ?></button>
                </form>
                <form method="post" action="<?= e($itemBase) ?>/delete">
                    <?= Csrf::field() ?>
                    <button type="submit" class="dropdown__item dropdown__item--danger" data-confirm><?= icon('trash') ?><?= e(t('tasks.checklist_delete')) ?></button>
                </form>
            </div>
        </details>
    <?php endif; ?>
</li>
