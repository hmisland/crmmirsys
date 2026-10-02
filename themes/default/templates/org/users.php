<?php
/** @var array $users */
/** @var array $roles */
/** @var array $errors */
/** @var array $old */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;
use App\Infrastructure\Security\CurrentUser;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
?>
<section class="card">
    <h1><?= e(t('org.team_members')) ?></h1>

    <div class="table-responsive"><table class="table">
        <thead>
            <tr><th><?= e(t('common.name')) ?></th><th><?= e(t('common.email')) ?></th><th><?= e(t('org.role')) ?></th><th><?= e(t('deals.status')) ?></th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <tr>
                <td><?= e(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?></td>
                <td><?= e($user['email']) ?></td>
                <td>
                    <form method="post" action="/org/users/<?= e($user['id']) ?>/role" class="inline-form">
                        <?= Csrf::field() ?>
                        <select name="role_id" onchange="this.form.submit()">
                            <?php foreach ($roles as $role): ?>
                                <option value="<?= e($role['id']) ?>" <?= (int) $user['role_id'] === (int) $role['id'] ? 'selected' : '' ?>>
                                    <?= e($role['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </td>
                <td><?= e(t('org.user_status.' . $user['status'])) ?></td>
                <td>
                    <?php if ($user['status'] === 'active'): ?>
                        <?php if ((int) $user['id'] !== (int) CurrentUser::id()): ?>
                        <form method="post" action="/org/users/<?= e($user['id']) ?>/deactivate" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button"><?= e(t('org.deactivate')) ?></button>
                        </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <form method="post" action="/org/users/<?= e($user['id']) ?>/activate" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button"><?= e(t('org.activate')) ?></button>
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

        <button type="submit" class="btn btn--primary"><?= e(t('org.add_teammate')) ?></button>
    </form>
</section>
