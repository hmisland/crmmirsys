<?php
/**
 * Webhook settings for one integration connection: the URL and secret
 * to enter in the external system (e.g. WooCommerce → Settings →
 * Advanced → Webhooks). Shown only here, to organization.edit users,
 * with no-store caching.
 *
 * @var array{provider: string, url: string, secret: string} $webhook
 * @var int $connectionId
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\help_link;
use function App\Infrastructure\Theme\icon;
?>
<div class="page-head">
    <div>
        <h1><?= e(t('integrations.webhook_title')) ?><?= help_link('woocommerce-connect-store') ?></h1>
        <p><?= e(t('integrations.webhook_subtitle')) ?></p>
    </div>
    <div class="actions">
        <a href="/org/integrations#connection-<?= e($connectionId) ?>" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('integrations.back')) ?></a>
    </div>
</div>

<section class="card card--form">
    <label for="webhook-url"><?= e(t('integrations.webhook_url')) ?></label>
    <input type="text" id="webhook-url" dir="ltr" readonly value="<?= e($webhook['url']) ?>">

    <label for="webhook-secret"><?= e(t('integrations.webhook_secret')) ?></label>
    <input type="text" id="webhook-secret" dir="ltr" readonly autocomplete="off" value="<?= e($webhook['secret']) ?>">
    <div class="field-hint"><?= e(t('integrations.webhook_secret_hint')) ?></div>

    <h3 class="form-section-title"><?= e(t('integrations.webhook_steps_title')) ?></h3>
    <ol>
        <li><?= e(t('integrations.' . $webhook['provider'] . '.webhook_step1')) ?></li>
        <li><?= e(t('integrations.' . $webhook['provider'] . '.webhook_step2')) ?></li>
        <li><?= e(t('integrations.' . $webhook['provider'] . '.webhook_step3')) ?></li>
    </ol>
</section>
