<?php
/**
 * The Progress cell of a Projects-list row (Modules\Sales\ProjectTaskPanels):
 * the same percent as the project's Tasks panel (TaskService::progressPercent()).
 *
 * @var int $percent
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
?>
<span class="nowrap"><?= e($fmt->digits($percent . '%')) ?></span>
<progress class="progress<?= $percent === 100 ? ' progress--done' : '' ?>" max="100" value="<?= (int) $percent ?>" aria-label="<?= e(t('tasks.project_list.progress')) ?>"></progress>
