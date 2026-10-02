<?php
/** @var string $token */
/** @var array $errors */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
?>
<section class="card card--form">
    <h1><?= e(t('auth.reset_title')) ?></h1>

    <?php if ($fieldError('token')): ?>
        <div class="alert alert--error"><?= e($fieldError('token')) ?></div>
        <p class="muted"><a href="/password/forgot"><?= e(t('auth.request_new_link')) ?></a></p>
    <?php else: ?>
        <form method="post" action="/password/reset/<?= e($token) ?>">
            <?= Csrf::field() ?>

            <label for="password"><?= e(t('auth.new_password')) ?></label>
            <input type="password" id="password" name="password" required autofocus>
            <?php if ($fieldError('password')): ?><div class="field-error"><?= e($fieldError('password')) ?></div><?php endif; ?>

            <label for="password_confirmation"><?= e(t('auth.register.confirm_password')) ?></label>
            <input type="password" id="password_confirmation" name="password_confirmation" required>

            <button type="submit" class="btn btn--primary"><?= icon('key') ?><?= e(t('auth.reset_submit')) ?></button>
        </form>
    <?php endif; ?>
</section>
