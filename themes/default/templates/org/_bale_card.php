<?php
/**
 * Bale (Safir) card on Organization settings → Messaging providers.
 * Rendered by MarketingBale's BaleSettingsController::card().
 *
 * @var bool $entitled organization holds marketing + bale_messaging
 * @var array{status: string, enabled: bool, bot_id: ?int, has_key: bool, key_hint: ?string, last_success_at: ?string, last_error: ?string, paused_until: ?string, pause_reason: ?string} $bale
 * @var int $maxText
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\help_link;

$disabled = !$entitled;
$statusTone = match ($bale['status']) {
    'verified' => 'active',
    'configured' => 'pending',
    'rejected' => 'failed',
    default => 'neutral',
};
?>
<section class="card card--form provider-card<?= $disabled ? ' provider-card--unavailable' : '' ?>" id="provider-bale">
    <div class="provider-card__head">
        <h2><span class="kpi__icon"><?= icon('send') ?></span><?= e(t('bale.card_title')) ?><?php if (\App\Infrastructure\Localization\Locale::get()?->locale() === 'fa'): ?><?= help_link('bale-safir-setup') ?><?php endif; ?></h2>
        <?php if ($disabled): ?>
            <?= badge(t('messaging.module_not_purchased'), 'disabled') ?>
        <?php else: ?>
            <?= badge(t('bale.status.' . $bale['status']), $statusTone) ?>
        <?php endif; ?>
    </div>

    <?php if ($disabled): ?>
        <p class="locked"><?= icon('lock') ?><?= e(t('bale.module_hint')) ?></p>
    <?php endif; ?>

    <dl class="fact-row">
        <div class="fact">
            <dt><?= icon('clock') ?><?= e(t('bale.last_accepted')) ?></dt>
            <dd><span class="ltr"><?= e(local_time($bale['last_success_at']) ?? '—') ?></span></dd>
        </div>
        <?php if ($bale['has_key']): ?>
        <div class="fact">
            <dt><?= icon('zap') ?><?= e(t('bale.sending')) ?></dt>
            <dd><?= $bale['enabled'] ? badge(t('bale.sending_on'), 'active') : badge(t('bale.sending_off'), 'disabled') ?></dd>
        </div>
        <?php endif; ?>
    </dl>
    <?php if ($bale['status'] === 'configured'): ?>
        <p class="field-hint"><?= e(t('bale.not_verified_hint')) ?></p>
    <?php endif; ?>
    <?php if ($bale['pause_reason'] !== null): ?>
        <div class="alert alert--warning"><?= e(t('bale.pause.' . $bale['pause_reason'])) ?> <span class="ltr"><?= e(local_time($bale['paused_until']) ?? '') ?></span></div>
    <?php elseif (!empty($bale['last_error'])): ?>
        <div class="alert alert--error"><?= e(t((string) $bale['last_error'])) ?></div>
    <?php endif; ?>

    <form method="post" action="/org/messaging/bale">
        <?= Csrf::field() ?>
        <fieldset class="form-stack fieldset--plain"<?= $disabled ? ' disabled' : '' ?>>
            <label for="bale-api-key"><?= e(t('bale.api_key')) ?></label>
            <input type="password" id="bale-api-key" name="api_key" dir="ltr" autocomplete="off" maxlength="512"
                   placeholder="<?= e($bale['has_key'] ? t('bale.key_saved', ['hint' => (string) $bale['key_hint']]) : '') ?>">
            <div class="field-hint"><?= e(t('bale.api_key_hint')) ?></div>

            <label for="bale-bot-id"><?= e(t('bale.bot_id')) ?></label>
            <input type="text" id="bale-bot-id" name="bot_id" dir="ltr" inputmode="numeric" pattern="[0-9]{1,19}" required value="<?= e($bale['bot_id'] !== null ? (string) $bale['bot_id'] : '') ?>">
            <div class="field-hint"><?= e(t('bale.bot_id_hint')) ?></div>

            <div class="actions">
                <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('bale.save')) ?></button>
            </div>
        </fieldset>
    </form>

    <?php if ($bale['has_key']): ?>
        <div class="actions">
            <form method="post" action="/org/messaging/bale/enabled">
                <?= Csrf::field() ?>
                <input type="hidden" name="enabled" value="<?= $bale['enabled'] ? '0' : '1' ?>">
                <button type="submit" class="btn"<?= $disabled ? ' disabled' : '' ?>><?= icon($bale['enabled'] ? 'ban' : 'check') ?><?= e(t($bale['enabled'] ? 'bale.disable' : 'bale.enable')) ?></button>
            </form>
            <form method="post" action="/org/messaging/bale/disconnect">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--danger" data-confirm="<?= e(t('bale.disconnect_confirm')) ?>"<?= $disabled ? ' disabled' : '' ?>><?= icon('x-circle') ?><?= e(t('bale.disconnect')) ?></button>
            </form>
        </div>
    <?php endif; ?>

    <h3 class="form-section-title"><?= e(t('email_providers.capabilities')) ?></h3>
    <ul class="capability-list">
        <li><?= icon('check') ?><?= e(t('bale.cap.text', ['max' => number_format($maxText)])) ?></li>
        <li><?= icon('ban') ?><?= e(t('bale.cap.no_delivery_reports')) ?></li>
        <li><?= icon('ban') ?><?= e(t('bale.cap.no_connection_test')) ?></li>
        <li><?= icon('info') ?><?= e(t('bale.cap.bale_account_required')) ?></li>
    </ul>
</section>
