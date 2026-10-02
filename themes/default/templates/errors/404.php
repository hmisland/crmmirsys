<?php
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
?>
<section class="card card--error">
    <h1>404</h1>
    <p><?= e(t('errors.not_found')) ?></p>
</section>
