<?php
/**
 * One task on the calendar (T9): a compact link to the task page with its
 * time (local wall clock as stored), title, status and labels. The start
 * border takes the status color; closed tasks are muted like in the list.
 *
 * @var array $item TaskCalendar::place() item: task, time, end, continues
 * @var array<int, array> $taskStatuses
 * @var array<int, array> $taskLabels labels by task id
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 * @var \App\Infrastructure\Theme\ThemeLoader $theme
 */

use Modules\Sales\Services\TaskStatusService;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;

$task = $item['task'];
$chipStatus = TaskStatusService::effective($task, $taskStatuses ?? []);
$chipTone = $chipStatus !== null ? (TaskStatusService::TONES[$chipStatus['color']] ?? null) : null;
$chipClosed = in_array($task['status'], ['completed', 'rejected', 'cancelled'], true);
$chipTime = match (true) {
    $item['time'] !== null && $item['end'] !== null => $item['time'] . '–' . $item['end'],
    $item['time'] !== null => $item['time'],
    $item['end'] !== null => '–' . $item['end'],
    default => null,
};
?>
<a class="task-chip<?= $chipTone !== null ? ' task-chip--' . e($chipTone) : '' ?><?= $chipClosed ? ' task-chip--closed' : '' ?>" href="/tasks/<?= e($task['id']) ?>">
    <span class="task-chip__time"><?php if ($chipTime !== null): ?><span class="ltr"><?= e($fmt->digits($chipTime)) ?></span><?php else: ?><?= e(t('tasks.calendar.all_day')) ?><?php endif; ?><?php if ($item['continues']): ?> <?= icon('arrow-right', 'icon--sm') ?><?php endif; ?></span>
    <span class="task-chip__title"><?= e($task['title']) ?></span>
    <span class="task-chip__meta"><?php include $theme->templatesPath() . '/sales/_task_status.php'; ?><?php $labelRows = ($taskLabels ?? [])[(int) $task['id']] ?? []; include $theme->templatesPath() . '/sales/_task_labels.php'; ?></span>
</a>
