<?php
/**
 * Public unsubscribe page (reached from a signed email link, no login).
 *
 * @var string $token
 * @var bool $done
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
?>
<section class="card card--form">
    <h1><?= e(t('marketing.unsubscribe_title')) ?></h1>
    <?php if ($done): ?>
        <div class="alert alert--success"><?= e(t('marketing.unsubscribe_done')) ?></div>
    <?php else: ?>
        <p><?= e(t('marketing.unsubscribe_text')) ?></p>
        <form method="post" action="/m/unsubscribe/<?= e($token) ?>">
            <button type="submit" class="btn btn--primary"><?= icon('ban') ?><?= e(t('marketing.unsubscribe_button')) ?></button>
        </form>
    <?php endif; ?>
</section>
