<?php
/**
 * Task calendar (T9): month / week / day, server-rendered like the
 * Appointments calendar. Data from TaskController::calendar(); placement by
 * TaskCalendar::place() (local wall clock, never converted). Clicking a task
 * opens its page; "+" opens the existing New task dialog with the date (and
 * hour) filled in.
 *
 * @var string $mode month|week|day
 * @var string $date the focused date (Y-m-d)
 * @var array $period TaskCalendar::period()
 * @var array<string, array{all_day: array, timed: array}> $days
 * @var int[] $weekdays weekday numbers in display order
 * @var bool $truncated
 * @var array $filters the applied filters (view, q, status_id, label_id)
 * @var string $search
 * @var string $scope mine|created|all
 * @var bool $canListAll
 * @var bool $canCreate
 * @var array<int, array> $taskStatuses
 * @var array<int, array> $taskLabels
 * @var array $allLabels
 * @var string $today
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 * @var \App\Infrastructure\Theme\ThemeLoader $theme
 */

use Modules\Sales\Services\TaskCalendar;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;

$calUrl = fn (array $over = []): string => '/tasks/calendar?' . http_build_query(array_merge(['mode' => $mode, 'date' => $date], $filters, $over));
$listUrl = '/tasks' . (($q = array_diff_key($filters, ['label_id' => 1])) !== [] ? '?' . http_build_query($q) : '');
$addUrl = fn (string $day, ?string $time = null): string => '/tasks?' . http_build_query(['new' => '1', 'due_at' => $day] + ($time !== null ? ['due_time' => $time] : []));
$heading = match ($mode) {
    'day' => t('tasks.calendar.weekday.' . date('w', strtotime($date))) . ' ' . $fmt->date($date),
    'week' => $fmt->date($period['from']) . ' – ' . $fmt->date(date('Y-m-d', strtotime($period['to'] . ' -1 day'))),
    default => $fmt->date($period['month_from']) . ' – ' . $fmt->date($period['month_to']),
};
$modes = ['month' => 'calendar', 'week' => 'layers', 'day' => 'clock'];
$scopes = ['mine' => 'tasks.view_active', 'created' => 'tasks.view_created'] + ($canListAll ? ['all' => 'tasks.view_all'] : []);
$moreLimit = 3;
?>
<section class="card">
    <div class="card__header">
        <div>
            <h1><?= e(t('tasks.calendar.title')) ?></h1>
            <p class="muted"><?= e(t('tasks.calendar.hint')) ?></p>
        </div>
        <div class="actions">
            <nav class="segmented" aria-label="<?= e(t('tasks.calendar.layout')) ?>">
                <a class="btn btn--sm" href="<?= e($listUrl) ?>"><?= icon('list') ?><?= e(t('tasks.calendar.list')) ?></a>
                <a class="btn btn--sm" href="<?= e($calUrl()) ?>" aria-current="page"><?= icon('calendar') ?><?= e(t('tasks.calendar.open')) ?></a>
            </nav>
            <?php if ($canCreate): ?>
                <a class="btn btn--primary" href="<?= e($addUrl($date)) ?>"><?= icon('plus') ?><?= e(t('tasks.new_task')) ?></a>
            <?php endif; ?>
        </div>
    </div>

    <div class="appt-toolbar">
        <nav class="segmented" aria-label="<?= e(t('tasks.calendar.views')) ?>">
            <?php foreach ($modes as $key => $modeIcon): ?>
                <a class="btn btn--sm" href="<?= e($calUrl(['mode' => $key])) ?>"<?= $mode === $key ? ' aria-current="page"' : '' ?>><?= icon($modeIcon) ?><?= e(t('tasks.calendar.mode_' . $key)) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="appt-datenav">
            <a class="btn btn--sm" href="<?= e($calUrl(['date' => $period['prev']])) ?>"><?= icon('chevron-left') ?><?= e(t('tasks.calendar.prev_' . $mode)) ?></a>
            <a class="btn btn--sm" href="<?= e($calUrl(['date' => $today])) ?>"><?= icon('calendar-check') ?><?= e(t('tasks.calendar.today')) ?></a>
            <a class="btn btn--sm" href="<?= e($calUrl(['date' => $period['next']])) ?>"><?= e(t('tasks.calendar.next_' . $mode)) ?><?= icon('chevron-right') ?></a>
            <strong class="appt-datenav__label"><span class="ltr"><?= e($heading) ?></span></strong>
        </div>
    </div>

    <form method="get" action="/tasks/calendar" class="inline-form-row filter-bar">
        <input type="hidden" name="mode" value="<?= e($mode) ?>">
        <input type="hidden" name="date" value="<?= e($date) ?>">
        <select name="view" aria-label="<?= e(t('tasks.my_tasks')) ?>">
            <?php foreach ($scopes as $key => $labelKey): ?>
                <option value="<?= e($key) ?>"<?= $scope === $key ? ' selected' : '' ?>><?= e(t($labelKey)) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="q" value="<?= e($search) ?>" maxlength="100" placeholder="<?= e(t('tasks.search_placeholder')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <select name="status_id" aria-label="<?= e(t('tasks.status_label')) ?>">
            <option value=""><?= e(t('tasks.all_statuses')) ?></option>
            <?php foreach ($taskStatuses as $option): ?>
                <option value="<?= e($option['id']) ?>"<?= ($filters['status_id'] ?? '') === (string) $option['id'] ? ' selected' : '' ?>><?= e($option['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="label_id" aria-label="<?= e(t('tasks.labels')) ?>">
            <option value=""><?= e(t('tasks.calendar.all_labels')) ?></option>
            <?php foreach ($allLabels as $label): ?>
                <option value="<?= e($label['id']) ?>"<?= ($filters['label_id'] ?? '') === (string) $label['id'] ? ' selected' : '' ?>><?= e($label['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('tasks.filter')) ?></button>
        <?php if (array_diff_key($filters, ['view' => 1]) !== []): ?>
            <a href="<?= e('/tasks/calendar?' . http_build_query(['mode' => $mode, 'date' => $date])) ?>" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a>
        <?php endif; ?>
    </form>

    <?php if ($truncated): ?>
        <div class="alert alert--info"><div class="alert__body"><?= e(t('tasks.calendar.too_many')) ?></div></div>
    <?php endif; ?>

    <?php if ($mode === 'month'): ?>
        <div class="task-cal-scroll">
            <div class="task-cal-month" role="grid" aria-label="<?= e($heading) ?>">
                <?php foreach ($weekdays as $weekday): ?>
                    <div class="task-cal-month__weekday" role="columnheader"><?= e(t('tasks.calendar.weekday_short.' . $weekday)) ?></div>
                <?php endforeach; ?>
                <?php foreach ($days as $day => $cell): ?>
                    <?php
                    $items = array_merge($cell['all_day'], $cell['timed']);
                    $outside = $day < $period['month_from'] || $day > $period['month_to'];
                    ?>
                    <div class="task-cal-cell<?= $outside ? ' task-cal-cell--outside' : '' ?><?= $day === $today ? ' task-cal-cell--today' : '' ?>" role="gridcell" data-date="<?= e($day) ?>">
                        <div class="task-cal-cell__head">
                            <a href="<?= e($calUrl(['mode' => 'day', 'date' => $day])) ?>" class="task-cal-cell__day"><?= e(TaskCalendar::dayNumber($day, $fmt)) ?></a>
                            <?php if ($canCreate): ?>
                                <a href="<?= e($addUrl($day)) ?>" class="task-cal-add" aria-label="<?= e(t('tasks.calendar.add')) ?>" title="<?= e(t('tasks.calendar.add')) ?>"><?= icon('plus', 'icon--sm') ?></a>
                            <?php endif; ?>
                        </div>
                        <?php foreach (array_slice($items, 0, $moreLimit) as $item): ?>
                            <?php include $theme->templatesPath() . '/sales/_calendar_item.php'; ?>
                        <?php endforeach; ?>
                        <?php if (count($items) > $moreLimit): ?>
                            <a class="task-cal-more" href="<?= e($calUrl(['mode' => 'day', 'date' => $day])) ?>"><?= e($fmt->digits(t('tasks.calendar.more', ['count' => (string) (count($items) - $moreLimit)]))) ?></a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php elseif ($mode === 'week'): ?>
        <div class="appt-week">
            <?php foreach ($days as $day => $cell): ?>
                <section class="appt-day<?= $day === $today ? ' appt-day--today' : '' ?>" data-date="<?= e($day) ?>">
                    <h2 class="appt-day__head">
                        <a href="<?= e($calUrl(['mode' => 'day', 'date' => $day])) ?>">
                            <span><?= e(t('tasks.calendar.weekday.' . date('w', strtotime($day)))) ?></span>
                            <span class="muted"><span class="ltr"><?= e($fmt->date($day)) ?></span></span>
                        </a>
                    </h2>
                    <?php foreach (array_merge($cell['all_day'], $cell['timed']) as $item): ?>
                        <?php include $theme->templatesPath() . '/sales/_calendar_item.php'; ?>
                    <?php endforeach; ?>
                    <?php if ($cell['all_day'] === [] && $cell['timed'] === []): ?>
                        <p class="muted text-sm"><?= e(t('tasks.calendar.empty_day')) ?></p>
                    <?php endif; ?>
                    <?php if ($canCreate): ?>
                        <a class="appt-slot__add" href="<?= e($addUrl($day)) ?>"><?= icon('plus', 'icon--sm') ?><?= e(t('tasks.calendar.add')) ?></a>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <?php
        $cell = $days[$date] ?? ['all_day' => [], 'timed' => []];
        $byHour = [];
        foreach ($cell['timed'] as $item) {
            $byHour[(int) substr($item['time'] ?? $item['end'], 0, 2)][] = $item;
        }
        ?>
        <div class="table-responsive">
            <table class="table appt-grid task-cal-day" data-date="<?= e($date) ?>">
                <tbody>
                    <tr>
                        <th scope="row" class="appt-grid__time"><?= e(t('tasks.calendar.all_day')) ?></th>
                        <td class="appt-slot">
                            <?php foreach ($cell['all_day'] as $item): ?>
                                <?php include $theme->templatesPath() . '/sales/_calendar_item.php'; ?>
                            <?php endforeach; ?>
                            <?php if ($canCreate): ?>
                                <a class="appt-slot__add" href="<?= e($addUrl($date)) ?>"><?= icon('plus', 'icon--sm') ?><?= e(t('tasks.calendar.add')) ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php for ($hour = 0; $hour < 24; $hour++): ?>
                        <?php $slot = sprintf('%02d:00', $hour); ?>
                        <tr>
                            <th scope="row" class="appt-grid__time"><span class="ltr"><?= e($fmt->digits($slot)) ?></span></th>
                            <td class="appt-slot">
                                <?php foreach ($byHour[$hour] ?? [] as $item): ?>
                                    <?php include $theme->templatesPath() . '/sales/_calendar_item.php'; ?>
                                <?php endforeach; ?>
                                <?php if ($canCreate): ?>
                                    <a class="appt-slot__add" href="<?= e($addUrl($date, $slot)) ?>"><?= icon('plus', 'icon--sm') ?><?= e(t('tasks.calendar.add')) ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <p class="muted text-sm"><?= e(t('tasks.calendar.unscheduled_hint')) ?></p>
</section>
