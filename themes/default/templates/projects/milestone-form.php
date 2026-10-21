<?php
/**
 * Edit one milestone of a project (Modules\Projects\Controllers\ProjectMilestoneController).
 * Adding happens inline on the project page; this form is for changes.
 *
 * @var int $projectId
 * @var array $milestone
 * @var array $errors field => translated messages
 * @var array $old
 * @var string[] $statuses ProjectMilestoneService::STATUSES
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
$value = fn (string $field) => (string) ($old[$field] ?? $milestone[$field] ?? '');
$err = fn (string $field) => $fieldError($field) ? '<div class="field-error">' . e($fieldError($field)) . '</div>' : '';
$back = '/projects/' . $projectId;
?>
<div class="page-head">
    <div>
        <h1><?= e(t('projects.milestones.edit')) ?></h1>
    </div>
    <div class="quick-actions">
        <a href="<?= e($back) ?>" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('projects.back')) ?></a>
    </div>
</div>

<section class="card card--form">
    <form method="post" action="<?= e($back) ?>/milestones/<?= e($milestone['id']) ?>">
        <?= Csrf::field() ?>

        <label for="name"><?= e(t('projects.milestones.name')) ?></label>
        <input type="text" id="name" name="name" value="<?= e($value('name')) ?>" maxlength="255" required autofocus>
        <?= $err('name') ?>

        <div class="form-grid">
            <div>
                <label for="date"><?= e(t('projects.milestones.date')) ?></label>
                <input type="text" class="crm-datepicker" id="date" name="date" value="<?= e($value('date')) ?>" required>
                <?= $err('date') ?>
            </div>
            <div>
                <label for="status"><?= e(t('projects.status')) ?></label>
                <select id="status" name="status">
                    <?php foreach ($statuses as $s): ?>
                        <option value="<?= e($s) ?>"<?= $value('status') === $s ? ' selected' : '' ?>><?= e(t('projects.milestones.status.' . $s)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('status') ?>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button>
            <a href="<?= e($back) ?>" class="btn"><?= icon('x') ?><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</section>
