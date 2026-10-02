<?php
/** @var bool $sent */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<section class="card card--form">
    <h1><?= e(t('auth.forgot_title')) ?></h1>

    <?php if ($sent): ?>
        <div class="alert alert--success">
            <?= e(t('auth.forgot_sent')) ?>
        </div>
        <p class="muted"><a href="/login"><?= e(t('auth.back_to_login')) ?></a></p>
    <?php else: ?>
        <form method="post" action="/password/forgot">
            <?= Csrf::field() ?>

            <label for="email"><?= e(t('auth.email')) ?></label>
            <input type="email" id="email" name="email" required autofocus>

            <button type="submit" class="btn btn--primary"><?= icon('send') ?><?= e(t('auth.send_reset_link')) ?></button>
        </form>
    <?php endif; ?>
</section>
