<?php
/**
 * The Task Manager's tiles on the Projects dashboard (Modules\Sales\ProjectTaskPanels):
 * open and overdue tasks of the open projects the user may see, counted with
 * the user's task visibility (TaskService::deadlinesForProjects()).
 *
 * @var int $open
 * @var int $overdue
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;
?>
<div class="card kpi">
    <span class="kpi__top">
        <span class="kpi__label"><?= e(t('tasks.project_dashboard.open')) ?></span>
        <span class="kpi__icon kpi__icon--info"><?= icon('check-square') ?></span>
    </span>
    <span class="kpi__value"><?= e($fmt->number((string) $open)) ?></span>
</div>
<div class="card kpi">
    <span class="kpi__top">
        <span class="kpi__label"><?= e(t('tasks.project_dashboard.overdue')) ?></span>
        <span class="kpi__icon kpi__icon--<?= $overdue > 0 ? 'danger' : 'success' ?>"><?= icon('alert-triangle') ?></span>
    </span>
    <span class="kpi__value"><?= e($fmt->number((string) $overdue)) ?></span>
</div>
