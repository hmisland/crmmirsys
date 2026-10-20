<?php
/** @var array $organization */
/** @var array $modules */
/** @var array<int, array> $pendingRequests module_id => pending activation request */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\local_time;
use App\Infrastructure\Security\Csrf;

$pendingRequests ??= [];
?>
<section class="card">
    <h1><?= e(t('admin.modules_for')) ?> <?= e($organization['name']) ?></h1>
    <p class="muted"><?= e(t('admin.entitlement_hint')) ?></p>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('common.name')) ?></th><th><?= e(t('admin.platform_status')) ?></th><th><?= e(t('admin.entitled')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($modules as $module): ?>
            <tr>
                <td><?= e($module['name']) ?></td>
                <td><?= badge(t('admin.module_status.' . $module['status']), $module['status']) ?></td>
                <td><?= $module['enabled_for_org'] ? e(t('common.yes')) : e(t('common.no')) ?></td>
                <td>
                    <?php if (isset($pendingRequests[(int) $module['id']])): $pending = $pendingRequests[(int) $module['id']]; ?>
                        <p><?= badge(t('catalog.request_status.pending'), 'pending') ?> <span class="muted ltr"><?= e(local_time($pending['created_at'])) ?></span></p>
                        <form method="post" action="/admin/module-requests/<?= e($pending['id']) ?>/approve" class="inline-form">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="back_organization" value="<?= e($organization['id']) ?>">
                            <button type="submit" class="btn btn--sm btn--primary" data-confirm="<?= e(t('catalog.admin.approve_confirm')) ?>"><?= icon('check') ?><?= e(t('catalog.admin.approve')) ?></button>
                        </form>
                        <form method="post" action="/admin/module-requests/<?= e($pending['id']) ?>/reject" class="inline-form">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="back_organization" value="<?= e($organization['id']) ?>">
                            <input type="text" name="reason" maxlength="500" placeholder="<?= e(t('catalog.admin.reason_placeholder')) ?>" aria-label="<?= e(t('catalog.admin.reason')) ?>">
                            <button type="submit" class="btn btn--sm btn--danger" data-confirm="<?= e(t('catalog.admin.reject_confirm')) ?>"><?= icon('x-circle') ?><?= e(t('catalog.admin.reject')) ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if ((bool) $module['is_core']): ?>
                        <span class="muted"><?= e(t('org.always_on_core')) ?></span>
                    <?php elseif ($module['status'] !== 'active'): ?>
                        <span class="muted"><?= e(t('admin.disabled_platform_wide')) ?></span>
                    <?php elseif ($module['enabled_for_org']): ?>
                        <form method="post" action="/admin/organizations/<?= e($organization['id']) ?>/modules/<?= e($module['id']) ?>/disable" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button"><?= icon('ban') ?><?= e(t('admin.revoke')) ?></button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="/admin/organizations/<?= e($organization['id']) ?>/modules/<?= e($module['id']) ?>/enable" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button"><?= icon('check') ?><?= e(t('admin.grant')) ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
