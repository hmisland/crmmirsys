<?php
/** @var array $tasks */
/** @var array $users */
/** @var bool $archived */
/** @var string $statusFilter */
/** @var array<string, bool> $can */
/** @var \App\Infrastructure\Theme\ThemeLoader $theme */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Csrf;
use Modules\Sales\Services\TaskService;

$returnTo = '/tasks' . ($archived ? '?view=archived' : '') . ($statusFilter !== '' ? ($archived ? '&' : '?') . 'status=' . $statusFilter : '');
?>
<section class="card">
    <div class="card__header">
        <div>
            <h1><?= e(t('tasks.my_tasks')) ?></h1>
            <p class="muted"><?= e(t($archived ? 'tasks.archive_hint' : 'tasks.my_tasks_hint')) ?></p>
        </div>
        <nav class="segmented" aria-label="<?= e(t('tasks.my_tasks')) ?>">
            <a class="btn btn--sm" href="/tasks"<?= !$archived ? ' aria-current="page"' : '' ?>><?= icon('check-square') ?><?= e(t('tasks.view_active')) ?></a>
            <a class="btn btn--sm" href="/tasks?view=archived"<?= $archived ? ' aria-current="page"' : '' ?>><?= icon('archive') ?><?= e(t('tasks.view_archived')) ?></a>
        </nav>
    </div>

    <form method="get" action="/tasks" class="inline-form-row">
        <?php if ($archived): ?><input type="hidden" name="view" value="archived"><?php endif; ?>
        <select name="status" aria-label="<?= e(t('tasks.status_label')) ?>">
            <option value=""><?= e(t('tasks.all_statuses')) ?></option>
            <?php foreach (TaskService::STATUSES as $status): ?>
                <option value="<?= e($status) ?>"<?= $statusFilter === $status ? ' selected' : '' ?>><?= e(t('tasks.status.' . $status)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('tasks.filter')) ?></button>
    </form>

    <ul class="item-list task-list">
        <?php foreach ($tasks as $task): ?>
            <?php include $theme->templatesPath() . '/sales/_task_row.php'; ?>
        <?php endforeach; ?>
        <?php if ($tasks === []): ?><li class="item-list--empty"><?= e(t($archived ? 'tasks.archive_empty' : 'tasks.nothing_upcoming')) ?></li><?php endif; ?>
    </ul>
</section>

<section class="card card--form">
    <h2><?= e(t('tasks.add_standalone')) ?></h2>
    <form method="post" action="/tasks">
        <?= Csrf::field() ?>
        <input type="hidden" name="return_to" value="/tasks">

        <label for="title"><?= e(t('tasks.field_title')) ?></label>
        <input type="text" id="title" name="title" required>

        <label for="description"><?= e(t('contacts.description')) ?></label>
        <textarea id="description" name="description" rows="3"></textarea>

        <label for="due_at"><?= e(t('tasks.due_date')) ?></label>
        <input type="text" class="crm-datepicker" id="due_at" name="due_at">

        <label for="priority"><?= e(t('tasks.priority_label')) ?></label>
        <select id="priority" name="priority">
            <option value="low"><?= e(t('tasks.priority.low')) ?></option>
            <option value="medium" selected><?= e(t('tasks.priority.medium')) ?></option>
            <option value="high"><?= e(t('tasks.priority.high')) ?></option>
        </select>

        <label for="assigned_to_user_ids"><?= e(t('tasks.assign_to')) ?></label>
        <select id="assigned_to_user_ids" name="assigned_to_user_ids[]" multiple size="5">
            <?php foreach ($users as $u): ?>
                <option value="<?= e($u['id']) ?>" <?= (int) $u['id'] === (int) CurrentUser::id() ? 'selected' : '' ?>>
                    <?= e(trim($u['first_name'] . ' ' . ($u['last_name'] ?? ''))) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn--primary"><?= icon('plus') ?><?= e(t('tasks.add')) ?></button>
    </form>
</section>
