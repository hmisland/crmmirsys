<?php
/** @var string|null $message */
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
?>
<section class="card card--error">
    <h1><?= e(t('errors.something_went_wrong')) ?></h1>
    <p><?= e($message ?? t('errors.try_again')) ?></p>
</section>
