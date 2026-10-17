<?php
/** @var array $task */
/** @var array $users active users + this task's current assignees */
/** @var array $assignees */
/** @var array $comments each with 'can_delete' */
/** @var array $attachments each with 'can_delete' */
/** @var array $errors */
/** @var array{statuses: string[], toggle: ?string, edit: bool, archive: bool, delete: bool, comment: bool, upload: bool} $actions */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
$assigneeIds = array_map('intval', array_column($assignees, 'id'));
$isInactive = fn (array $u): bool => ($u['status'] ?? 'active') !== 'active';
?>
<section class="card">
    <h1><?= e($task['title']) ?></h1>
    <p class="muted">
        <?= e(t('tasks.priority.' . $task['priority'])) ?> <?= e(t('tasks.priority_suffix')) ?>
        · <?= $task['due_at'] ? e(t('tasks.due')) . ' ' . e($task['due_at']) : e(t('tasks.no_due_date')) ?>
        · <?= badge(t('tasks.status.' . $task['status']), $task['status']) ?>
        <?= $task['related_type'] ? ' · ' . e(t('tasks.related.' . $task['related_type'])) . ' #' . e($task['related_id']) : '' ?>
    </p>
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
        <?php if ($actions['statuses'] !== []): ?>
            <form method="post" action="/tasks/<?= e($task['id']) ?>/status" class="inline-form-row">
                <?= Csrf::field() ?>
                <input type="hidden" name="return_to" value="/tasks/<?= e($task['id']) ?>">
                <select name="status" aria-label="<?= e(t('tasks.change_status')) ?>">
                    <?php foreach ($actions['statuses'] as $status): ?>
                        <option value="<?= e($status) ?>"><?= e(t('tasks.status.' . $status)) ?></option>
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

<?php if ($actions['edit']): ?>
<section class="card card--form">
    <h2><?= e(t('tasks.edit')) ?></h2>
    <form method="post" action="/tasks/<?= e($task['id']) ?>">
        <?= Csrf::field() ?>

        <label for="title"><?= e(t('tasks.field_title')) ?></label>
        <input type="text" id="title" name="title" value="<?= e($task['title']) ?>" required maxlength="255">
        <?php if ($fieldError('title')): ?><div class="field-error"><?= e($fieldError('title')) ?></div><?php endif; ?>

        <label for="description"><?= e(t('contacts.description')) ?></label>
        <textarea id="description" name="description" rows="4"><?= e($task['description'] ?? '') ?></textarea>

        <label for="due_at"><?= e(t('tasks.due_date')) ?></label>
        <input type="text" class="crm-datepicker" id="due_at" name="due_at" value="<?= e($task['due_at'] ? substr($task['due_at'], 0, 10) : '') ?>">

        <label for="priority"><?= e(t('tasks.priority_label')) ?></label>
        <select id="priority" name="priority">
            <?php foreach (['low', 'medium', 'high'] as $p): ?>
                <option value="<?= e($p) ?>" <?= $task['priority'] === $p ? 'selected' : '' ?>><?= e(t('tasks.priority.' . $p)) ?></option>
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
                <span class="muted"><?= e(local_time($comment['created_at'])) ?></span>
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
