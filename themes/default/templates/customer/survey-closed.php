<?php
/**
 * Customer Portal → a survey that can no longer be answered (answered,
 * expired or withdrawn).
 *
 * @var array $invitation
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;
?>
<section class="card">
    <h1><?= e($invitation['survey_name']) ?></h1>
    <p><?= e(t($invitation['status'] === 'responded' ? 'customer_portal.surveys.already_answered' : 'customer_portal.surveys.closed')) ?></p>
    <a href="/customer/surveys" class="btn"><?= icon('arrow-left') ?><?= e(t('customer_portal.surveys.back')) ?></a>
</section>
