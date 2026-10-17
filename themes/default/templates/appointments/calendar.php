<?php
/**
 * Appointment calendar: a day grid (time rows × resource columns) or a
 * week overview. View-model data from AppointmentController::calendar().
 *
 * @var string $view
 * @var string $date
 * @var string $prev
 * @var string $next
 * @var int|null $resourceId
 * @var array $resources
 * @var array|null $grid
 * @var array|null $week
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 * @var bool $canCreate
 * @var bool $canManage
 * @var \App\Infrastructure\Theme\ThemeLoader $theme
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_today;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$base = '/appointments?view=' . $view . ($resourceId !== null ? '&resource_id=' . $resourceId : '') . '&date=';
$heading = $view === 'week'
    ? $fmt->date($week[0]['date']) . ' – ' . $fmt->date($week[6]['date'])
    : t('appointments.weekday.' . date('w', strtotime($date))) . ' ' . $fmt->date($date);
$current = $view;
$bookUrl = function (?int $resource, string $day, string $time = ''): string {
    $query = ['date' => $day];
    if ($time !== '') {
        $query['time'] = $time;
    }
    if ($resource !== null) {
        $query['resource_id'] = $resource;
    }

    return '/appointments/create?' . http_build_query($query);
};
?>
<div class="page-head">
    <div>
        <h1><?= e(t('appointments.title')) ?></h1>
        <p><?= e(t('appointments.subtitle')) ?></p>
    </div>
    <div class="quick-actions">
        <?php if ($canCreate): ?>
            <a href="<?= e($bookUrl($resourceId, $date)) ?>" class="btn btn--primary"><?= icon('plus') ?><?= e(t('appointments.new')) ?></a>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <a href="/appointments/settings" class="btn"><?= icon('settings') ?><?= e(t('appointments.settings')) ?></a>
        <?php endif; ?>
    </div>
</div>

<section class="card">
    <div class="appt-toolbar">
        <?php include $theme->templatesPath() . '/appointments/_views.php'; ?>

        <div class="appt-datenav">
            <a class="btn btn--sm" href="<?= e($base . $prev) ?>"><?= icon('chevron-left') ?><?= e(t($view === 'week' ? 'appointments.prev_week' : 'appointments.prev_day')) ?></a>
            <a class="btn btn--sm" href="<?= e($base . local_today()) ?>"><?= icon('calendar-check') ?><?= e(t('appointments.today')) ?></a>
            <a class="btn btn--sm" href="<?= e($base . $next) ?>"><?= e(t($view === 'week' ? 'appointments.next_week' : 'appointments.next_day')) ?><?= icon('chevron-right') ?></a>
            <strong class="appt-datenav__label"><?= e($heading) ?></strong>
        </div>
    </div>

    <form method="get" action="/appointments" class="inline-form-row">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <input type="text" class="crm-datepicker" name="date" value="<?= e($date) ?>" aria-label="<?= e(t('appointments.date')) ?>">
        <select name="resource_id" aria-label="<?= e(t('appointments.resource')) ?>">
            <option value=""><?= e(t('appointments.all_resources')) ?></option>
            <?php foreach ($resources as $r): ?>
                <option value="<?= e($r['id']) ?>"<?= $resourceId === (int) $r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('appointments.show')) ?></button>
    </form>

    <?php if ($resources === [] && $canManage): ?>
        <div class="alert alert--info"><div class="alert__body"><?= e(t('appointments.no_resources_hint')) ?> <a href="/appointments/settings/resources/create"><?= e(t('appointments.new_resource')) ?></a></div></div>
    <?php endif; ?>

    <?php if ($view === 'day'): ?>
        <div class="table-responsive">
            <table class="table appt-grid">
                <thead>
                    <tr>
                        <th class="appt-grid__time"><?= e(t('appointments.time')) ?></th>
                        <?php foreach ($grid['columns'] as $col): ?>
                            <th><?= e($col['id'] === null ? t('appointments.no_resource') : $col['name']) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($grid['rows'] as $row): ?>
                    <tr>
                        <th scope="row" class="appt-grid__time"><span class="ltr"><?= e($fmt->digits($row['time'])) ?></span></th>
                        <?php foreach ($row['cells'] as $i => $cell): ?>
                            <?php $col = $grid['columns'][$i]; ?>
                            <td class="appt-slot appt-slot--<?= e($cell['state']) ?>">
                                <?php foreach ($cell['items'] as $item): ?>
                                    <?php include $theme->templatesPath() . '/appointments/_chip.php'; ?>
                                <?php endforeach; ?>
                                <?php if ($cell['state'] === 'open' && $canCreate): ?>
                                    <a class="appt-slot__add" href="<?= e($bookUrl($col['id'], $date, $row['time'])) ?>"><?= icon('plus', 'icon--sm') ?><?= e(t('appointments.book')) ?></a>
                                <?php elseif ($cell['state'] === 'closed'): ?>
                                    <span class="visually-hidden"><?= e(t('appointments.closed')) ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="appt-legend">
            <span class="appt-legend__item appt-legend__item--busy"><?= e(t('appointments.legend_busy')) ?></span>
            <span class="appt-legend__item appt-legend__item--closed"><?= e(t('appointments.legend_closed')) ?></span>
        </p>
    <?php else: ?>
        <div class="appt-week">
            <?php foreach ($week as $day): ?>
                <section class="appt-day<?= $day['is_today'] ? ' appt-day--today' : '' ?>">
                    <h2 class="appt-day__head">
                        <a href="<?= e('/appointments?view=day' . ($resourceId !== null ? '&resource_id=' . $resourceId : '') . '&date=' . $day['date']) ?>">
                            <span><?= e(t('appointments.weekday.' . $day['weekday'])) ?></span>
                            <span class="muted"><?= e($fmt->date($day['date'])) ?></span>
                        </a>
                    </h2>
                    <?php foreach ($day['items'] as $item): ?>
                        <?php include $theme->templatesPath() . '/appointments/_chip.php'; ?>
                    <?php endforeach; ?>
                    <?php if ($day['items'] === []): ?>
                        <p class="muted text-sm"><?= e(t('appointments.day_empty')) ?></p>
                    <?php endif; ?>
                    <?php if ($canCreate): ?>
                        <a class="appt-slot__add" href="<?= e($bookUrl($resourceId, $day['date'])) ?>"><?= icon('plus', 'icon--sm') ?><?= e(t('appointments.book')) ?></a>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
