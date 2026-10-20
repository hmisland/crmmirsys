<?php
/**
 * Customer Portal sign-in, step 2: the one-time code. Says nothing about
 * where (or whether) a code was sent beyond what the customer chose.
 *
 * @var string $slug
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;

$base = '/customer/login/' . rawurlencode($slug);
?>
<section class="card card--form">
    <h1><?= icon('lock') ?><?= e(t('customer_portal.verify.title')) ?></h1>
    <p class="field-hint"><?= e(t('customer_portal.verify.hint')) ?></p>
    <form method="post" action="<?= e($base . '/verify') ?>">
        <?= Csrf::field() ?>
        <label for="code"><?= e(t('customer_portal.verify.code')) ?></label>
        <input type="text" id="code" name="code" class="ltr field-sm" required maxlength="12" inputmode="numeric" autocomplete="one-time-code">
        <div class="actions">
            <button type="submit" class="btn btn--primary"><?= icon('log-in') ?><?= e(t('customer_portal.verify.submit')) ?></button>
        </div>
    </form>
    <form method="post" action="<?= e($base . '/resend') ?>" class="inline-form">
        <?= Csrf::field() ?>
        <button type="submit" class="link-button"><?= icon('refresh') ?><?= e(t('customer_portal.verify.resend')) ?></button>
    </form>
    <p><a href="<?= e($base) ?>"><?= icon('arrow-left') ?><?= e(t('customer_portal.login.start_over')) ?></a></p>
</section>
