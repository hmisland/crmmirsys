<?php
/** @var array $tasks */
/** @var array $users */
/** @var string $view one of TaskService::LIST_VIEWS */
/** @var bool $canListAll */
/** @var bool $archived */
/** @var string $statusFilter the older ?status= (legacy value) */
/** @var ?int $statusIdFilter the custom status filter (T8) */
/** @var array<int, array> $taskStatuses the organization's statuses by id */
/** @var array $createStatuses the statuses a new task may start in */
/** @var bool $canManageStatuses */
/** @var string $search the ?q= search text */
/** @var ?int $projectFilter the Project filter (a project this user may see), null = all */
/** @var array<int, string> $projectOptions projects this user may see (id => label) */
/** @var array $listQuery the list's view / status / q / page / per_page (kept by links and actions) */
/** @var \App\Infrastructure\Pagination\Pagination $pagination */
/** @var array<string, bool> $can */
/** @var array<int, array> $taskActions */
/** @var bool $openCreate render the "New task" dialog open (?new=1, or after a failed create) */
/** @var array $old the submitted values after a failed create */
/** @var array<string, string[]> $errors field errors after a failed create */
/** @var \App\Infrastructure\Localization\LocaleFormatter $fmt */
/** @var array $relatedOptions records the user may pick in the dialog */
/** @var bool $canManageLabels */
/** @var int $reminderUnread the user's unread reminder notifications */
/** @var \App\Infrastructure\Theme\ThemeLoader $theme */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\help_link;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Csrf;
use Modules\Sales\Services\TaskService;

