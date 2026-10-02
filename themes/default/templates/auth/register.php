<?php
/** @var array $errors field => string[] */
/** @var array $old */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
?>
<section class="card card--form">
    <h1><?= e(t('auth.register.title')) ?></h1>

    <form method="post" action="/register">
        <?= Csrf::field() ?>

        <label for="organization_name"><?= e(t('auth.register.organization_name')) ?></label>
        <input type="text" id="organization_name" name="organization_name" value="<?= e($old['organization_name'] ?? '') ?>" required>
        <?php if ($fieldError('organization_name')): ?><div class="field-error"><?= e($fieldError('organization_name')) ?></div><?php endif; ?>

        <label for="first_name"><?= e(t('auth.first_name')) ?></label>
        <input type="text" id="first_name" name="first_name" value="<?= e($old['first_name'] ?? '') ?>" required autofocus>
        <?php if ($fieldError('first_name')): ?><div class="field-error"><?= e($fieldError('first_name')) ?></div><?php endif; ?>

        <label for="last_name"><?= e(t('auth.last_name')) ?></label>
        <input type="text" id="last_name" name="last_name" value="<?= e($old['last_name'] ?? '') ?>">

        <label for="email"><?= e(t('auth.email')) ?></label>
        <input type="email" id="email" name="email" value="<?= e($old['email'] ?? '') ?>" required>
        <?php if ($fieldError('email')): ?><div class="field-error"><?= e($fieldError('email')) ?></div><?php endif; ?>

        <label for="password"><?= e(t('auth.password')) ?></label>
        <input type="password" id="password" name="password" required>
        <?php if ($fieldError('password')): ?><div class="field-error"><?= e($fieldError('password')) ?></div><?php endif; ?>

        <label for="password_confirmation"><?= e(t('auth.register.confirm_password')) ?></label>
        <input type="password" id="password_confirmation" name="password_confirmation" required>

        <button type="submit" class="btn btn--primary"><?= icon('user-plus') ?><?= e(t('auth.register.submit')) ?></button>
    </form>

    <p class="muted">
        <?= e(t('auth.register.have_account')) ?> <a href="/login"><?= e(t('auth.login.title')) ?></a>
    </p>
</section>
