<?php
/**
 * Appointment list with filters. View-model data from AppointmentController::index().
 *
 * @var array $rows
 * @var array $filters
 * @var array $resources
 * @var array<int, string> $users
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 * @var bool $canCreate
 * @var \App\Infrastructure\Theme\ThemeLoader $theme
 */

use Modules\Appointments\Services\AppointmentService;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_today;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;

$current = 'list';
$date = $filters['from'] !== '' ? $filters['from'] : local_today();
?>
<div class="page-head">
    <div>
        <h1><?= e(t('appointments.title')) ?></h1>
        <p><?= e(t('appointments.list_subtitle')) ?></p>
    </div>
    <div class="quick-actions">
        <?php if ($canCreate): ?>
            <a href="/appointments/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('appointments.new')) ?></a>
        <?php endif; ?>
    </div>
</div>

<section class="card">
    <div class="appt-toolbar">
        <?php include $theme->templatesPath() . '/appointments/_views.php'; ?>
    </div>

    <form method="get" action="/appointments/list" class="inline-form-row" role="search">
        <input type="text" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('appointments.search_placeholder')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
        <input type="text" class="crm-datepicker" name="from" value="<?= e($filters['from']) ?>" placeholder="<?= e(t('appointments.from')) ?>" aria-label="<?= e(t('appointments.from')) ?>">
        <input type="text" class="crm-datepicker" name="to" value="<?= e($filters['to']) ?>" placeholder="<?= e(t('appointments.to')) ?>" aria-label="<?= e(t('appointments.to')) ?>">
        <select name="status" aria-label="<?= e(t('appointments.status')) ?>">
            <option value=""><?= e(t('appointments.all_statuses')) ?></option>
            <?php foreach (AppointmentService::STATUSES as $s): ?>
                <option value="<?= e($s) ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= e(t('appointments.status.' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="resource_id" aria-label="<?= e(t('appointments.resource')) ?>">
            <option value=""><?= e(t('appointments.all_resources')) ?></option>
            <?php foreach ($resources as $r): ?>
                <option value="<?= e($r['id']) ?>"<?= (string) $filters['resource_id'] === (string) $r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="assigned_user_id" aria-label="<?= e(t('appointments.assigned_user')) ?>">
            <option value=""><?= e(t('appointments.all_staff')) ?></option>
            <?php foreach ($users as $uid => $name): ?>
                <option value="<?= e($uid) ?>"<?= (string) $filters['assigned_user_id'] === (string) $uid ? ' selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('appointments.filter')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/appointments/list" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../partials/pagination.php'; ?>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th><?= e(t('appointments.when')) ?></th>
                    <th><?= e(t('appointments.customer')) ?></th>
                    <th><?= e(t('appointments.subject')) ?></th>
                    <th><?= e(t('appointments.resource')) ?></th>
                    <th><?= e(t('appointments.assigned_user')) ?></th>
                    <th><?= e(t('appointments.status')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                $who = !empty($row['contact_id']) ? trim($row['contact_first_name'] . ' ' . ($row['contact_last_name'] ?? '')) : t('appointments.internal_meeting');
                $what = (string) ($row['title'] ?? '') !== '' ? (string) $row['title'] : (string) ($row['type_name'] ?? '');
                ?>
                <tr>
                    <td class="nowrap">
                        <a href="/appointments/<?= e($row['id']) ?>"><?= e($fmt->date($row['starts_at'])) ?></a>
                        <div class="muted text-sm"><span class="ltr"><?= e($fmt->digits(substr($row['starts_at'], 11, 5) . '–' . substr($row['ends_at'], 11, 5))) ?></span></div>
                    </td>
                    <td><?= e($who) ?><?php if (!empty($row['contact_phone'])): ?><div class="muted text-sm"><span class="ltr"><?= e($row['contact_phone']) ?></span></div><?php endif; ?></td>
                    <td><?= e($what !== '' ? $what : '—') ?></td>
                    <td><?= e($row['resource_name'] ?? '—') ?></td>
                    <td><?= e($row['assigned_user_id'] !== null ? ($users[$row['assigned_user_id']] ?? '—') : '—') ?></td>
                    <td><?= badge(t('appointments.status.' . $row['status']), $row['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="muted"><?= e(t('appointments.empty')) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
