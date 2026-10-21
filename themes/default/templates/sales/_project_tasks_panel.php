<?php
/**
 * Body of the "Tasks" panel on a project page (Modules\Sales\ProjectTaskPanels).
 * The heading and card come from partials/related-panels.php. Rows are the
 * project's tasks this user may see (TaskService::visibleForSubject()); the
 * quick add creates an ordinary task related to the project, back here.
 *
 * @var int $projectId
 * @var array $tasks
 * @var array $summary TaskService::summaryFor()
 * @var array<int, string[]> $assignees task id => names
 * @var array<int, array> $taskStatuses TaskService::statusMap()
 * @var bool $canCreate
 * @var array $users assignable users
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 * @var \App\Infrastructure\Theme\ThemeLoader $theme
 */

use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;

$returnTo = '/projects/' . $projectId;
include __DIR__ . '/_project_tasks_summary.php';
?>
<div class="actions">
    <a href="/tasks/projects/<?= e($projectId) ?>/board" class="btn btn--sm"><?= icon('kanban') ?><?= e(t('tasks.project_board.open')) ?></a>
</div>
<div class="table-responsive">
<table class="table table--compact">
    <thead><tr>
        <th><?= e(t('tasks.field_title')) ?></th>
        <th><?= e(t('tasks.status_label')) ?></th>
        <th><?= e(t('tasks.priority_label')) ?></th>
        <th><?= e(t('tasks.project_panel.assignees')) ?></th>
        <th><?= e(t('tasks.due_date')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($tasks as $task): ?>
        <tr>
            <td><a href="/tasks/<?= e($task['id']) ?>"><?= e($task['title']) ?></a></td>
            <td><?php include __DIR__ . '/_task_status.php'; ?></td>
            <td><?= e(t('tasks.priority.' . $task['priority'])) ?></td>
            <td><?= e(implode(', ', $assignees[(int) $task['id']] ?? []) ?: '—') ?></td>
            <td class="nowrap"><?php if (!empty($task['due_at'])): ?><span class="ltr"><?= e($fmt->date($task['due_at'])) ?></span><?php else: ?><span class="muted"><?= e(t('tasks.no_due_date')) ?></span><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($tasks === []): ?>
        <tr><td colspan="5" class="muted"><?= e(t('tasks.project_panel.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<?php if ($canCreate): ?>
<form method="post" action="/tasks" class="inline-form-row">
    <?= Csrf::field() ?>
    <input type="hidden" name="related_type" value="project">
    <input type="hidden" name="related_id" value="<?= e($projectId) ?>">
    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
    <input type="text" name="title" placeholder="<?= e(t('tasks.new_placeholder')) ?>" aria-label="<?= e(t('tasks.field_title')) ?>" required>
    <input type="text" class="crm-datepicker" name="due_at" placeholder="<?= e(t('tasks.due_date')) ?>" aria-label="<?= e(t('tasks.due_date')) ?>">
    <select name="assigned_to_user_ids[]" multiple size="3" aria-label="<?= e(t('tasks.assigned_to')) ?>">
        <?php foreach ($users as $u): ?>
            <option value="<?= e($u['id']) ?>"<?= (int) $u['id'] === (int) CurrentUser::id() ? ' selected' : '' ?>><?= e(trim($u['first_name'] . ' ' . ($u['last_name'] ?? ''))) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn"><?= icon('plus') ?><?= e(t('tasks.project_panel.new')) ?></button>
</form>
<p class="muted"><?= e(t('tasks.more_options_hint')) ?></p>
<?php endif; ?>
