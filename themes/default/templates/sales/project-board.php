<?php
/**
 * One project's tasks as a board (TaskController::projectBoard()): one
 * column per organization status (active ones, plus an inactive status a
 * task still carries), in the organization's order. Only the project's
 * tasks this user may see. A card moves through the usual status route
 * (POST /tasks/{id}/status: RBAC, CSRF, validation) with the targets
 * actionsFor() allows — the Sales / Support boards' form pattern.
 *
 * @var array{id: int, label: string, url: ?string} $project
 * @var array<int, array{status: array, tasks: array}> $columns
 * @var array<int, array> $taskActions TaskService::actionsFor()
 * @var array<int, string[]> $assignees task id => names
 * @var array<int, array> $taskStatuses TaskService::statusMap()
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;

$boardUrl = '/tasks/projects/' . (int) $project['id'] . '/board';
$projectUrl = $project['url'] ?? ('/projects/' . (int) $project['id']);
$hasTasks = array_sum(array_map(fn (array $c) => count($c['tasks']), $columns)) > 0;
?>
<section class="card">
    <h1><?= e(t('tasks.project_board.title')) ?></h1>
    <p class="muted"><?= e($project['label']) ?></p>
    <div class="actions">
        <a href="<?= e($projectUrl) ?>" class="btn"><?= icon('arrow-left') ?><?= e(t('tasks.project_board.back')) ?></a>
        <a href="/tasks?<?= e(http_build_query(['project_id' => (int) $project['id']])) ?>" class="btn btn--ghost"><?= icon('list') ?><?= e(t('tasks.project_board.list')) ?></a>
    </div>
    <?php if (!$hasTasks): ?>
        <p class="muted"><?= e(t('tasks.project_panel.empty')) ?></p>
    <?php endif; ?>
</section>

<div class="kanban">
    <?php foreach ($columns as $column): ?>
        <?php $columnStatus = $column['status']; ?>
        <div class="kanban__column">
            <h3><?= e($columnStatus['label']) ?> <span class="muted">(<?= e($fmt->number((string) count($column['tasks']))) ?>)</span></h3>
            <?php foreach ($column['tasks'] as $task): ?>
                <?php $options = ($taskActions[(int) $task['id']] ?? [])['status_options'] ?? []; ?>
                <div class="kanban__card">
                    <a href="/tasks/<?= e($task['id']) ?>"><?= e($task['title']) ?></a>
                    <div class="muted">
                        <?php include __DIR__ . '/_task_status.php'; ?>
                        · <?= e(t('tasks.priority.' . $task['priority'])) ?>
                        <?php if (($assignees[(int) $task['id']] ?? []) !== []): ?> · <?= e(implode(', ', $assignees[(int) $task['id']])) ?><?php endif; ?>
                        <?php if (!empty($task['due_at'])): ?> · <span class="ltr"><?= e($fmt->date($task['due_at'])) ?></span><?php endif; ?>
                    </div>
                    <?php if ($options !== []): ?>
                        <form method="post" action="/tasks/<?= e($task['id']) ?>/status" class="kanban__move inline-form-row">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="return_to" value="<?= e($boardUrl) ?>">
                            <select name="status_id" aria-label="<?= e(t('tasks.change_status')) ?>">
                                <?php foreach ($options as $option): ?>
                                    <option value="<?= e($option['id']) ?>"><?= e($option['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn--sm"><?= icon('arrow-left-right') ?><?= e(t('tasks.project_board.move')) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($column['tasks'] === []): ?><p class="muted"><?= e(t('tasks.project_board.column_empty')) ?></p><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
