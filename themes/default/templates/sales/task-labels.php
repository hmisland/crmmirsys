<?php
/**
 * The organization's task labels (T4): create, rename, recolor, delete.
 * Only for users who may manage labels (TaskLabelService::canManage()).
 *
 * @var array    $labels
 * @var string[] $colors the fixed palette keys
 * @var \App\Infrastructure\Theme\ThemeLoader $theme
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$colorSelect = function (string $id, string $selected) use ($colors): string {
    $html = '<select id="' . e($id) . '" name="color" aria-label="' . e(t('tasks.label_color')) . '">';
    foreach ($colors as $color) {
        $html .= '<option value="' . e($color) . '"' . ($color === $selected ? ' selected' : '') . '>' . e(t('tasks.label_colors.' . $color)) . '</option>';
    }

    return $html . '</select>';
};
?>
<section class="card">
    <div class="card__header">
        <div>
            <h1><?= e(t('tasks.labels')) ?></h1>
            <p class="muted"><?= e(t('tasks.labels_hint')) ?></p>
        </div>
        <a class="btn" href="/tasks"><?= icon('arrow-left') ?><?= e(t('nav.tasks')) ?></a>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th><?= e(t('tasks.labels')) ?></th>
                    <th><?= e(t('tasks.label_name')) ?> / <?= e(t('tasks.label_color')) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($labels as $label): ?>
                    <tr>
                        <td><?php $labelRows = [$label]; include $theme->templatesPath() . '/sales/_task_labels.php'; ?></td>
                        <td>
                            <form method="post" action="/tasks/labels/<?= e($label['id']) ?>" class="inline-form-row">
                                <?= Csrf::field() ?>
                                <input type="text" name="name" value="<?= e($label['name']) ?>" required maxlength="50" aria-label="<?= e(t('tasks.label_name')) ?>">
                                <?= $colorSelect('label-color-' . (int) $label['id'], (string) $label['color']) ?>
                                <button type="submit" class="btn btn--sm"><?= icon('save') ?><?= e(t('common.save')) ?></button>
                            </form>
                        </td>
                        <td>
                            <form method="post" action="/tasks/labels/<?= e($label['id']) ?>/delete" class="inline-form">
                                <?= Csrf::field() ?>
                                <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('tasks.label_delete')) ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($labels === []): ?>
                    <tr><td colspan="3" class="muted"><?= e(t('tasks.labels_none_defined')) ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card card--form">
    <h2><?= e(t('tasks.label_new')) ?></h2>
    <form method="post" action="/tasks/labels">
        <?= Csrf::field() ?>
        <label for="label-new-name"><?= e(t('tasks.label_name')) ?></label>
        <input type="text" id="label-new-name" name="name" required maxlength="50">
        <label for="label-new-color"><?= e(t('tasks.label_color')) ?></label>
        <?= $colorSelect('label-new-color', 'blue') ?>
        <button type="submit" class="btn btn--primary"><?= icon('plus') ?><?= e(t('tasks.label_create')) ?></button>
    </form>
</section>
