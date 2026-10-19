<?php
/**
 * The related-record selector of the task forms: "" = none, "type:id" = a
 * record this user may pick (TaskService::relatedOptions()). On the edit
 * form, a current relation that is not in the list (another type, or a
 * record the user can't see) is kept with the "keep" option — so saving
 * other fields never replaces it, and its name is never shown unless the
 * user may see it.
 *
 * @var string     $selectId
 * @var array      $relatedOptions type => ["type:id" => label]
 * @var string     $selectedRelated
 * @var array|null $keepRelated the current relation's resolve() answer, when it must be kept
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;

$keepRelated ??= null;
?>
<select id="<?= e($selectId) ?>" name="related">
    <?php if ($keepRelated !== null): ?>
        <?php $keepText = $keepRelated['state'] === 'ok' ? $keepRelated['label'] : t($keepRelated['state'] === 'missing' ? 'tasks.related_deleted' : 'tasks.related_restricted'); ?>
        <option value="keep" selected><?= e(t('tasks.related_keep', ['record' => t('tasks.related.' . $keepRelated['type']) . ': ' . $keepText])) ?></option>
    <?php endif; ?>
    <option value=""<?= $keepRelated === null && $selectedRelated === '' ? ' selected' : '' ?>><?= e(t('tasks.related_none')) ?></option>
    <?php foreach ($relatedOptions as $type => $options): ?>
        <optgroup label="<?= e(t('tasks.related.' . $type)) ?>">
            <?php foreach ($options as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $keepRelated === null && $selectedRelated === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </optgroup>
    <?php endforeach; ?>
</select>
