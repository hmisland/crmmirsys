<?php
/**
 * A task's labels as colored pills. The color is only ever a palette key
 * (TaskLabelService::COLORS) turned into a CSS class — never raw CSS.
 *
 * @var array $labelRows [{id, name, color}]
 */

use function App\Infrastructure\Theme\e;
use Modules\Sales\Services\TaskLabelService;

if (($labelRows ?? []) === []) {
    return;
}
?>
<span class="task-labels">
    <?php foreach ($labelRows as $label): ?>
        <span class="task-label task-label--<?= e(in_array($label['color'], TaskLabelService::COLORS, true) ? $label['color'] : 'gray') ?>"><?= e($label['name']) ?></span>
    <?php endforeach; ?>
</span>
