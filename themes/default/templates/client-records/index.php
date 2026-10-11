<?php
/** @var array $rows */
/** @var array $filters */
/** @var array<int, string> $users */
/** @var array $kpis */
/** @var bool $canCreate */
/** @var bool $canExport */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;

$statuses = ['active', 'inactive', 'archived'];
$tiles = [
    ['label' => t('client_records.kpi_active'), 'value' => $kpis['active'], 'icon' => 'clipboard', 'tone' => 'success'],
    ['label' => t('client_records.kpi_upcoming'), 'value' => $kpis['upcoming'], 'icon' => 'calendar', 'tone' => 'info'],
    ['label' => t('client_records.kpi_mine'), 'value' => $kpis['mine'], 'icon' => 'user', 'tone' => ''],
    ['label' => t('client_records.kpi_total'), 'value' => $kpis['total'], 'icon' => 'users', 'tone' => ''],
];
$exportQuery = $filters['q'] !== '' ? '?q=' . rawurlencode($filters['q']) : '';
?>
<div class="page-head">
    <div>
        <h1><?= e(t('client_records.title')) ?></h1>
        <p><?= e(t('client_records.subtitle')) ?></p>
    </div>
    <div class="quick-actions">
        <?php if ($canCreate): ?>
            <a href="/client-records/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('client_records.new')) ?></a>
        <?php endif; ?>
        <?php if ($canExport): ?>
            <a href="/client-records/export<?= e($exportQuery) ?>" class="btn"><?= icon('download') ?><?= e(t('common.export_csv')) ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="kpi-grid">
    <?php foreach ($tiles as $tile): ?>
        <div class="card kpi">
            <span class="kpi__top">
                <span class="kpi__label"><?= e($tile['label']) ?></span>
                <span class="kpi__icon<?= $tile['tone'] !== '' ? ' kpi__icon--' . e($tile['tone']) : '' ?>"><?= icon($tile['icon']) ?></span>
            </span>
            <span class="kpi__value"><?= e(number_format((int) $tile['value'])) ?></span>
        </div>
    <?php endforeach; ?>
</div>

<section class="card">
    <form method="get" action="/client-records" class="inline-form-row" role="search">
        <input type="text" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('client_records.search_placeholder')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <select name="status" aria-label="<?= e(t('client_records.status')) ?>">
            <option value=""><?= e(t('client_records.all_statuses')) ?></option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e(t('client_records.status.' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="assigned_user_id" aria-label="<?= e(t('client_records.assigned_specialist')) ?>">
            <option value=""><?= e(t('client_records.all_specialists')) ?></option>
            <?php foreach ($users as $uid => $name): ?>
                <option value="<?= e($uid) ?>" <?= (string) $filters['assigned_user_id'] === (string) $uid ? 'selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('client_records.filter')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/client-records" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../partials/pagination.php'; ?>

    <?php $bulkAction = '/client-records/bulk-delete'; include __DIR__ . '/../partials/bulk-bar.php'; ?>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th class="cell-check"><input type="checkbox" data-bulk-all aria-label="<?= e(t('bulk.select_page')) ?>" title="<?= e(t('bulk.select_page')) ?>"></th>
                    <th><?= e(t('client_records.client')) ?></th>
                    <th><?= e(t('common.phone')) ?></th>
                    <th><?= e(t('client_records.status')) ?></th>
                    <th><?= e(t('client_records.assigned_specialist')) ?></th>
                    <th><?= e(t('client_records.last_visit')) ?></th>
                    <th><?= e(t('client_records.next_visit')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $name = trim($row['contact_first_name'] . ' ' . ($row['contact_last_name'] ?? '')); ?>
                <tr>
                    <td class="cell-check"><input type="checkbox" name="ids[]" value="<?= e($row['id']) ?>" form="bulk-form" data-bulk-item aria-label="<?= e(t('bulk.select_row')) ?>" <?= ($canDelete[(int) $row['id']] ?? false) ? '' : 'disabled' ?>></td>
                    <td>
                        <a href="/client-records/<?= e($row['id']) ?>"><?= e($name) ?></a>
                        <div class="muted mono text-sm"><?= e($row['record_number']) ?></div>
                    </td>
                    <td><span class="ltr"><?= e($row['contact_phone'] ?? '—') ?></span></td>
                    <td><?= badge(t('client_records.status.' . $row['status']), $row['status']) ?></td>
                    <td><?= e($users[$row['assigned_user_id']] ?? '—') ?></td>
                    <td class="nowrap"><span class="ltr"><?= e($row['last_visit_at'] !== null ? substr($row['last_visit_at'], 0, 10) : '—') ?></span></td>
                    <td class="nowrap"><span class="ltr"><?= e($row['next_visit_at'] !== null ? substr($row['next_visit_at'], 0, 16) : '—') ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="7" class="muted"><?= e(t('client_records.empty')) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
