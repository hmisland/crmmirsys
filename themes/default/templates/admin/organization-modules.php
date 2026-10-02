<?php
/** @var array $organization */
/** @var array $modules */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;
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
                <td><?= e(t('admin.module_status.' . $module['status'])) ?></td>
                <td><?= $module['enabled_for_org'] ? e(t('common.yes')) : e(t('common.no')) ?></td>
                <td>
                    <?php if ((bool) $module['is_core']): ?>
                        <span class="muted"><?= e(t('org.always_on_core')) ?></span>
                    <?php elseif ($module['status'] !== 'active'): ?>
                        <span class="muted"><?= e(t('admin.disabled_platform_wide')) ?></span>
                    <?php elseif ($module['enabled_for_org']): ?>
                        <form method="post" action="/admin/organizations/<?= e($organization['id']) ?>/modules/<?= e($module['id']) ?>/disable" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button"><?= e(t('admin.revoke')) ?></button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="/admin/organizations/<?= e($organization['id']) ?>/modules/<?= e($module['id']) ?>/enable" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button"><?= e(t('admin.grant')) ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
