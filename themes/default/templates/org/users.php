<?php
/** @var array $users */
/** @var array $roles */
/** @var array $errors */
/** @var array $old */
/** @var array $grants user_id => [permission_key => 'role'|'granted'|'none'] */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
use App\Infrastructure\Security\CurrentUser;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;

// Individually grantable permissions — only shown to admins who can
// manage them ($grants is empty otherwise).
$grants = $grants ?? [];
$grantColumns = $grants === [] ? [] : [
    'contacts.import' => t('org.grant_contacts_import'),
    'contacts.export' => t('org.grant_contacts_export'),
];
?>
<section class="card">
    <h1><?= e(t('org.team_members')) ?></h1>

    <?php if (isset($pagination)): ?>
    <form method="get" action="/org/users" class="inline-form-row filter-bar">
        <input type="text" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('filters.search_user')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
        <select name="role_id" aria-label="<?= e(t('org.role')) ?>">
            <option value=""><?= e(t('filters.all_roles')) ?></option>
            <?php foreach ($roles as $role): ?>
                <option value="<?= e($role['id']) ?>" <?= $filters['role_id'] === (string) $role['id'] ? 'selected' : '' ?>><?= e($role['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" aria-label="<?= e(t('deals.status')) ?>">
            <option value=""><?= e(t('filters.all_statuses')) ?></option>
            <?php foreach (['active', 'inactive'] as $s): ?>
                <option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e(t('org.user_status.' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/org/users" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../partials/pagination.php'; ?>

    <?php endif; ?>
    <div class="table-responsive"><table class="table">
        <thead>
            <tr>
                <th><?= e(t('common.name')) ?></th><th><?= e(t('common.email')) ?></th><th><?= e(t('org.role')) ?></th>
                <?php foreach ($grantColumns as $grantLabel): ?><th><?= e($grantLabel) ?></th><?php endforeach; ?>
                <th><?= e(t('deals.status')) ?></th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <tr>
                <td><?= e(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?></td>
                <td><?= e($user['email']) ?></td>
                <td>
                    <form method="post" action="/org/users/<?= e($user['id']) ?>/role" class="inline-form">
                        <?= Csrf::field() ?>
                        <select aria-label="<?= e(t('org.role')) ?>" name="role_id" onchange="this.form.submit()">
                            <?php foreach ($roles as $role): ?>
                                <option value="<?= e($role['id']) ?>" <?= (int) $user['role_id'] === (int) $role['id'] ? 'selected' : '' ?>>
                                    <?= e($role['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </td>
                <?php foreach ($grantColumns as $permissionKey => $grantLabel): ?>
                    <?php $grantState = $grants[(int) $user['id']][$permissionKey] ?? 'none'; ?>
                    <td class="nowrap">
                        <?php if ($grantState === 'role'): ?>
                            <span class="muted"><?= e(t('org.access_via_role')) ?></span>
                        <?php else: ?>
                            <form method="post" action="/org/users/<?= e($user['id']) ?>/grants" class="inline-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="permission" value="<?= e($permissionKey) ?>">
                                <?php if ($grantState === 'granted'): ?>
                                    <?= badge(t('org.access_granted'), 'active') ?>
                                    <input type="hidden" name="granted" value="0">
                                    <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('x') ?><?= e(t('org.access_revoke')) ?></button>
                                <?php else: ?>
                                    <input type="hidden" name="granted" value="1">
                                    <button type="submit" class="link-button"><?= icon('plus') ?><?= e(t('org.access_grant')) ?></button>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </td>
                <?php endforeach; ?>
                <td><?= badge(t('org.user_status.' . $user['status']), $user['status']) ?></td>
                <td>
                    <?php if ($user['status'] === 'active'): ?>
                        <?php if ((int) $user['id'] !== (int) CurrentUser::id()): ?>
                        <form method="post" action="/org/users/<?= e($user['id']) ?>/deactivate" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button"><?= icon('ban') ?><?= e(t('org.deactivate')) ?></button>
                        </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <form method="post" action="/org/users/<?= e($user['id']) ?>/activate" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button"><?= icon('check-circle') ?><?= e(t('org.activate')) ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>

<section class="card card--form">
    <h2><?= e(t('org.add_teammate')) ?></h2>

    <form method="post" action="/org/users">
        <?= Csrf::field() ?>

        <label for="first_name"><?= e(t('auth.first_name')) ?></label>
        <input type="text" id="first_name" name="first_name" value="<?= e($old['first_name'] ?? '') ?>" required>
        <?php if ($fieldError('first_name')): ?><div class="field-error"><?= e($fieldError('first_name')) ?></div><?php endif; ?>

        <label for="last_name"><?= e(t('auth.last_name')) ?></label>
        <input type="text" id="last_name" name="last_name" value="<?= e($old['last_name'] ?? '') ?>">

        <label for="email"><?= e(t('common.email')) ?></label>
        <input type="email" id="email" name="email" value="<?= e($old['email'] ?? '') ?>" required>
        <?php if ($fieldError('email')): ?><div class="field-error"><?= e($fieldError('email')) ?></div><?php endif; ?>

        <label for="password"><?= e(t('org.temporary_password')) ?></label>
        <input type="password" id="password" name="password" required>
        <?php if ($fieldError('password')): ?><div class="field-error"><?= e($fieldError('password')) ?></div><?php endif; ?>

        <label for="role_id"><?= e(t('org.role')) ?></label>
        <select id="role_id" name="role_id">
            <?php foreach ($roles as $role): ?>
                <option value="<?= e($role['id']) ?>" <?= $role['key'] === 'member' ? 'selected' : '' ?>><?= e($role['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn--primary"><?= icon('user-plus') ?><?= e(t('org.add_teammate')) ?></button>
    </form>
</section>
