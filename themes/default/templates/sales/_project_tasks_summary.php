<?php
/**
 * A project's task counts and progress (TaskService::summaryFor()), shown
 * in the project's Tasks panel and in its Overview card
 * (Modules\Sales\ProjectTaskPanels). Presentation only.
 *
 * @var array{total: int, open: int, completed: int, overdue: int, progress: int} $summary
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;

$taskFacts = [
    ['activity', t('tasks.project_panel.progress'), $fmt->digits($summary['progress'] . '%')],
    ['clock', t('tasks.project_panel.open'), $fmt->number((string) $summary['open'])],
    ['check-circle', t('tasks.project_panel.completed'), $fmt->number((string) $summary['completed'])],
    ['alert-triangle', t('tasks.project_panel.overdue'), $fmt->number((string) $summary['overdue'])],
];
?>
<dl class="fact-row">
    <?php foreach ($taskFacts as [$factIcon, $factLabel, $factValue]): ?>
        <div class="fact">
            <dt><?= icon($factIcon) ?><?= e($factLabel) ?></dt>
            <dd><?= e($factValue) ?></dd>
        </div>
    <?php endforeach; ?>
</dl>
<progress class="progress<?= $summary['total'] > 0 && $summary['progress'] === 100 ? ' progress--done' : '' ?>" max="100" value="<?= (int) $summary['progress'] ?>" aria-label="<?= e(t('tasks.project_panel.progress')) ?>"></progress>
