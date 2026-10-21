<?php
/**
 * Body of the "Projects with overdue tasks" box on the Projects dashboard
 * (Modules\Sales\ProjectTaskPanels); heading and card from
 * partials/related-panels.php. Rendered only when at least one is late.
 *
 * @var array<int, array{id: int, label: string, open: int, overdue: int}> $rows most overdue first
 * @var int $total projects with overdue tasks
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;
?>
<div class="table-responsive">
<table class="table table--compact">
    <thead><tr>
        <th><?= e(t('projects.record')) ?></th>
        <th><?= e(t('tasks.project_panel.open')) ?></th>
        <th><?= e(t('tasks.project_panel.overdue')) ?></th>
        <th><span class="visually-hidden"><?= e(t('tasks.actions')) ?></span></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td><a href="/projects/<?= e($row['id']) ?>"><?= e($row['label']) ?></a></td>
            <td><?= e($fmt->number((string) $row['open'])) ?></td>
            <td><span class="badge badge--danger"><?= e($fmt->number((string) $row['overdue'])) ?></span></td>
            <td class="nowrap">
                <a href="/tasks/projects/<?= e($row['id']) ?>/board" class="link-button"><?= icon('kanban') ?><?= e(t('tasks.project_board.open')) ?></a>
                <a href="/tasks?<?= e(http_build_query(['project_id' => $row['id']])) ?>" class="link-button"><?= icon('list') ?><?= e(t('tasks.project_board.list')) ?></a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php if ($total > count($rows)): ?>
    <p class="muted"><?= e(t('tasks.project_dashboard.showing', ['shown' => $fmt->number((string) count($rows)), 'total' => $fmt->number((string) $total)])) ?></p>
<?php endif; ?>
