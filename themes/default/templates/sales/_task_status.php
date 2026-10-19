<?php
/**
 * A task's status badge (T8): its custom status — name, and its palette
 * color as the CRM's badge tone — or, when the organization no longer has
 * a status for it, the translated legacy status as before. An inactive
 * status still shows on the tasks that have it, marked as such.
 *
 * @var array $task
 * @var array<int, array> $taskStatuses TaskService::statusMap()
 */

use Modules\Sales\Services\TaskStatusService;

use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;

$shownStatus = TaskStatusService::effective($task, $taskStatuses ?? []);
if ($shownStatus === null) {
    echo badge(t('tasks.status.' . $task['status']), $task['status']);

    return;
}
$statusTone = TaskStatusService::TONES[$shownStatus['color']] ?? null;
?>
<span class="badge task-status<?= $statusTone !== null ? ' badge--' . e($statusTone) : '' ?>"><?= e($shownStatus['label']) ?></span><?php if ((int) $shownStatus['is_active'] !== 1): ?> <span class="muted text-sm">(<?= e(t('tasks.status_inactive')) ?>)</span><?php endif; ?>
