<?php
/**
 * Customer Portal home: the sections the organization offers.
 *
 * @var string $name
 * @var array<string, bool> $sections
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;
?>
<div class="page-head">
    <div>
        <h1><?= e($name !== '' ? t('customer_portal.home.welcome', ['name' => $name]) : t('customer_portal.title')) ?></h1>
    </div>
</div>
<div class="customer-sections">
    <section class="card"><a href="/customer/profile"><?= icon('user') ?><?= e(t('customer_portal.nav.profile')) ?></a></section>
    <?php if (!empty($sections['surveys'])): ?><section class="card"><a href="/customer/surveys"><?= icon('smile') ?><?= e(t('customer_portal.nav.surveys')) ?></a></section><?php endif; ?>
    <?php if (!empty($sections['invoices'])): ?><section class="card"><a href="/customer/invoices"><?= icon('receipt') ?><?= e(t('customer_portal.nav.invoices')) ?></a></section><?php endif; ?>
    <?php if (!empty($sections['proformas'])): ?><section class="card"><a href="/customer/proformas"><?= icon('file-text') ?><?= e(t('customer_portal.nav.proformas')) ?></a></section><?php endif; ?>
</div>
