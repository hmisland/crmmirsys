<?php
/**
 * Day / Week / List switch shared by the calendar and list pages.
 * Expects $current ('day'|'week'|'list') and $date (Y-m-d) in scope.
 *
 * @var string $current
 * @var string $date
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$views = [
    'day' => ['href' => '/appointments?view=day&date=' . $date, 'icon' => 'calendar', 'label' => t('appointments.view_day')],
    'week' => ['href' => '/appointments?view=week&date=' . $date, 'icon' => 'layers', 'label' => t('appointments.view_week')],
    'list' => ['href' => '/appointments/list', 'icon' => 'list', 'label' => t('appointments.view_list')],
];
?>
<nav class="segmented" aria-label="<?= e(t('appointments.views')) ?>">
    <?php foreach ($views as $key => $v): ?>
        <a class="btn btn--sm" href="<?= e($v['href']) ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>><?= icon($v['icon']) ?><?= e($v['label']) ?></a>
    <?php endforeach; ?>
</nav>
