<?php
/**
 * The organization's task statuses (T8): create, rename, recolor, reorder,
 * activate / deactivate, delete. Only for users with organization.edit
 * (TaskStatusService::canManage()). The lifecycle category is fixed once a
 * status exists; deleting a status still shown on tasks needs another
 * active status of the same category to move them to.
 *
 * @var array    $statuses each with 'label', 'usage', 'replacements'
 * @var string[] $colors the fixed palette keys
 * @var string[] $categories the fixed lifecycle categories
 * @var \App\Infrastructure\Theme\ThemeLoader $theme
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;

$colorSelect = function (string $id, string $selected) use ($colors): string {
    $html = '<select id="' . e($id) . '" name="color" aria-label="' . e(t('tasks.label_color')) . '">';
    foreach ($colors as $color) {
        $html .= '<option value="' . e($color) . '"' . ($color === $selected ? ' selected' : '') . '>' . e(t('tasks.label_colors.' . $color)) . '</option>';
    }

    return $html . '</select>';
};
$last = count($statuses) - 1;
?>
<section class="card">
    <div class="card__header">
        <div>
            <h1><?= e(t('tasks.statuses')) ?></h1>
            <p class="muted"><?= e(t('tasks.statuses_hint')) ?></p>
        </div>
        <a class="btn" href="/tasks"><?= icon('arrow-left') ?><?= e(t('nav.tasks')) ?></a>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th><?= e(t('tasks.status_label')) ?></th>
                    <th><?= e(t('tasks.status_category')) ?></th>
                    <th><?= e(t('tasks.label_name')) ?> / <?= e(t('tasks.label_color')) ?></th>
                    <th><?= e(t('tasks.status_order')) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($statuses as $i => $status): ?>
                    <?php $task = ['status' => (string) ($status['system_key'] ?? 'pending'), 'status_id' => (int) $status['id']]; $taskStatuses = [(int) $status['id'] => $status]; ?>
                    <tr>
                        <td>
                            <?php include $theme->templatesPath() . '/sales/_task_status.php'; ?>
                            <div class="muted text-sm"><?= e(t('tasks.status_usage', ['count' => (string) (int) $status['usage']])) ?></div>
                        </td>
                        <td><?= e(t('tasks.status_categories.' . $status['category'])) ?></td>
                        <td>
                            <form method="post" action="/tasks/statuses/<?= e($status['id']) ?>" class="inline-form-row">
                                <?= Csrf::field() ?>
                                <input type="text" name="name" value="<?= e((string) ($status['name'] ?? '')) ?>" maxlength="50" placeholder="<?= e($status['label']) ?>"<?= $status['system_key'] === null ? ' required' : '' ?> aria-label="<?= e(t('tasks.label_name')) ?>">
                                <?= $colorSelect('status-color-' . (int) $status['id'], (string) $status['color']) ?>
                                <input type="hidden" name="is_active" value="0">
                                <label class="check"><input type="checkbox" name="is_active" value="1"<?= (int) $status['is_active'] === 1 ? ' checked' : '' ?>> <?= e(t('tasks.status_active')) ?></label>
                                <button type="submit" class="btn btn--sm"><?= icon('save') ?><?= e(t('common.save')) ?></button>
                            </form>
                        </td>
                        <td>
                            <div class="inline-form-row">
                                <?php foreach (['up' => ['chevron-up', $i > 0], 'down' => ['chevron-down', $i < $last]] as $direction => [$arrow, $possible]): ?>
                                    <?php if ($possible): ?>
                                        <form method="post" action="/tasks/statuses/<?= e($status['id']) ?>/move" class="inline-form">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="direction" value="<?= e($direction) ?>">
                                            <button type="submit" class="btn btn--ghost btn--sm"><?= icon($arrow) ?><?= e(t('tasks.status_move_' . $direction)) ?></button>
                                        </form>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td>
                            <form method="post" action="/tasks/statuses/<?= e($status['id']) ?>/delete" class="inline-form-row">
                                <?= Csrf::field() ?>
                                <?php if ((int) $status['usage'] > 0): ?>
                                    <?php if ($status['replacements'] === []): ?>
                                        <span class="muted text-sm"><?= e(t('tasks.status_delete_blocked')) ?></span>
                                    <?php else: ?>
                                        <select name="replacement_id" aria-label="<?= e(t('tasks.status_move_tasks_to')) ?>">
                                            <?php foreach ($status['replacements'] as $replacement): ?>
                                                <option value="<?= e($replacement['id']) ?>"><?= e(t('tasks.status_move_tasks_to')) ?> <?= e($replacement['label']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ((int) $status['usage'] === 0 || $status['replacements'] !== []): ?>
                                    <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('tasks.status_delete')) ?></button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($statuses === []): ?>
                    <tr><td colspan="5" class="muted"><?= e(t('tasks.statuses_none')) ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card card--form">
    <h2><?= e(t('tasks.status_new')) ?></h2>
    <form method="post" action="/tasks/statuses">
        <?= Csrf::field() ?>
        <label for="status-new-name"><?= e(t('tasks.label_name')) ?></label>
        <input type="text" id="status-new-name" name="name" required maxlength="50">
        <label for="status-new-color"><?= e(t('tasks.label_color')) ?></label>
        <?= $colorSelect('status-new-color', 'blue') ?>
        <label for="status-new-category"><?= e(t('tasks.status_category')) ?></label>
        <select id="status-new-category" name="category">
            <?php foreach ($categories as $category): ?>
                <option value="<?= e($category) ?>"><?= e(t('tasks.status_categories.' . $category)) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="muted text-sm"><?= e(t('tasks.status_category_hint')) ?></p>
        <button type="submit" class="btn btn--primary"><?= icon('plus') ?><?= e(t('tasks.status_create')) ?></button>
    </form>
</section>