// Actions come back to this exact list: view, filter, search and page.
$returnTo = '/tasks' . ($listQuery !== [] ? '?' . http_build_query($listQuery) : '');
$tabs = ['mine' => ['/tasks', 'check-square', 'tasks.view_active']];
$tabs['created'] = ['/tasks?view=created', 'user', 'tasks.view_created'];
if ($canListAll) {
    $tabs['all'] = ['/tasks?view=all', 'users', 'tasks.view_all'];
}
$tabs['archived'] = ['/tasks?view=archived', 'archive', 'tasks.view_archived'];
[$titleKey, $hintKey] = match ($view) {
    'created' => ['tasks.view_created', 'tasks.created_hint'],
    'all' => ['tasks.view_all', 'tasks.all_hint'],
    'archived' => ['tasks.my_tasks', 'tasks.archive_hint'],
    default => ['tasks.my_tasks', 'tasks.my_tasks_hint'],
};
// Without JavaScript the button is a plain link that renders the dialog open.
$newTaskHref = $returnTo . (str_contains($returnTo, '?') ? '&' : '?') . 'new=1';
?>
<section class="card">
    <div class="card__header">
        <div>
            <h1><?= e(t($titleKey)) ?><?= help_link('tasks-getting-started') ?></h1>
            <p class="muted"><?= e(t($hintKey)) ?></p>
        </div>
        <div class="actions">
            <nav class="segmented" aria-label="<?= e(t('tasks.my_tasks')) ?>">
                <?php foreach ($tabs as $key => [$href, $tabIcon, $labelKey]): ?>
                    <a class="btn btn--sm" href="<?= e($href) ?>"<?= $view === $key ? ' aria-current="page"' : '' ?>><?= icon($tabIcon) ?><?= e(t($labelKey)) ?></a>
                <?php endforeach; ?>
            </nav>
            <a class="btn" href="/tasks/reminders"><?= icon('bell') ?><?= e(t('tasks.reminders')) ?><?php if ($reminderUnread > 0): ?> <span class="badge badge--info badge--plain"><?= e($fmt->digits((string) $reminderUnread)) ?></span><?php endif; ?></a>
            <a class="btn" href="<?= e('/tasks/calendar' . ($view === 'created' || $view === 'all' ? '?view=' . $view : '')) ?>"><?= icon('calendar') ?><?= e(t('tasks.calendar.open')) ?></a>
            <?php if ($canManageStatuses): ?>
                <a class="btn" href="/tasks/statuses"><?= icon('sliders') ?><?= e(t('tasks.statuses_manage')) ?></a>
            <?php endif; ?>
            <?php if ($canManageLabels): ?>
                <a class="btn" href="/tasks/labels"><?= icon('tag') ?><?= e(t('tasks.labels_manage')) ?></a>
            <?php endif; ?>
            <?php if ($can['create']): ?>
                <a class="btn btn--primary" href="<?= e($newTaskHref) ?>" data-open-dialog="task-create-dialog"><?= icon('plus') ?><?= e(t('tasks.new_task')) ?></a>
            <?php endif; ?>
        </div>
    </div>

    <form method="get" action="/tasks" class="inline-form-row filter-bar">
        <?php if ($view !== 'mine'): ?><input type="hidden" name="view" value="<?= e($view) ?>"><?php endif; ?>
        <input type="text" name="q" value="<?= e($search) ?>" maxlength="100" placeholder="<?= e(t('tasks.search_placeholder')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
        <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
        <select name="status_id" aria-label="<?= e(t('tasks.status_label')) ?>">
            <option value=""><?= e(t('tasks.all_statuses')) ?></option>
            <?php foreach ($taskStatuses as $option): ?>
                <option value="<?= e($option['id']) ?>"<?= $statusIdFilter === (int) $option['id'] ? ' selected' : '' ?>><?= e($option['label']) ?><?= (int) $option['is_active'] !== 1 ? ' (' . e(t('tasks.status_inactive')) . ')' : '' ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($projectOptions !== [] || $projectFilter !== null): ?>
            <select name="project_id" aria-label="<?= e(t('tasks.project_filter.label')) ?>">
                <option value=""><?= e(t('tasks.project_filter.all')) ?></option>
                <?php foreach ($projectOptions as $optionId => $optionLabel): ?>
                    <option value="<?= e($optionId) ?>"<?= $projectFilter === (int) $optionId ? ' selected' : '' ?>><?= e($optionLabel) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('tasks.filter')) ?></button>
        <?php if ($search !== '' || $statusFilter !== '' || $statusIdFilter !== null || $projectFilter !== null): ?>
            <a href="/tasks<?= $view !== 'mine' ? '?view=' . e($view) : '' ?>" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a>
        <?php endif; ?>
    </form>

    <?php include $theme->templatesPath() . '/partials/pagination.php'; ?>

    <ul class="item-list task-list">
        <?php foreach ($tasks as $task): ?>
            <?php include $theme->templatesPath() . '/sales/_task_row.php'; ?>
        <?php endforeach; ?>
        <?php if ($tasks === []): ?><li class="item-list--empty"><?= e(t($search !== '' ? 'tasks.search_no_results' : ($archived ? 'tasks.archive_empty' : 'tasks.nothing_upcoming'))) ?></li><?php endif; ?>
    </ul>
</section>

<?php if ($can['create']): ?>
<?php
$old ??= [];
$errors ??= [];
$fieldError = fn (string $field): ?string => isset($errors[$field][0]) ? (string) $errors[$field][0] : null;
$oldText = fn (string $field): string => is_string($old[$field] ?? null) ? $old[$field] : '';
$oldPriority = in_array($old['priority'] ?? null, ['low', 'medium', 'high'], true) ? $old['priority'] : 'medium';
$oldAssignees = isset($old['assigned_to_user_ids']) && is_array($old['assigned_to_user_ids'])
    ? array_map('intval', $old['assigned_to_user_ids'])
    : [(int) CurrentUser::id()];
