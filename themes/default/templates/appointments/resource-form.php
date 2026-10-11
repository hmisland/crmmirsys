<?php
/**
 * Create / edit a bookable resource and its weekly working hours.
 * View-model data from AppointmentSettingsController::resourceForm().
 *
 * @var array|null $resource
 * @var array<int, string> $users
 * @var array<int, list<array{start: string, end: string}>> $hours weekday => ranges
 * @var int[] $weekOrder
 * @var array $old
 * @var array $errors
 */

use App\Infrastructure\Security\Csrf;
use Modules\Appointments\Services\AppointmentSettingsService;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$isEdit = $resource !== null;
$val = fn (string $key): string => e((string) ($old[$key] ?? ''));
$err = fn (string $key): string => isset($errors[$key][0]) ? '<div class="field-error">' . e(t($errors[$key][0])) . '</div>' : '';
$selected = fn (string $key, $value): string => (string) ($old[$key] ?? '') === (string) $value ? ' selected' : '';
$isActive = !empty($old['is_active']);
$action = $isEdit ? '/appointments/settings/resources/' . (int) $resource['id'] : '/appointments/settings/resources';
?>
<div class="page-head">
    <div>
        <h1><?= e(t($isEdit ? 'appointments.edit_resource' : 'appointments.new_resource')) ?></h1>
        <p><?= e(t('appointments.resources_hint')) ?></p>
    </div>
    <div class="quick-actions">
        <a href="/appointments/settings" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('appointments.back')) ?></a>
    </div>
</div>

<section class="card card--form">
    <form method="post" action="<?= e($action) ?>">
        <?= Csrf::field() ?>

        <div class="form-grid">
            <div>
                <label for="name"><?= e(t('appointments.name')) ?></label>
                <input type="text" id="name" name="name" value="<?= $val('name') ?>" maxlength="150" required>
                <?= $err('name') ?>
            </div>
            <div>
                <label for="kind"><?= e(t('appointments.kind')) ?></label>
                <select id="kind" name="kind">
                    <?php foreach (AppointmentSettingsService::RESOURCE_KINDS as $kind): ?>
                        <option value="<?= e($kind) ?>"<?= $selected('kind', $kind) ?>><?= e(t('appointments.kind.' . $kind)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('kind') ?>
            </div>
            <div>
                <label for="user_id"><?= e(t('appointments.linked_user')) ?></label>
                <select id="user_id" name="user_id">
                    <option value=""><?= e(t('common.none')) ?></option>
                    <?php foreach ($users as $uid => $name): ?>
                        <option value="<?= e($uid) ?>"<?= $selected('user_id', $uid) ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('user_id') ?>
                <p class="field-hint"><?= e(t('appointments.linked_user_hint')) ?></p>
            </div>
            <div>
                <label for="capacity"><?= e(t('appointments.capacity')) ?></label>
                <input type="number" id="capacity" name="capacity" min="1" max="500" value="<?= $val('capacity') ?>" class="field-sm" required>
                <?= $err('capacity') ?>
                <p class="field-hint"><?= e(t('appointments.capacity_hint')) ?></p>
            </div>
            <div>
                <label for="sort_order"><?= e(t('appointments.sort_order')) ?></label>
                <input type="number" id="sort_order" name="sort_order" value="<?= $val('sort_order') ?>" class="field-sm">
                <?= $err('sort_order') ?>
            </div>
        </div>

        <label for="description"><?= e(t('appointments.description')) ?></label>
        <textarea id="description" name="description" rows="2"><?= $val('description') ?></textarea>

        <label class="check check--spaced">
            <input type="checkbox" name="is_active" value="1"<?= $isActive ? ' checked' : '' ?>> <?= e(t('appointments.is_active')) ?>
        </label>

        <h2 class="form-section-title"><?= e(t('appointments.working_hours')) ?></h2>
        <p class="field-hint"><?= e(t('appointments.working_hours_hint')) ?></p>
        <div class="table-responsive">
            <table class="table hours-table">
                <thead>
                    <tr>
                        <th><?= e(t('appointments.day')) ?></th>
                        <?php for ($i = 1; $i <= AppointmentSettingsService::RANGES_PER_DAY; $i++): ?>
                            <th><?= e(t('appointments.shift_n', ['n' => $i])) ?></th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($weekOrder as $day): ?>
                    <?php $dayName = t('appointments.weekday.' . $day); ?>
                    <tr>
                        <th scope="row">
                            <?= e($dayName) ?>
                            <?= $err('hours.' . $day) ?>
                        </th>
                        <?php foreach ($hours[$day] as $i => $range): ?>
                            <td>
                                <span class="hours-range">
                                    <input type="time" name="hours[<?= e($day) ?>][<?= e($i) ?>][start]" value="<?= e($range['start']) ?>" aria-label="<?= e($dayName . ' — ' . t('appointments.shift_n', ['n' => $i + 1]) . ' — ' . t('appointments.from')) ?>">
                                    <span aria-hidden="true">–</span>
                                    <input type="time" name="hours[<?= e($day) ?>][<?= e($i) ?>][end]" value="<?= e($range['end']) ?>" aria-label="<?= e($dayName . ' — ' . t('appointments.shift_n', ['n' => $i + 1]) . ' — ' . t('appointments.to')) ?>">
                                </span>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="actions">
            <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button>
            <a href="/appointments/settings" class="btn btn--ghost"><?= icon('x') ?><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</section>
