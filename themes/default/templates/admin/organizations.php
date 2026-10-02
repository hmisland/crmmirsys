<?php
/** @var array $organizations */
/** @var array $userCounts */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
?>
<section class="card">
    <h1><?= e(t('admin.all_organizations')) ?></h1>
    <p class="muted"><?= e(t('admin.platform_wide_view')) ?></p>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('common.name')) ?></th><th><?= e(t('admin.slug')) ?></th><th><?= e(t('deals.status')) ?></th><th><?= e(t('admin.users')) ?></th><th><?= e(t('admin.created')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($organizations as $organization): ?>
            <tr>
                <td><?= e($organization['name']) ?></td>
                <td><code><?= e($organization['slug']) ?></code></td>
                <td><?= e(t('org.user_status.' . $organization['status'])) ?></td>
                <td><?= e($userCounts[$organization['id']] ?? 0) ?></td>
                <td><?= e($organization['created_at']) ?></td>
                <td><a href="/admin/organizations/<?= e($organization['id']) ?>/modules"><?= e(t('admin.manage_modules')) ?></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($organizations === []): ?>
            <tr><td colspan="6" class="muted"><?= e(t('admin.no_organizations_yet')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
