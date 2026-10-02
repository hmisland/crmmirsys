<?php
/** @var array $tasks */
/** @var array $users */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(t('tasks.my_tasks')) ?></h1>
    <p class="muted"><?= e(t('tasks.my_tasks_hint')) ?></p>

    <ul class="note-list">
        <?php foreach ($tasks as $task): ?>
            <li>
                <form method="post" action="/tasks/<?= e($task['id']) ?>/toggle" class="inline-form">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="return_to" value="/tasks">
                    <button type="submit" class="checkbox-toggle" title="<?= e(t('tasks.toggle_title')) ?>">
                        <?= $task['status'] === 'completed' ? '✓' : '' ?>
                    </button>
                </form>
                <a href="/tasks/<?= e($task['id']) ?>"><strong><?= e($task['title']) ?></strong></a>
                <span class="muted">
                    <?= $task['due_at'] ? e(t('tasks.due')) . ' ' . e($task['due_at']) : e(t('tasks.no_due_date')) ?>
                    · <?= e(t('tasks.priority.' . $task['priority'])) ?> <?= e(t('tasks.priority_suffix')) ?>
                    <?= $task['related_type'] ? ' · ' . e(t('tasks.related.' . $task['related_type'])) . ' #' . e($task['related_id']) : '' ?>
                </span>
            </li>
        <?php endforeach; ?>
        <?php if ($tasks === []): ?><li class="muted"><?= e(t('tasks.nothing_upcoming')) ?></li><?php endif; ?>
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
        <textarea id="description" name="description" rows="3" style="width:100%;padding:0.55rem;border:1px solid var(--color-border);border-radius:6px;font-family:inherit;"></textarea>

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

        <button type="submit" class="btn btn--primary"><?= e(t('tasks.add')) ?></button>
    </form>
</section>
