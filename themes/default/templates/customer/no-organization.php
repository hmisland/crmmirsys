<?php
/** Customer Portal: /customer/login without an organization address. */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
?>
<section class="card card--form">
    <h1><?= e(t('customer_portal.login.title')) ?></h1>
    <p><?= e(t('customer_portal.login.use_company_link')) ?></p>
</section>
