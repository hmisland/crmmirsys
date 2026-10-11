<?php
/**
 * Create / edit an appointment type. View-model data from
 * AppointmentSettingsController::typeForm().
 *
 * @var array|null $type
 * @var array $old
 * @var array $errors
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$isEdit = $type !== null;
$val = fn (string $key): string => e((string) ($old[$key] ?? ''));
$err = fn (string $key): string => isset($errors[$key][0]) ? '<div class="field-error">' . e(t($errors[$key][0])) . '</div>' : '';
$isActive = !empty($old['is_active']);
$action = $isEdit ? '/appointments/settings/types/' . (int) $type['id'] : '/appointments/settings/types';
?>
<div class="page-head">
    <div>
        <h1><?= e(t($isEdit ? 'appointments.edit_type' : 'appointments.new_type')) ?></h1>
        <p><?= e(t('appointments.types_hint')) ?></p>
    </div>
    <div class="quick-actions">
        <a href="/appointments/settings" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('appointments.back')) ?></a>
    </div>
</div>

<section class="card card--form">
    <form method="post" action="<?= e($action) ?>">
        <?= Csrf::field() ?>

        <label for="name"><?= e(t('appointments.name')) ?></label>
        <input type="text" id="name" name="name" value="<?= $val('name') ?>" maxlength="150" required>
        <?= $err('name') ?>

        <div class="form-grid">
            <div>
                <label for="duration_minutes"><?= e(t('appointments.duration')) ?></label>
                <input type="number" id="duration_minutes" name="duration_minutes" min="5" max="1440" step="5" value="<?= $val('duration_minutes') ?>" class="field-sm" required>
                <?= $err('duration_minutes') ?>
            </div>
            <div>
                <label for="buffer_minutes"><?= e(t('appointments.buffer')) ?></label>
                <input type="number" id="buffer_minutes" name="buffer_minutes" min="0" max="480" step="5" value="<?= $val('buffer_minutes') ?>" class="field-sm">
                <?= $err('buffer_minutes') ?>
                <p class="field-hint"><?= e(t('appointments.buffer_hint')) ?></p>
            </div>
        </div>

        <label for="description"><?= e(t('appointments.description')) ?></label>
        <textarea id="description" name="description" rows="2"><?= $val('description') ?></textarea>

        <label class="check check--spaced">
            <input type="checkbox" name="is_active" value="1"<?= $isActive ? ' checked' : '' ?>> <?= e(t('appointments.is_active')) ?>
        </label>

        <div class="actions">
            <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button>
            <a href="/appointments/settings" class="btn btn--ghost"><?= icon('x') ?><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</section>
