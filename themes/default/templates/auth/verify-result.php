<?php
/** @var string $status success|error */
/** @var string $message */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
?>
<section class="card card--form" style="text-align:center">
    <h1><?= $status === 'success' ? e(t('auth.email_verified')) : e(t('auth.verification_failed')) ?></h1>
    <p class="<?= $status === 'success' ? '' : 'alert alert--error' ?>"><?= e($message) ?></p>
    <p><a href="/login" class="btn btn--primary"><?= e(t('auth.go_to_login')) ?></a></p>
</section>
