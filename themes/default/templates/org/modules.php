<?php
/** @var array $modules */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\help_link;
?>
<section class="card">
    <h1><?= e(t('org.modules')) ?></h1>
    <p class="muted"><?= e(t('org.modules_hint')) ?></p>
    <p><a href="/org/modules/catalog" class="btn"><?= icon('grid') ?><?= e(t('catalog.title')) ?></a><?= help_link('modules-activation-request') ?></p>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('common.name')) ?></th><th><?= e(t('deals.status')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($modules as $module): ?>
            <?php if ($module['status'] !== 'active' && !$module['is_core']): continue; endif; ?>
            <tr>
                <td><?= e($module['name']) ?></td>
                <td><?= $module['enabled_for_org'] ? e(t('org.enabled_for_org')) : e(t('org.not_enabled')) ?></td>
                <td>
                    <?php if ((bool) $module['is_core']): ?>
                        <span class="muted"><?= e(t('org.always_on_core')) ?></span>
                    <?php elseif ($module['enabled_for_org']): ?>
                        <a href="/org/modules/<?= e($module['id']) ?>/access"><?= e(t('org.manage_member_access')) ?></a>
                    <?php else: ?>
                        <a href="/org/modules/catalog#module-<?= e($module['key']) ?>"><?= e(t('catalog.request_link')) ?></a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
