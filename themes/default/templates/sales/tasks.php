<?php
/** @var array $tasks */
/** @var array $users */
/** @var string $view one of TaskService::LIST_VIEWS */
/** @var bool $canListAll */
/** @var bool $archived */
/** @var string $statusFilter */
/** @var array<string, bool> $can */
/** @var array<int, array> $taskActions */
/** @var \App\Infrastructure\Theme\ThemeLoader $theme */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Csrf;
use Modules\Sales\Services\TaskService;

$query = array_filter(['view' => $view !== 'mine' ? $view : '', 'status' => $statusFilter]);
$returnTo = '/tasks' . ($query !== [] ? '?' . http_build_query($query) : '');
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
?>
<section class="card">
    <div class="card__header">
        <div>
            <h1><?= e(t($titleKey)) ?></h1>
            <p class="muted"><?= e(t($hintKey)) ?></p>
        </div>
        <nav class="segmented" aria-label="<?= e(t('tasks.my_tasks')) ?>">
            <?php foreach ($tabs as $key => [$href, $tabIcon, $labelKey]): ?>
                <a class="btn btn--sm" href="<?= e($href) ?>"<?= $view === $key ? ' aria-current="page"' : '' ?>><?= icon($tabIcon) ?><?= e(t($labelKey)) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>

    <form method="get" action="/tasks" class="inline-form-row">
        <?php if ($view !== 'mine'): ?><input type="hidden" name="view" value="<?= e($view) ?>"><?php endif; ?>
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

<?php if ($can['create']): ?>
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
<?php endif; ?>
