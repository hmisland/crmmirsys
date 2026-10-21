<?php
/**
 * Super Admin → unverified users: users whose verification email never
 * arrived (or was never opened) can be verified here by hand.
 *
 * @var array $users
 * @var string $q
 * @var \App\Infrastructure\Pagination\Pagination $pagination
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
?>
<section class="card">
    <h1><?= e(t('admin.unverified.title')) ?></h1>
    <p class="muted"><?= e(t('admin.unverified.subtitle')) ?></p>

    <form method="get" action="/admin/unverified-users" class="inline-form-row filter-bar">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('admin.unverified.search')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/admin/unverified-users" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../partials/pagination.php'; ?>

    <div class="table-responsive">
    <table class="table">
        <thead><tr>
            <th><?= e(t('common.name')) ?></th>
            <th><?= e(t('common.email')) ?></th>
            <th><?= e(t('catalog.admin.organization')) ?></th>
            <th><?= e(t('deals.status')) ?></th>
            <th><?= e(t('admin.created')) ?></th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <tr>
                <td><?= e(trim($user['first_name'] . ' ' . ($user['last_name'] ?? ''))) ?></td>
                <td><span class="ltr"><?= e($user['email']) ?></span></td>
                <td><?= e($user['organization_name'] ?? '—') ?></td>
                <td><?= badge(t('org.user_status.' . $user['status']), $user['status']) ?></td>
                <td class="nowrap"><span class="ltr"><?= e(local_time($user['created_at'])) ?></span></td>
                <td>
                    <form method="post" action="/admin/unverified-users/<?= e($user['id']) ?>/verify" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn--sm btn--primary" data-confirm="<?= e(t('admin.unverified.verify_confirm', ['email' => (string) $user['email']])) ?>"><?= icon('check-circle') ?><?= e(t('admin.unverified.verify')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($users === []): ?>
            <tr><td colspan="6" class="muted"><?= e(t('admin.unverified.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
