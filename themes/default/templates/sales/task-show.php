<?php
/** @var array $task */
/** @var array $users active users + this task's current assignees */
/** @var array $assignees */
/** @var array $comments each with 'can_delete' */
/** @var array $attachments each with 'can_delete' */
/** @var array $errors */
/** @var array{statuses: string[], toggle: ?string, edit: bool, archive: bool, delete: bool, comment: bool, upload: bool} $actions */
/** @var \App\Infrastructure\Localization\LocaleFormatter $fmt */
/** @var array $checklist the task's checklist items, in order */
/** @var array{done: int, total: int} $checklistProgress */
/** @var array $labels this task's labels */
/** @var array $allLabels the organization's labels */
/** @var array|null $related the related record as this user may see it */
/** @var array $relatedOptions records the user may pick (edit form) */
/** @var bool $canManageLabels */
/** @var array $old the submitted values after a failed edit */
/** @var array<int, array> $taskStatuses the organization's statuses by id (T8) */
/** @var array{previous: ?int, next: ?int} $series this task's visible neighbours in its recurring series */
/** @var \App\Infrastructure\Theme\ThemeLoader $theme */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
$assigneeIds = array_map('intval', array_column($assignees, 'id'));
$isInactive = fn (array $u): bool => ($u['status'] ?? 'active') !== 'active';
$fmt ??= new \App\Infrastructure\Localization\LocaleFormatter('en');
// "Y-m-d H:i:s" in the user's calendar and digits; the time only when set.
// due_at is already organization-local (never converted); comment times are
// stored instants, so they go through local_time() first.
$dateLabel = fn (string $value): string => $fmt->date($value) . (substr($value, 11, 5) !== '' && substr($value, 11, 5) !== '00:00' ? ' ' . $fmt->digits(substr($value, 11, 5)) : '');
// start_at / due_at: the time only for a timed value (*_all_day = 0, so an
// explicit 00:00 shows) or a stored time other than 00:00. The edit form
// pre-fills the time by the same rule, so saving never moves a date.
$showsTime = fn (?string $value, mixed $allDay): bool => $value !== null && $value !== '' && (!((bool) ($allDay ?? true)) || substr($value, 11, 5) !== '00:00');
$pointLabel = fn (?string $value, mixed $allDay): string => $fmt->date($value) . ($showsTime($value, $allDay) ? ' ' . $fmt->digits(substr((string) $value, 11, 5)) : '');
$timeValue = fn (?string $value, mixed $allDay): string => $showsTime($value, $allDay) ? substr((string) $value, 11, 5) : '';
// After a failed edit the form shows what was submitted, not the stored values.
$old ??= [];
$formValue = fn (string $field, string $stored): string => array_key_exists($field, $old) && is_string($old[$field]) ? $old[$field] : $stored;
// remind_at: the same wall clock; "sent" once the processor handled this occurrence.
$remindFired = ($task['remind_at'] ?? null) !== null && (int) ($task['remind_fired_seq'] ?? 0) >= (int) ($task['remind_seq'] ?? 0);
?>
<section class="card">
    <h1><?= e($task['title']) ?></h1>
    <?php $labelRows = $labels; include $theme->templatesPath() . '/sales/_task_labels.php'; ?>
    <p class="muted">
        <?= e(t('tasks.priority.' . $task['priority'])) ?> <?= e(t('tasks.priority_suffix')) ?>
        <?php if ($task['start_at'] ?? null): ?>· <?= e(t('tasks.start')) ?> <span class="ltr"><?= e($pointLabel($task['start_at'], $task['start_all_day'] ?? 1)) ?></span><?php endif; ?>
        · <?php if ($task['due_at']): ?><?= e(t('tasks.due')) ?> <span class="ltr"><?= e($pointLabel($task['due_at'], $task['due_all_day'] ?? 1)) ?></span><?php else: ?><?= e(t('tasks.no_due_date')) ?><?php endif; ?>
        · <?php include $theme->templatesPath() . '/sales/_task_status.php'; ?>
        <?php if ($related !== null): ?>· <?php $relatedInfo = $related; include $theme->templatesPath() . '/sales/_task_related.php'; ?><?php endif; ?>
    </p>
    <p class="muted task-reminder">
        <?= icon('bell', 'icon--sm') ?><?= e(t('tasks.reminder')) ?>:
        <?php if ($task['remind_at'] ?? null): ?>
            <span class="ltr"><?= e($pointLabel($task['remind_at'], $task['remind_all_day'] ?? 1)) ?></span>
            <?= $remindFired ? badge(t('tasks.reminder_state.delivered'), 'delivered') : badge(t('tasks.reminder_state.scheduled'), 'scheduled') ?>
        <?php else: ?>
            <?= e(t('tasks.reminder_none')) ?>
        <?php endif; ?>
        <?php if (!empty($task['remind_at']) && !empty($task['remind_email'])): ?> · <?= icon('mail', 'icon--sm') ?><?= e(t('tasks.remind_email_on')) ?><?php endif; ?>
    </p>
    <?php if (!empty($task['recur_type']) || ($series['previous'] ?? null) !== null || ($series['next'] ?? null) !== null): ?>
        <p class="muted task-series">
            <?php include $theme->templatesPath() . '/sales/_task_recurrence.php'; ?>
            <?php if (($series['previous'] ?? null) !== null): ?> · <a href="/tasks/<?= e($series['previous']) ?>"><?= icon('arrow-left') ?><?= e(t('tasks.recur.previous')) ?></a><?php endif; ?>
            <?php if (($series['next'] ?? null) !== null): ?> · <a href="/tasks/<?= e($series['next']) ?>"><?= e(t('tasks.recur.next')) ?><?= icon('arrow-right') ?></a><?php endif; ?>
        </p>
    <?php endif; ?>
    <p>
        <?= e(t('tasks.assigned_to')) ?>:
        <?php foreach ($assignees as $a): ?>
            <span class="tag-pill"><?= e(trim($a['first_name'] . ' ' . ($a['last_name'] ?? ''))) ?></span>
            <?php if ($isInactive($a)): ?><?= badge(t('tasks.assignee_inactive'), 'inactive') ?><?php endif; ?>
        <?php endforeach; ?>
        <?php if ($assignees === []): ?><span class="muted"><?= e(t('tasks.nobody_yet')) ?></span><?php endif; ?>
    </p>

    <?php if ($task['archived_at'] !== null): ?>
        <div class="alert alert--info"><div class="alert__body"><?= e(t('tasks.archived_notice')) ?></div></div>
    <?php endif; ?>

    <div class="actions">
        <?php if ($actions['toggle'] !== null): ?>
            <form method="post" action="/tasks/<?= e($task['id']) ?>/toggle" class="inline-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="return_to" value="/tasks/<?= e($task['id']) ?>">
                <button type="submit" class="btn<?= $task['status'] === 'completed' ? '' : ' btn--primary' ?>"><?= icon($task['status'] === 'completed' ? 'undo' : 'check') ?><?= $task['status'] === 'completed' ? e(t('tasks.mark_pending')) : e(t('tasks.mark_complete')) ?></button>
            </form>
        <?php endif; ?>
        <?php if (($actions['status_options'] ?? []) !== []): ?>
            <form method="post" action="/tasks/<?= e($task['id']) ?>/status" class="inline-form-row">
                <?= Csrf::field() ?>
                <input type="hidden" name="return_to" value="/tasks/<?= e($task['id']) ?>">
                <select name="status_id" aria-label="<?= e(t('tasks.change_status')) ?>">
                    <?php foreach ($actions['status_options'] as $option): ?>
                        <option value="<?= e($option['id']) ?>"><?= e($option['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn"><?= icon('refresh') ?><?= e(t('tasks.change_status')) ?></button>
            </form>
        <?php endif; ?>
        <?php if ($actions['archive']): ?>
            <form method="post" action="/tasks/<?= e($task['id']) ?>/<?= $task['archived_at'] !== null ? 'unarchive' : 'archive' ?>" class="inline-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="return_to" value="/tasks/<?= e($task['id']) ?>">
                <button type="submit" class="btn btn--ghost"><?= icon($task['archived_at'] !== null ? 'undo' : 'archive') ?><?= e(t($task['archived_at'] !== null ? 'tasks.unarchive' : 'tasks.archive')) ?></button>
            </form>
        <?php endif; ?>
        <?php if ($actions['delete']): ?>
            <form method="post" action="/tasks/<?= e($task['id']) ?>/delete" class="inline-form">
                <?= Csrf::field() ?>
                <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('tasks.delete_task')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="card" id="labels">
    <div class="card__header">
        <h2><?= icon('tag') ?><?= e(t('tasks.labels')) ?></h2>
        <?php if ($canManageLabels): ?><a class="btn btn--ghost btn--sm" href="/tasks/labels"><?= icon('settings') ?><?= e(t('tasks.labels_manage')) ?></a><?php endif; ?>
    </div>
    <?php if ($actions['edit'] && $allLabels !== []): ?>
        <?php $onTask = array_column($labels, 'id'); ?>
        <form method="post" action="/tasks/<?= e($task['id']) ?>/labels" class="form-stack">
            <?= Csrf::field() ?>
            <div class="task-label-picker">
                <?php foreach ($allLabels as $label): ?>
                    <label class="task-label-option">
                        <input type="checkbox" name="label_ids[]" value="<?= e($label['id']) ?>"<?= in_array((int) $label['id'], $onTask, true) ? ' checked' : '' ?>>
                        <?php $labelRows = [$label]; include $theme->templatesPath() . '/sales/_task_labels.php'; ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn--sm"><?= icon('save') ?><?= e(t('tasks.labels_save')) ?></button>
        </form>
    <?php elseif ($labels === []): ?>
        <p class="muted"><?= e(t($allLabels === [] ? 'tasks.labels_none_defined' : 'tasks.labels_none_on_task')) ?></p>
    <?php else: ?>
        <?php $labelRows = $labels; include $theme->templatesPath() . '/sales/_task_labels.php'; ?>
    <?php endif; ?>
</section>
<?php [$clDone, $clTotal] = [$checklistProgress['done'], $checklistProgress['total']]; ?>
<section class="card" id="checklist" data-checklist>
    <div class="card__header">
        <h2><?= icon('check-square') ?><?= e(t('tasks.checklist')) ?></h2>
        <span class="muted" data-checklist-count<?= $clTotal === 0 ? ' hidden' : '' ?>><?= e($fmt->digits(t('tasks.checklist_progress', ['done' => $clDone, 'total' => $clTotal]))) ?></span>
    </div>
    <progress class="progress<?= $clTotal > 0 && $clDone === $clTotal ? ' progress--done' : '' ?>" max="<?= max(1, $clTotal) ?>" value="<?= $clDone ?>" aria-label="<?= e(t('tasks.checklist')) ?>" data-checklist-progress<?= $clTotal === 0 ? ' hidden' : '' ?>></progress>
    <ul class="checklist" data-checklist-list>
        <?php foreach ($checklist as $item): ?>
            <?php $taskId = (int) $task['id']; $canEdit = $actions['edit']; include $theme->templatesPath() . '/sales/_checklist_item.php'; ?>
        <?php endforeach; ?>
    </ul>
    <p class="muted" data-checklist-empty<?= $clTotal > 0 ? ' hidden' : '' ?>><?= e(t('tasks.checklist_empty')) ?></p>
    <?php if ($actions['edit']): ?>
        <form method="post" action="/tasks/<?= e($task['id']) ?>/checklist" class="inline-form-row" data-checklist-add>
            <?= Csrf::field() ?>
            <input type="text" name="title" required maxlength="255" placeholder="<?= e(t('tasks.checklist_placeholder')) ?>" aria-label="<?= e(t('tasks.checklist_item_title')) ?>">
            <button type="submit" class="btn"><?= icon('plus') ?><?= e(t('tasks.checklist_add')) ?></button>
        </form>
        <div class="field-error" role="alert" data-checklist-error hidden></div>
    <?php endif; ?>
</section>

<?php if ($actions['edit']): ?>
<section class="card card--form">
    <h2><?= e(t('tasks.edit')) ?></h2>
    <form method="post" action="/tasks/<?= e($task['id']) ?>">
        <?= Csrf::field() ?>
        <?php if ($errors !== []): ?>
            <div class="alert alert--error" role="alert"><div class="alert__body"><?= e(t('tasks.form_has_errors')) ?></div></div>
        <?php endif; ?>

        <label for="title"><?= e(t('tasks.field_title')) ?></label>
        <input type="text" id="title" name="title" value="<?= e($formValue('title', (string) $task['title'])) ?>" required maxlength="255">
        <?php if ($fieldError('title')): ?><div class="field-error"><?= e($fieldError('title')) ?></div><?php endif; ?>

        <label for="description"><?= e(t('contacts.description')) ?></label>
        <textarea id="description" name="description" rows="4"><?= e($formValue('description', (string) ($task['description'] ?? ''))) ?></textarea>

        <label for="start_at"><?= e(t('tasks.start_date')) ?></label>
        <input type="text" class="crm-datepicker" id="start_at" name="start_at" value="<?= e($formValue('start_at', $task['start_at'] ?? null ? substr($task['start_at'], 0, 10) : '')) ?>">
        <?php if ($fieldError('start_at')): ?><div class="field-error"><?= e($fieldError('start_at')) ?></div><?php endif; ?>
        <label for="start_time"><?= e(t('tasks.start_time')) ?></label>
        <input type="time" class="field-sm" id="start_time" name="start_time" value="<?= e($formValue('start_time', $timeValue($task['start_at'] ?? null, $task['start_all_day'] ?? 1))) ?>">
        <?php if ($fieldError('start_time')): ?><div class="field-error"><?= e($fieldError('start_time')) ?></div><?php endif; ?>

        <label for="due_at"><?= e(t('tasks.due_date')) ?></label>
        <input type="text" class="crm-datepicker" id="due_at" name="due_at" value="<?= e($formValue('due_at', $task['due_at'] ? substr($task['due_at'], 0, 10) : '')) ?>">
        <?php if ($fieldError('due_at')): ?><div class="field-error"><?= e($fieldError('due_at')) ?></div><?php endif; ?>
        <label for="due_time"><?= e(t('tasks.due_time')) ?></label>
        <input type="time" class="field-sm" id="due_time" name="due_time" value="<?= e($formValue('due_time', $timeValue($task['due_at'], $task['due_all_day'] ?? 1))) ?>">
        <?php if ($fieldError('due_time')): ?><div class="field-error"><?= e($fieldError('due_time')) ?></div><?php endif; ?>
        <p class="muted text-sm"><?= e(t('tasks.time_hint')) ?></p>

        <label for="remind_at"><?= e(t('tasks.remind_date')) ?></label>
        <input type="text" class="crm-datepicker" id="remind_at" name="remind_at" value="<?= e($formValue('remind_at', $task['remind_at'] ?? null ? substr($task['remind_at'], 0, 10) : '')) ?>">
        <?php if ($fieldError('remind_at')): ?><div class="field-error"><?= e($fieldError('remind_at')) ?></div><?php endif; ?>
        <label for="remind_time"><?= e(t('tasks.remind_time')) ?></label>
        <input type="time" class="field-sm" id="remind_time" name="remind_time" value="<?= e($formValue('remind_time', $timeValue($task['remind_at'] ?? null, $task['remind_all_day'] ?? 1))) ?>">
        <?php if ($fieldError('remind_time')): ?><div class="field-error"><?= e($fieldError('remind_time')) ?></div><?php endif; ?>
        <p class="muted text-sm"><?= e(t('tasks.remind_hint')) ?></p>
        <input type="hidden" name="remind_email" value="0">
        <label class="check"><input type="checkbox" name="remind_email" value="1"<?= $formValue('remind_email', !empty($task['remind_email']) ? '1' : '0') === '1' ? ' checked' : '' ?>> <?= e(t('tasks.remind_email')) ?></label>

        <?php $recurValue = $formValue('recur_type', (string) ($task['recur_type'] ?? '')); ?>
        <label for="recur_type"><?= e(t('tasks.recur.label')) ?></label>
        <select id="recur_type" name="recur_type">
            <option value=""><?= e(t('tasks.recur.none')) ?></option>
            <?php foreach (\Modules\Sales\Services\TaskService::RECURRENCES as $recur): ?>
                <option value="<?= e($recur) ?>"<?= $recurValue === $recur ? ' selected' : '' ?>><?= e(t('tasks.recur.unit_' . $recur)) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($fieldError('recur_type')): ?><div class="field-error"><?= e($fieldError('recur_type')) ?></div><?php endif; ?>
        <label for="recur_interval"><?= e(t('tasks.recur.interval')) ?></label>
        <input type="number" class="field-sm" id="recur_interval" name="recur_interval" min="1" max="<?= \Modules\Sales\Services\TaskService::MAX_RECUR_INTERVAL ?>" value="<?= e($formValue('recur_interval', (string) ($task['recur_interval'] ?? 1))) ?>">
        <?php if ($fieldError('recur_interval')): ?><div class="field-error"><?= e($fieldError('recur_interval')) ?></div><?php endif; ?>
        <label for="recur_until"><?= e(t('tasks.recur.until_label')) ?></label>
        <input type="text" class="crm-datepicker" id="recur_until" name="recur_until" value="<?= e($formValue('recur_until', (string) ($task['recur_until'] ?? ''))) ?>">
        <?php if ($fieldError('recur_until')): ?><div class="field-error"><?= e($fieldError('recur_until')) ?></div><?php endif; ?>
        <input type="hidden" name="recur_active" value="0">
        <label class="check"><input type="checkbox" name="recur_active" value="1"<?= $formValue('recur_active', empty($task['recur_type']) || !empty($task['recur_active']) ? '1' : '0') === '1' ? ' checked' : '' ?>> <?= e(t('tasks.recur.active')) ?></label>
        <p class="muted text-sm"><?= e(t('tasks.recur.hint')) ?></p>

        <label for="related"><?= e(t('tasks.related_label')) ?></label>
        <?php
        $current = $task['related_type'] && $task['related_id'] ? $task['related_type'] . ':' . (int) $task['related_id'] : '';
        $listed = $current !== '' && isset($relatedOptions[$task['related_type']][$current]);
        $selectId = 'related';
        $selectedRelated = $listed ? $current : '';
        $keepRelated = $current !== '' && !$listed ? $related : null;
        include $theme->templatesPath() . '/sales/_related_select.php';
        ?>
        <?php if ($fieldError('related')): ?><div class="field-error"><?= e($fieldError('related')) ?></div><?php endif; ?>

        <?php
        // The current status (even an inactive one, marked) plus those this user may move the task to.
        $currentStatus = \Modules\Sales\Services\TaskStatusService::effective($task, $taskStatuses ?? []);
        $selectedStatus = $formValue('status_id', $currentStatus !== null ? (string) $currentStatus['id'] : '');
        ?>
        <label for="status_id"><?= e(t('tasks.status_label')) ?></label>
        <select id="status_id" name="status_id">
            <?php if ($currentStatus === null): ?>
                <option value=""><?= e(t('tasks.status.' . $task['status'])) ?></option>
            <?php else: ?>
                <option value="<?= e($currentStatus['id']) ?>"<?= $selectedStatus === (string) $currentStatus['id'] ? ' selected' : '' ?>><?= e($currentStatus['label']) ?><?= (int) $currentStatus['is_active'] !== 1 ? ' (' . e(t('tasks.status_inactive')) . ')' : '' ?></option>
            <?php endif; ?>
            <?php foreach ($actions['status_options'] ?? [] as $option): ?>
                <option value="<?= e($option['id']) ?>"<?= $selectedStatus === (string) $option['id'] ? ' selected' : '' ?>><?= e($option['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($fieldError('status_id')): ?><div class="field-error"><?= e($fieldError('status_id')) ?></div><?php endif; ?>

        <label for="priority"><?= e(t('tasks.priority_label')) ?></label>
        <select id="priority" name="priority">
            <?php foreach (['low', 'medium', 'high'] as $p): ?>
                <option value="<?= e($p) ?>" <?= $formValue('priority', (string) $task['priority']) === $p ? 'selected' : '' ?>><?= e(t('tasks.priority.' . $p)) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="assigned_to_user_ids"><?= e(t('tasks.assign_to')) ?></label>
        <select id="assigned_to_user_ids" name="assigned_to_user_ids[]" multiple size="5">
            <?php foreach ($users as $u): ?>
                <option value="<?= e($u['id']) ?>" <?= in_array((int) $u['id'], $assigneeIds, true) ? 'selected' : '' ?>>
                    <?= e(trim($u['first_name'] . ' ' . ($u['last_name'] ?? ''))) ?><?= $isInactive($u) ? ' (' . e(t('tasks.assignee_inactive')) . ')' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button>
    </form>
</section>
<?php endif; ?>

<section class="card">
    <h2><?= e(t('common.attachments')) ?></h2>
    <ul class="attachment-list">
        <?php foreach ($attachments as $a): ?>
            <li>
                <a href="/tasks/<?= e($task['id']) ?>/attachments/<?= e($a['id']) ?>/download"><?= e($a['filename']) ?></a>
                <span class="muted">(<?= e(round($a['size'] / 1024)) ?> KB)</span>
                <?php if ($a['can_delete']): ?>
                    <form method="post" action="/tasks/<?= e($task['id']) ?>/attachments/<?= e($a['id']) ?>/delete" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        <?php if ($attachments === []): ?><li class="muted"><?= e(t('common.no_attachments_yet')) ?></li><?php endif; ?>
    </ul>
    <?php if ($actions['upload']): ?>
        <form method="post" action="/tasks/<?= e($task['id']) ?>/attachments" enctype="multipart/form-data" class="inline-form-row">
            <?= Csrf::field() ?>
            <input aria-label="<?= e(t('common.attachments')) ?>" type="file" name="file" required>
            <button type="submit" class="btn"><?= icon('upload') ?><?= e(t('common.upload')) ?></button>
        </form>
    <?php endif; ?>
</section>

<section class="card">
    <h2><?= e(t('tasks.comments')) ?></h2>
    <?php if ($actions['comment']): ?>
        <form method="post" action="/tasks/<?= e($task['id']) ?>/comments" class="card--form" style="max-width:100%">
            <?= Csrf::field() ?>
            <textarea name="body" rows="3" placeholder="<?= e(t('tasks.write_a_comment')) ?>" required></textarea>
            <button type="submit" class="btn btn--primary"><?= icon('message') ?><?= e(t('tasks.add_comment')) ?></button>
        </form>
    <?php endif; ?>
    <ul class="note-list">
        <?php foreach ($comments as $comment): ?>
            <li>
                <p><?= nl2br(e($comment['body'])) ?></p>
                <span class="muted ltr"><?= e($dateLabel((string) local_time($comment['created_at']))) ?></span>
                <?php if ($comment['can_delete']): ?>
                    <form method="post" action="/tasks/<?= e($task['id']) ?>/comments/<?= e($comment['id']) ?>/delete" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        <?php if ($comments === []): ?><li class="muted"><?= e(t('tasks.no_comments_yet')) ?></li><?php endif; ?>
    </ul>
</section>
