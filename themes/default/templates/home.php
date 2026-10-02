<?php
/** @var array $installedModules */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
?>
<section class="card">
    <h1><?= e(t('home.foundation_running')) ?></h1>
    <p>
        <?= e(t('home.foundation_hint')) ?>
    </p>
</section>

<section class="card">
    <h2><?= e(t('home.discovered_modules')) ?></h2>
    <?php if ($installedModules === []): ?>
        <p class="muted"><?= e(t('home.none_yet')) ?></p>
    <?php else: ?>
        <ul>
            <?php foreach ($installedModules as $module): ?>
                <li>
                    <strong><?= e($module->name) ?></strong>
                    v<?= e($module->version) ?>
                    &mdash; <?= e($module->type) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="card">
    <h2>API</h2>
    <p>
        <code>GET /api/v1/health</code> &middot;
        <code>GET /api/v1/modules</code>
    </p>
</section>
