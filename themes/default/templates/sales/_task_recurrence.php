<?php
/**
 * A recurring task's rule in a few words (T7): "Every 2 weeks · until …",
 * plus "Paused" when the series is inactive. Nothing for a one-off task.
 *
 * @var array $task
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 */

use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;

$recurType = $task['recur_type'] ?? null;
if (!in_array($recurType, \Modules\Sales\Services\TaskService::RECURRENCES, true)) {
    return;
}
$recurInterval = max(1, (int) ($task['recur_interval'] ?? 1));
$recurText = $recurInterval === 1
    ? t('tasks.recur.' . $recurType)
    : t('tasks.recur.every_' . $recurType, ['n' => $fmt->digits((string) $recurInterval)]);
?>
<span class="task-recurrence"><?= icon('refresh', 'icon--sm') ?><?= e($recurText) ?><?php if (!empty($task['recur_until'])): ?> <?= e(t('tasks.recur.until')) ?> <span class="ltr"><?= e($fmt->date($task['recur_until'])) ?></span><?php endif; ?><?php if (empty($task['recur_active'])): ?> <?= badge(t('tasks.recur.paused'), 'inactive') ?><?php endif; ?></span>
