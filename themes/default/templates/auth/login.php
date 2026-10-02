<?php
/** @var string|null $error */
/** @var array $old */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<section class="card card--form">
    <h1><?= e(t('auth.login.title')) ?></h1>

    <?php if ($error !== null): ?>
        <div class="alert alert--error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/login">
        <?= Csrf::field() ?>

        <label for="email"><?= e(t('auth.email')) ?></label>
        <input type="email" id="email" name="email" value="<?= e($old['email'] ?? '') ?>" required autofocus>

        <label for="password"><?= e(t('auth.password')) ?></label>
        <input type="password" id="password" name="password" required>

        <button type="submit" class="btn btn--primary"><?= icon('log-in') ?><?= e(t('auth.login.submit')) ?></button>
    </form>

    <p class="muted">
        <a href="/password/forgot"><?= e(t('auth.login.forgot_password')) ?></a>
    </p>
    <p class="muted">
        <?= e(t('auth.login.no_account')) ?> <a href="/register"><?= e(t('auth.register.title')) ?></a>
    </p>
</section>
