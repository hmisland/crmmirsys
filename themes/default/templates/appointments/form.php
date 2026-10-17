<?php
/**
 * Book / edit an appointment. View-model data from AppointmentController::form().
 * When the only problems left are warnings (outside working hours, staff
 * already booked) errors carry AppointmentService::FORCE_KEY and the form
 * offers "save anyway".
 *
 * @var array|null $appointment
 * @var array $contacts
 * @var array $resources
 * @var array $types
 * @var array<int, string> $users
 * @var bool $canManage
 * @var array $old
 * @var array $errors
 */

use App\Infrastructure\Security\Csrf;
use Modules\Appointments\Services\AppointmentService;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_today;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$isEdit = $appointment !== null;
$val = fn (string $key): string => e((string) ($old[$key] ?? ''));
$err = fn (string $key): string => isset($errors[$key][0]) ? '<div class="field-error">' . e(t($errors[$key][0])) . '</div>' : '';
$selected = fn (string $key, $value): string => (string) ($old[$key] ?? '') === (string) $value ? ' selected' : '';
$canForce = isset($errors[AppointmentService::FORCE_KEY]);
$back = $isEdit ? '/appointments/' . (int) $appointment['id'] : '/appointments?date=' . rawurlencode((string) ($old['date'] ?? local_today()));
$action = $isEdit ? '/appointments/' . (int) $appointment['id'] : '/appointments';
?>
<div class="page-head">
    <div>
        <h1><?= e(t($isEdit ? 'appointments.edit' : 'appointments.new')) ?></h1>
        <p><?= e(t('appointments.form_subtitle')) ?></p>
    </div>
    <div class="quick-actions">
        <a href="<?= e($back) ?>" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('appointments.back')) ?></a>
    </div>
</div>

<?php if ($resources === [] && $types === [] && $canManage): ?>
    <div class="alert alert--info"><div class="alert__body"><?= e(t('appointments.no_settings_hint')) ?> <a href="/appointments/settings"><?= e(t('appointments.settings')) ?></a></div></div>
<?php endif; ?>

<section class="card card--form">
    <form method="post" action="<?= e($action) ?>">
        <?= Csrf::field() ?>

        <?php if ($canForce): ?>
            <div class="alert alert--warning">
                <div class="alert__body">
                    <p><?= e(t('appointments.validation.force_hint')) ?></p>
                    <label class="check"><input type="checkbox" name="force" value="1"> <?= e(t('appointments.save_anyway')) ?></label>
                </div>
            </div>
        <?php endif; ?>

        <div class="form-grid">
            <div>
                <label for="contact_id"><?= e(t('appointments.customer')) ?></label>
                <select id="contact_id" name="contact_id">
                    <option value=""><?= e(t('appointments.no_customer_internal')) ?></option>
                    <?php foreach ($contacts as $c): ?>
                        <option value="<?= e($c['id']) ?>"<?= $selected('contact_id', $c['id']) ?>><?= e(trim($c['first_name'] . ' ' . ($c['last_name'] ?? '')) . (!empty($c['phone']) ? ' — ' . $c['phone'] : '')) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('contact_id') ?>
                <p class="field-hint"><a href="/contacts/create"><?= icon('user-plus', 'icon--sm') ?> <?= e(t('appointments.new_customer')) ?></a></p>
            </div>
            <div>
                <label for="appointment_type_id"><?= e(t('appointments.type')) ?></label>
                <select id="appointment_type_id" name="appointment_type_id">
                    <option value=""><?= e(t('common.none')) ?></option>
                    <?php foreach ($types as $type): ?>
                        <option value="<?= e($type['id']) ?>"<?= $selected('appointment_type_id', $type['id']) ?>><?= e($type['name'] . ' (' . t('appointments.minutes_n', ['n' => $type['duration_minutes']]) . ')') ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('appointment_type_id') ?>
            </div>
            <div>
                <label for="title"><?= e(t('appointments.title_field')) ?></label>
                <input type="text" id="title" name="title" value="<?= $val('title') ?>" maxlength="255" placeholder="<?= e(t('appointments.title_placeholder')) ?>">
                <?= $err('title') ?>
            </div>
            <div>
                <label for="resource_id"><?= e(t('appointments.resource')) ?></label>
                <select id="resource_id" name="resource_id">
                    <option value=""><?= e(t('appointments.no_resource')) ?></option>
                    <?php foreach ($resources as $r): ?>
                        <option value="<?= e($r['id']) ?>"<?= $selected('resource_id', $r['id']) ?>><?= e($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('resource_id') ?>
            </div>
            <div>
                <label for="assigned_user_id"><?= e(t('appointments.assigned_user')) ?></label>
                <select id="assigned_user_id" name="assigned_user_id">
                    <option value=""><?= e(t('common.none')) ?></option>
                    <?php foreach ($users as $uid => $name): ?>
                        <option value="<?= e($uid) ?>"<?= $selected('assigned_user_id', $uid) ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('assigned_user_id') ?>
            </div>
            <div>
                <label for="status"><?= e(t('appointments.status')) ?></label>
                <select id="status" name="status">
                    <?php foreach (AppointmentService::STATUSES as $s): ?>
                        <option value="<?= e($s) ?>"<?= $selected('status', $s) ?>><?= e(t('appointments.status.' . $s)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('status') ?>
            </div>
            <div>
                <label for="date"><?= e(t('appointments.date')) ?></label>
                <input type="text" class="crm-datepicker" id="date" name="date" value="<?= $val('date') ?>" required>
                <?= $err('date') ?>
            </div>
            <div>
                <label for="start_time"><?= e(t('appointments.start_time')) ?></label>
                <input type="time" id="start_time" name="start_time" value="<?= $val('start_time') ?>" class="field-sm" required>
                <?= $err('start_time') ?>
            </div>
            <div>
                <label for="duration_minutes"><?= e(t('appointments.duration')) ?></label>
                <input type="number" id="duration_minutes" name="duration_minutes" min="5" max="1440" step="5" value="<?= $val('duration_minutes') ?>" class="field-sm">
                <?= $err('duration_minutes') ?>
                <p class="field-hint"><?= e(t('appointments.duration_hint')) ?></p>
            </div>
        </div>

        <label for="notes"><?= e(t('appointments.notes')) ?></label>
        <textarea id="notes" name="notes" rows="3"><?= $val('notes') ?></textarea>

        <div class="actions">
            <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button>
            <a href="<?= e($back) ?>" class="btn btn--ghost"><?= icon('x') ?><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</section>