// Messages for fields this form does not show (e.g. a tampered related record) go in the summary.
$otherErrors = array_merge(...array_values(array_diff_key($errors, array_flip(['status_id', 'title', 'start_at', 'start_time', 'due_at', 'due_time', 'remind_at', 'remind_time', 'recur_type', 'recur_interval', 'recur_until', 'related', 'assigned_to_user_ids']))) ?: [[]]);
?>
<dialog class="modal modal--wide" id="task-create-dialog" aria-labelledby="task-create-title"<?= $openCreate ? ' data-autoopen open' : '' ?>>
    <div class="modal__head">
        <h2 class="modal__title" id="task-create-title"><?= e(t('tasks.add_standalone')) ?></h2>
        <form method="dialog">
            <button type="submit" class="btn btn--ghost btn--icon" aria-label="<?= e(t('common.close')) ?>"><?= icon('x') ?></button>
        </form>
    </div>
    <div class="modal__content">
        <?php if ($errors !== []): ?>
            <div class="alert alert--error" role="alert">
                <div class="alert__body">
                    <?= e(t('tasks.form_has_errors')) ?>
                    <?php foreach ($otherErrors as $message): ?><br><?= e($message) ?><?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <form method="post" action="/tasks" class="form-stack">
            <?= Csrf::field() ?>
            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">

            <label for="task-new-title"><?= e(t('tasks.field_title')) ?></label>
            <input type="text" id="task-new-title" name="title" value="<?= e($oldText('title')) ?>" required maxlength="255">
            <?php if ($fieldError('title')): ?><div class="field-error"><?= e($fieldError('title')) ?></div><?php endif; ?>

            <label for="task-new-description"><?= e(t('contacts.description')) ?></label>
            <textarea id="task-new-description" name="description" rows="3"><?= e($oldText('description')) ?></textarea>

            <label for="task-new-start"><?= e(t('tasks.start_date')) ?></label>
            <input type="text" class="crm-datepicker" id="task-new-start" name="start_at" value="<?= e($oldText('start_at')) ?>">
            <?php if ($fieldError('start_at')): ?><div class="field-error"><?= e($fieldError('start_at')) ?></div><?php endif; ?>
            <label for="task-new-start-time"><?= e(t('tasks.start_time')) ?></label>
            <input type="time" class="field-sm" id="task-new-start-time" name="start_time" value="<?= e($oldText('start_time')) ?>">
            <?php if ($fieldError('start_time')): ?><div class="field-error"><?= e($fieldError('start_time')) ?></div><?php endif; ?>

            <label for="task-new-due"><?= e(t('tasks.due_date')) ?></label>
            <input type="text" class="crm-datepicker" id="task-new-due" name="due_at" value="<?= e($oldText('due_at')) ?>">
            <?php if ($fieldError('due_at')): ?><div class="field-error"><?= e($fieldError('due_at')) ?></div><?php endif; ?>
            <label for="task-new-due-time"><?= e(t('tasks.due_time')) ?></label>
            <input type="time" class="field-sm" id="task-new-due-time" name="due_time" value="<?= e($oldText('due_time')) ?>">
            <?php if ($fieldError('due_time')): ?><div class="field-error"><?= e($fieldError('due_time')) ?></div><?php endif; ?>
            <p class="muted text-sm"><?= e(t('tasks.time_hint')) ?></p>

            <label for="task-new-remind"><?= e(t('tasks.remind_date')) ?></label>
            <input type="text" class="crm-datepicker" id="task-new-remind" name="remind_at" value="<?= e($oldText('remind_at')) ?>">
            <?php if ($fieldError('remind_at')): ?><div class="field-error"><?= e($fieldError('remind_at')) ?></div><?php endif; ?>
            <label for="task-new-remind-time"><?= e(t('tasks.remind_time')) ?></label>
            <input type="time" class="field-sm" id="task-new-remind-time" name="remind_time" value="<?= e($oldText('remind_time')) ?>">
            <?php if ($fieldError('remind_time')): ?><div class="field-error"><?= e($fieldError('remind_time')) ?></div><?php endif; ?>
            <p class="muted text-sm"><?= e(t('tasks.remind_hint')) ?></p>
            <input type="hidden" name="remind_email" value="0">
            <label class="check"><input type="checkbox" name="remind_email" value="1"<?= $oldText('remind_email') === '1' ? ' checked' : '' ?>> <?= e(t('tasks.remind_email')) ?></label>

            <label for="task-new-recur"><?= e(t('tasks.recur.label')) ?></label>
            <select id="task-new-recur" name="recur_type">
                <option value=""><?= e(t('tasks.recur.none')) ?></option>
                <?php foreach (TaskService::RECURRENCES as $recur): ?>
                    <option value="<?= e($recur) ?>"<?= $oldText('recur_type') === $recur ? ' selected' : '' ?>><?= e(t('tasks.recur.unit_' . $recur)) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($fieldError('recur_type')): ?><div class="field-error"><?= e($fieldError('recur_type')) ?></div><?php endif; ?>
            <label for="task-new-recur-interval"><?= e(t('tasks.recur.interval')) ?></label>
            <input type="number" class="field-sm" id="task-new-recur-interval" name="recur_interval" min="1" max="<?= TaskService::MAX_RECUR_INTERVAL ?>" value="<?= e($oldText('recur_interval') !== '' ? $oldText('recur_interval') : '1') ?>">
            <?php if ($fieldError('recur_interval')): ?><div class="field-error"><?= e($fieldError('recur_interval')) ?></div><?php endif; ?>
            <label for="task-new-recur-until"><?= e(t('tasks.recur.until_label')) ?></label>
            <input type="text" class="crm-datepicker" id="task-new-recur-until" name="recur_until" value="<?= e($oldText('recur_until')) ?>">
            <?php if ($fieldError('recur_until')): ?><div class="field-error"><?= e($fieldError('recur_until')) ?></div><?php endif; ?>
            <p class="muted text-sm"><?= e(t('tasks.recur.hint')) ?></p>

            <label for="task-new-related"><?= e(t('tasks.related_label')) ?></label>
            <?php $selectId = 'task-new-related'; $selectedRelated = $oldText('related'); $keepRelated = null; include $theme->templatesPath() . '/sales/_related_select.php'; ?>
            <?php if ($fieldError('related')): ?><div class="field-error"><?= e($fieldError('related')) ?></div><?php endif; ?>

            <label for="task-new-status"><?= e(t('tasks.status_label')) ?></label>
            <select id="task-new-status" name="status_id">
                <?php foreach ($createStatuses as $i => $option): ?>
                    <option value="<?= e($option['id']) ?>"<?= ($oldText('status_id') !== '' ? $oldText('status_id') === (string) $option['id'] : $i === 0) ? ' selected' : '' ?>><?= e($option['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($fieldError('status_id')): ?><div class="field-error"><?= e($fieldError('status_id')) ?></div><?php endif; ?>

            <label for="task-new-priority"><?= e(t('tasks.priority_label')) ?></label>
            <select id="task-new-priority" name="priority">
                <?php foreach (['low', 'medium', 'high'] as $p): ?>
                    <option value="<?= e($p) ?>"<?= $oldPriority === $p ? ' selected' : '' ?>><?= e(t('tasks.priority.' . $p)) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="task-new-assignees"><?= e(t('tasks.assign_to')) ?></label>
            <select id="task-new-assignees" name="assigned_to_user_ids[]" multiple size="5">
                <?php foreach ($users as $u): ?>
                    <option value="<?= e($u['id']) ?>"<?= in_array((int) $u['id'], $oldAssignees, true) ? ' selected' : '' ?>>
                        <?= e(trim($u['first_name'] . ' ' . ($u['last_name'] ?? ''))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($fieldError('assigned_to_user_ids')): ?><div class="field-error"><?= e($fieldError('assigned_to_user_ids')) ?></div><?php endif; ?>

            <div class="actions">
                <button type="submit" class="btn btn--primary"><?= icon('plus') ?><?= e(t('tasks.add')) ?></button>
                <button type="submit" class="btn" formmethod="dialog" formnovalidate><?= icon('x') ?><?= e(t('common.cancel')) ?></button>
            </div>
        </form>
    </div>
</dialog>
<?php endif; ?>
