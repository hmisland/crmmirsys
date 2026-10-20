<?php
/**
 * Customer Portal sign-in, step 1: email or mobile (or the national-ID way).
 *
 * @var string $slug
 * @var array<string, string[]> $errors
 * @var string $old
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;

$base = '/customer/login/' . rawurlencode($slug);
?>
<section class="card card--form">
    <h1><?= icon('log-in') ?><?= e(t('customer_portal.login.title')) ?></h1>
    <p class="field-hint"><strong><?= e(t('customer_portal.login.how_title')) ?></strong> <?= e(t('customer_portal.login.how')) ?></p>

    <form method="post" action="<?= e($base) ?>">
        <?= Csrf::field() ?>
        <label for="identifier"><?= e(t('customer_portal.login.identifier')) ?></label>
        <input type="text" id="identifier" name="identifier" class="ltr" required maxlength="190" autocomplete="username" inputmode="email" value="<?= e($old) ?>">
        <?php if (isset($errors['identifier'])): ?><div class="field-error"><?= e(t((string) $errors['identifier'][0])) ?></div><?php endif; ?>
        <div class="actions">
            <button type="submit" class="btn btn--primary"><?= icon('send') ?><?= e(t('customer_portal.login.send_code')) ?></button>
        </div>
    </form>
    <p><a href="<?= e($base . '/national-id') ?>"><?= icon('key') ?><?= e(t('customer_portal.login.use_national_id')) ?></a></p>
</section>
