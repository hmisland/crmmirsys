<?php
/** @var array $modules */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(t('admin.modules_platform_title')) ?></h1>
    <p class="muted"><?= e(t('admin.modules_platform_hint')) ?></p>

    <form method="post" action="/admin/modules/sync" class="inline-form">
        <?= Csrf::field() ?>
        <button type="submit" class="btn"><?= e(t('admin.sync_from_disk')) ?></button>
    </form>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('common.name')) ?></th><th><?= e(t('org.key')) ?></th><th><?= e(t('admin.version')) ?></th><th><?= e(t('org.type')) ?></th><th><?= e(t('deals.status')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($modules as $module): ?>
            <tr>
                <td><?= e($module['name']) ?></td>
                <td><code><?= e($module['key']) ?></code></td>
                <td><?= e($module['version']) ?></td>
                <td><?= e(t('admin.module_type.' . $module['type'])) ?><?= $module['is_core'] ? ' (' . e(t('org.always_on_core')) . ')' : '' ?></td>
                <td><?= e(t('admin.module_status.' . $module['status'])) ?></td>
                <td>
                    <?php if ((bool) $module['is_core']): ?>
                        <span class="muted"><?= e(t('admin.always_on')) ?></span>
                    <?php elseif ($module['status'] === 'active'): ?>
                        <form method="post" action="/admin/modules/<?= e($module['id']) ?>/disable" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button"><?= e(t('admin.disable')) ?></button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="/admin/modules/<?= e($module['id']) ?>/enable" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button"><?= e(t('admin.enable')) ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($modules === []): ?>
            <tr><td colspan="6" class="muted"><?= e(t('admin.no_modules_discovered')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
