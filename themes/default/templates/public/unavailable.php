<?php
/**
 * One neutral page for every invalid public link (unknown, expired,
 * revoked, used) — never says which case it is.
 *
 * @var string $reason invalid | rate_limited
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
?>
<section class="card card--form">
    <h1><?= icon('info') ?><?= e(t($reason === 'rate_limited' ? 'public.rate_limited_title' : 'public.unavailable_title')) ?></h1>
    <p class="muted"><?= e(t($reason === 'rate_limited' ? 'public.rate_limited_text' : 'public.unavailable_text')) ?></p>
</section>
