<?php
/** @var array $organizations */
/** @var array $userCounts */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
?>
<section class="card">
    <h1><?= e(t('admin.all_organizations')) ?></h1>
    <p class="muted"><?= e(t('admin.platform_wide_view')) ?></p>

    <form method="get" action="/admin/organizations" class="inline-form-row filter-bar">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('filters.search_organization')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/admin/organizations" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../partials/pagination.php'; ?>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('common.name')) ?></th><th><?= e(t('admin.slug')) ?></th><th><?= e(t('deals.status')) ?></th><th><?= e(t('admin.users')) ?></th><th><?= e(t('admin.created')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($organizations as $organization): ?>
            <tr>
                <td><?= e($organization['name']) ?></td>
                <td><code><?= e($organization['slug']) ?></code></td>
                <td><?= badge(t('org.user_status.' . $organization['status']), $organization['status']) ?></td>
                <td><?= e($userCounts[$organization['id']] ?? 0) ?></td>
                <td><?= e(local_time($organization['created_at'])) ?></td>
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
