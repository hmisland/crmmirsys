<?php
/** @var array{available: bool, mode: string, provider: string, can_send: bool} $sms */
/** @var bool $smsMarketingEntitled */
/** @var bool $emailEntitled */
/** @var \App\Infrastructure\Email\EmailProviderDefinition[] $definitions */
/** @var array<string, array<string, mixed>> $emailSettings */
/** @var string[] $extraCards module cards, already rendered and escaped by their own templates */

use App\Infrastructure\Email\EmailProviderInterface;
use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\help_link;

$statusTone = fn (string $status): string => match ($status) {
    'connected' => 'active',
    'error' => 'failed',
    default => 'neutral',
};
?>
<div class="page-head">
    <div>
        <h1><?= e(t('messaging.title')) ?><?= help_link('email-getting-started') ?></h1>
        <p><?= e(t('messaging.subtitle')) ?></p>
    </div>
    <div class="actions">
        <a href="/org/settings" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('messaging.back_to_settings')) ?></a>
    </div>
</div>

<div class="provider-grid">
    <section class="card provider-card" id="provider-kavenegar">
        <div class="provider-card__head">
            <h2><span class="kpi__icon kpi__icon--info"><?= icon('message') ?></span><?= e(t('messaging.sms_kavenegar')) ?><?php if (\App\Infrastructure\Localization\Locale::get()?->locale() === 'fa'): ?><?= help_link('sms-kavenegar-account-modes') ?><?php endif; ?></h2>
            <?= $sms['can_send'] ? badge(t('email_providers.status.connected'), 'active') : badge(t('email_providers.status.disconnected'), 'neutral') ?>
        </div>

        <?php if (!$sms['available']): ?>
            <p class="muted"><?= e(t('org_sms.no_provider_for_region')) ?></p>
        <?php else: ?>
            <dl class="fact-row">
                <div class="fact">
                    <dt><?= icon('zap') ?><?= e(t('org_sms.current_mode')) ?></dt>
                    <dd><?= $sms['mode'] === 'own' ? badge(t('org_sms.mode_own'), 'active') : badge(t('org_sms.mode_platform'), 'new') ?></dd>
                </div>
                <div class="fact">
                    <dt><?= icon('megaphone') ?><?= e(t('messaging.sms_marketing')) ?></dt>
                    <dd><?= $smsMarketingEntitled ? badge(t('messaging.module_available'), 'active') : badge(t('messaging.module_not_purchased'), 'disabled') ?></dd>
                </div>
            </dl>
            <p class="field-hint"><?= e(t('messaging.sms_hint')) ?></p>
            <p class="actions">
                <a href="/org/sms" class="btn"><?= icon('settings') ?><?= e(t('messaging.manage_sms')) ?></a>
            </p>
        <?php endif; ?>
    </section>

    <?php foreach ($definitions as $definition): ?>
        <?php
        $key = $definition->key;
        $s = $emailSettings[$key];
        $caps = $s['capabilities'];
        $disabled = !$emailEntitled;
        ?>
        <section class="card card--form provider-card<?= $disabled ? ' provider-card--unavailable' : '' ?>" id="provider-<?= e($key) ?>">
            <div class="provider-card__head">
                <h2><span class="kpi__icon"><?= icon('mail') ?></span><?= e(t('messaging.email_provider', ['provider' => $definition->label])) ?><?= help_link($key === 'brevo' ? 'email-brevo-connect' : 'email-sender-connect') ?></h2>
                <?php if ($disabled): ?>
                    <?= badge(t('messaging.module_not_purchased'), 'disabled') ?>
                <?php else: ?>
                    <?= badge(t('email_providers.status.' . $s['status']), $statusTone((string) $s['status'])) ?>
                <?php endif; ?>
            </div>

            <?php if ($disabled): ?>
                <p class="locked"><?= icon('lock') ?><?= e(t('messaging.email_module_hint')) ?></p>
            <?php endif; ?>

            <dl class="fact-row">
                <div class="fact">
                    <dt><?= icon('clock') ?><?= e(t('email_providers.last_success')) ?></dt>
                    <dd><span class="ltr"><?= e(local_time($s['last_success_at']) ?? '—') ?></span></dd>
                </div>
                <div class="fact">
                    <dt><?= icon('check-circle') ?><?= e(t('email_providers.sender_verified')) ?></dt>
                    <dd><?= $s['sender_verified'] === null ? e(t('email_providers.unknown')) : ($s['sender_verified'] ? badge(t('common.yes'), 'active') : badge(t('common.no'), 'failed')) ?></dd>
                </div>
            </dl>
            <?php if (!empty($s['last_error'])): ?>
                <div class="alert alert--error"><?= e($s['last_error']) ?></div>
            <?php endif; ?>

            <form method="post" action="/org/messaging/email/<?= e($key) ?>">
                <?= Csrf::field() ?>
                <fieldset class="form-stack fieldset--plain"<?= $disabled ? ' disabled' : '' ?>>
                    <label for="<?= e($key) ?>-secret"><?= e(t($definition->secretLabelKey)) ?></label>
                    <input type="password" id="<?= e($key) ?>-secret" name="api_secret" dir="ltr" autocomplete="off"
                           placeholder="<?= e($s['has_secret'] ? t('email_providers.secret_saved', ['hint' => $s['secret_hint']]) : '') ?>">
                    <div class="field-hint"><?= e(t('email_providers.secret_hint')) ?> <a href="<?= e($definition->docsUrl) ?>" target="_blank" rel="noopener"><?= e($definition->docsUrl) ?></a></div>

                    <label for="<?= e($key) ?>-sender-email"><?= e(t('email_providers.sender_email')) ?></label>
                    <input type="email" id="<?= e($key) ?>-sender-email" name="sender_email" dir="ltr" required value="<?= e($s['sender_email']) ?>">
                    <div class="field-hint"><?= e(t('email_providers.sender_email_hint')) ?></div>

                    <label for="<?= e($key) ?>-sender-name"><?= e(t('email_providers.sender_name')) ?></label>
                    <input type="text" id="<?= e($key) ?>-sender-name" name="sender_name" required maxlength="150" value="<?= e($s['sender_name']) ?>">

                    <label for="<?= e($key) ?>-reply-to"><?= e(t('email_providers.reply_to')) ?></label>
                    <input type="email" id="<?= e($key) ?>-reply-to" name="reply_to" dir="ltr" value="<?= e($s['reply_to']) ?>"<?= empty($caps[EmailProviderInterface::CAP_REPLY_TO]) ? ' disabled' : '' ?>>
                    <?php if (empty($caps[EmailProviderInterface::CAP_REPLY_TO])): ?>
                        <div class="field-hint"><?= e(t('email_providers.reply_to_unsupported')) ?></div>
                    <?php endif; ?>

                    <div class="actions">
                        <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('email_providers.save_and_test')) ?></button>
                    </div>
                </fieldset>
            </form>

            <?php if ($s['has_secret']): ?>
                <div class="actions">
                    <form method="post" action="/org/messaging/email/<?= e($key) ?>/test">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn"<?= $disabled ? ' disabled' : '' ?>><?= icon('refresh') ?><?= e(t('email_providers.test_connection')) ?></button>
                    </form>
                    <form method="post" action="/org/messaging/email/<?= e($key) ?>/disconnect">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn--danger" data-confirm="<?= e(t('email_providers.disconnect_confirm')) ?>"<?= $disabled ? ' disabled' : '' ?>><?= icon('x-circle') ?><?= e(t('email_providers.disconnect')) ?></button>
                    </form>
                </div>

                <form method="post" action="/org/messaging/email/<?= e($key) ?>/test-email" class="inline-form-row">
                    <?= Csrf::field() ?>
                    <label for="<?= e($key) ?>-test-to" class="visually-hidden"><?= e(t('email_providers.test_to')) ?></label>
                    <input type="email" id="<?= e($key) ?>-test-to" name="to" dir="ltr" required placeholder="<?= e(t('email_providers.test_to')) ?>"<?= $disabled ? ' disabled' : '' ?>>
                    <button type="submit" class="btn"<?= $disabled ? ' disabled' : '' ?>><?= icon('send') ?><?= e(t('email_providers.send_test_email')) ?></button>
                </form>
            <?php endif; ?>

            <h3 class="form-section-title"><?= e(t('email_providers.capabilities')) ?></h3>
            <ul class="capability-list">
                <?php foreach ([EmailProviderInterface::CAP_REPLY_TO, EmailProviderInterface::CAP_TEXT_PART, EmailProviderInterface::CAP_DELIVERY_EVENTS, EmailProviderInterface::CAP_SENDER_CHECK] as $cap): ?>
                    <li><?= !empty($caps[$cap]) ? icon('check') : icon('ban') ?><?= e(t('email_providers.cap.' . $cap)) ?></li>
                <?php endforeach; ?>
            </ul>

            <?php if (!empty($caps[EmailProviderInterface::CAP_WEBHOOK_REGISTRATION])): ?>
                <h3 class="form-section-title"><?= e(t('email_providers.webhook')) ?></h3>
                <?php if ($s['webhook_url'] !== null): ?>
                    <p class="field-hint"><?= e(t('email_providers.webhook_active', ['date' => (string) local_time($s['webhook_registered_at'])])) ?></p>
                    <p><code class="ltr"><?= e($s['webhook_url']) ?></code></p>
                <?php else: ?>
                    <p class="field-hint"><?= e(t('email_providers.webhook_hint')) ?></p>
                <?php endif; ?>
                <?php if ($s['has_secret']): ?>
                    <form method="post" action="/org/messaging/email/<?= e($key) ?>/webhook">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn"<?= $disabled ? ' disabled' : '' ?>><?= icon('zap') ?><?= e($s['webhook_url'] !== null ? t('email_providers.webhook_rotate') : t('email_providers.webhook_register')) ?></button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <?php foreach ($extraCards ?? [] as $card): ?>
        <?= $card ?>
    <?php endforeach; ?>
</div>
