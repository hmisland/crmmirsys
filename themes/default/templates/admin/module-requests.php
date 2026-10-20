<?php
/**
 * Super Admin → Module requests: pending first (oldest first), then the
 * reviewed history. Approve = the existing per-organization entitlement;
 * reject keeps the request with an optional reason.
 *
 * @var array $rows
 * @var array{status: string} $filters
 * @var \App\Infrastructure\Pagination\Pagination $pagination
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\help_link;

$moduleName = function (array $row): string {
    $translated = t('catalog.module.' . $row['module_key'] . '.name');

    return $translated !== 'catalog.module.' . $row['module_key'] . '.name' ? $translated : (string) $row['module_name'];
};
?>
<section class="card">
    <h1><?= e(t('catalog.admin.title')) ?><?= help_link('modules-activation-request') ?></h1>
    <p class="muted"><?= e(t('catalog.admin.subtitle')) ?></p>

    <form method="get" action="/admin/module-requests" class="inline-form-row filter-bar">
        <select name="status" aria-label="<?= e(t('catalog.admin.status')) ?>">
            <option value=""><?= e(t('catalog.admin.all_statuses')) ?></option>
            <?php foreach (['pending', 'approved', 'rejected'] as $status): ?>
                <option value="<?= e($status) ?>"<?= $filters['status'] === $status ? ' selected' : '' ?>><?= e(t('catalog.request_status.' . $status)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($filters['status'] !== ''): ?><a href="/admin/module-requests" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../partials/pagination.php'; ?>

    <div class="table-responsive">
    <table class="table">
        <thead><tr>
            <th><?= e(t('catalog.admin.organization')) ?></th>
            <th><?= e(t('catalog.admin.module')) ?></th>
            <th><?= e(t('catalog.admin.requester')) ?></th>
            <th><?= e(t('catalog.admin.requested_at')) ?></th>
            <th><?= e(t('catalog.admin.status')) ?></th>
            <th><?= e(t('catalog.admin.review')) ?></th>
        </tr></thead>
        <tbody>
        <?php if ($rows === []): ?>
            <tr><td colspan="6" class="muted"><?= e(t('catalog.admin.empty')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><a href="/admin/organizations/<?= e($row['organization_id']) ?>/modules"><?= e($row['organization_name']) ?></a></td>
                <td><?= e($moduleName($row)) ?></td>
                <td><?= e($row['requester_name'] !== '' ? $row['requester_name'] : ($row['requester_email'] ?? '—')) ?></td>
                <td class="nowrap ltr"><?= e(local_time($row['created_at'])) ?></td>
                <td><?= badge(t('catalog.request_status.' . $row['status']), $row['status']) ?></td>
                <td>
                    <?php if ($row['status'] === 'pending'): ?>
                        <form method="post" action="/admin/module-requests/<?= e($row['id']) ?>/approve" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--sm btn--primary" data-confirm="<?= e(t('catalog.admin.approve_confirm')) ?>"><?= icon('check') ?><?= e(t('catalog.admin.approve')) ?></button>
                        </form>
                        <form method="post" action="/admin/module-requests/<?= e($row['id']) ?>/reject" class="inline-form">
                            <?= Csrf::field() ?>
                            <input type="text" name="reason" maxlength="500" placeholder="<?= e(t('catalog.admin.reason_placeholder')) ?>" aria-label="<?= e(t('catalog.admin.reason')) ?>">
                            <button type="submit" class="btn btn--sm btn--danger" data-confirm="<?= e(t('catalog.admin.reject_confirm')) ?>"><?= icon('x-circle') ?><?= e(t('catalog.admin.reject')) ?></button>
                        </form>
                    <?php else: ?>
                        <span class="muted"><?= e($row['reviewer_name'] ?? '') ?> <span class="ltr"><?= e(local_time($row['reviewed_at']) ?? '') ?></span></span>
                        <?php if (!empty($row['rejection_reason'])): ?>
                            <p class="field-hint"><?= e(t('catalog.notice.reason', ['reason' => (string) $row['rejection_reason']])) ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
