<?php
/**
 * A task's related record as this user may see it
 * (TaskRelatedRecords::resolve()): a link when accessible; otherwise only
 * "restricted" or "deleted" — never the record's name or details.
 *
 * @var array|null $relatedInfo {state, type, id, label, url}
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;

if (($relatedInfo ?? null) === null) {
    return;
}
?>
<span class="task-related"><?= e(t('tasks.related.' . $relatedInfo['type'])) ?>:
<?php if ($relatedInfo['state'] === 'ok'): ?>
    <?php if ($relatedInfo['url'] !== null): ?><a href="<?= e($relatedInfo['url']) ?>"><?= e($relatedInfo['label']) ?></a><?php else: ?><?= e($relatedInfo['label']) ?><?php endif; ?>
<?php else: ?>
    <span class="muted"><?= e(t($relatedInfo['state'] === 'missing' ? 'tasks.related_deleted' : 'tasks.related_restricted')) ?></span>
<?php endif; ?>
</span>
