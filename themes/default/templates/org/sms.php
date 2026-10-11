<?php
/** @var array{mode: string, provider: string, values: array<string, string>, secret_hints: array<string, string>} $settings */
/** @var \App\Infrastructure\Sms\SmsProviderDefinition[] $providers */
/** @var bool $platformAvailable */
/** @var bool $platformReady */
/** @var bool $billingReady */
/** @var string|null $unitPrice */
/** @var string|null $balance */
/** @var array{credit: ?int, expires_at: ?int, error: ?string}|null $ownAccount */
/** @var array $logs */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;

$isOwn = $settings['mode'] === 'own';
$money = fn (?string $v) => $v === null ? '—' : number_format((float) $v);
$selectedProvider = $settings['provider'] !== '' ? $settings['provider'] : ($providers[0]->key ?? '');
?>
<section class="card">
    <h1><?= e(t('org_sms.title')) ?></h1>

    <?php if ($providers === []): ?>
        <div class="alert alert--info"><?= e(t('org_sms.no_provider_for_region')) ?></div>
    <?php else: ?>
    <p class="muted"><?= e(t('org_sms.subtitle')) ?></p>

    <dl class="fact-row">
        <div class="fact">
            <dt><?= icon('zap') ?><?= e(t('org_sms.current_mode')) ?></dt>
            <dd><?= $isOwn ? badge(t('org_sms.mode_own'), 'active') : badge(t('org_sms.mode_platform'), 'new') ?></dd>
        </div>
        <?php if ($isOwn): ?>
            <div class="fact">
                <dt><?= icon('wallet') ?><?= e(t('org_sms.own_credit')) ?></dt>
                <dd>
                    <?php if ($ownAccount === null || $ownAccount['error'] !== null): ?>
                        <span class="text-danger"><?= e($ownAccount['error'] ?? '—') ?></span>
                    <?php else: ?>
                        <span class="ltr"><?= e(number_format((int) $ownAccount['credit'])) ?></span> <?= e(t('sms.rials')) ?>
                    <?php endif; ?>
                </dd>
            </div>
        <?php elseif ($platformAvailable): ?>
            <div class="fact">
                <dt><?= icon('wallet') ?><?= e(t('org_sms.platform_credit')) ?></dt>
                <dd><?= $billingReady ? '<span class="ltr">' . e($money($balance)) . '</span>' : e(t('org_sms.billing_unavailable')) ?></dd>
            </div>
            <div class="fact">
                <dt><?= icon('coins') ?><?= e(t('org_sms.unit_price')) ?></dt>
                <dd><?= $billingReady ? '<span class="ltr">' . e($money($unitPrice)) . '</span>' : '—' ?></dd>
            </div>
        <?php endif; ?>
    </dl>

    <?php if (!$isOwn && $platformAvailable): ?>
        <p class="actions">
            <a href="/notifications/admin" class="btn"><?= icon('wallet') ?><?= e(t('org_sms.charge_credit')) ?></a>
            <a href="/wallet" class="btn btn--ghost"><?= icon('credit-card') ?><?= e(t('org_sms.topup_wallet')) ?></a>
        </p>
        <?php if (!$platformReady): ?>
            <div class="alert alert--warning"><?= e(t('org_sms.platform_not_ready')) ?></div>
        <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($providers !== []): ?>
<section class="card card--form">
    <h2><?= e(t('org_sms.choose_title')) ?></h2>
    <form method="post" action="/org/sms" data-sms-form>
        <?= Csrf::field() ?>

        <?php if ($platformAvailable): ?>
            <label class="check check--block">
                <input type="radio" name="mode" value="platform" <?= !$isOwn ? 'checked' : '' ?>>
                <span><strong><?= e(t('org_sms.mode_platform')) ?></strong> — <?= e(t('org_sms.mode_platform_hint')) ?></span>
            </label>
        <?php endif; ?>
        <label class="check check--block">
            <input type="radio" name="mode" value="own" <?= $isOwn || !$platformAvailable ? 'checked' : '' ?>>
            <span><strong><?= e(t('org_sms.mode_own')) ?></strong> — <?= e(t('org_sms.mode_own_hint')) ?></span>
        </label>

        <label for="provider"><?= e(t('org_sms.provider')) ?></label>
        <select id="provider" name="provider" data-sms-provider>
            <?php foreach ($providers as $provider): ?>
                <option value="<?= e($provider->key) ?>" <?= $provider->key === $selectedProvider ? 'selected' : '' ?>><?= e($provider->label) ?></option>
            <?php endforeach; ?>
        </select>

        <?php foreach ($providers as $provider): ?>
            <?php $current = $provider->key === $settings['provider']; ?>
            <fieldset class="field-group" data-sms-fields="<?= e($provider->key) ?>" <?= $provider->key === $selectedProvider ? '' : 'hidden' ?>>
                <?php if ($provider->signupUrl !== null): ?>
                    <p class="field-hint"><?= e(t('org_sms.signup_hint')) ?> <a href="<?= e($provider->signupUrl) ?>" target="_blank" rel="noopener"><?= e($provider->signupUrl) ?></a></p>
                <?php endif; ?>
                <?php foreach ($provider->fields as $name => $field): ?>
                    <?php $id = 'f_' . $provider->key . '_' . $name; $secret = !empty($field['secret']); ?>
                    <label for="<?= e($id) ?>"><?= e(t($field['label'])) ?><?= !empty($field['required']) ? ' *' : '' ?></label>
                    <input type="<?= $secret ? 'password' : 'text' ?>" id="<?= e($id) ?>" name="fields[<?= e($provider->key) ?>][<?= e($name) ?>]" dir="ltr" autocomplete="off"
                           value="<?= $secret ? '' : e($current ? ($settings['values'][$name] ?? '') : '') ?>"
                           placeholder="<?= e($secret && $current && isset($settings['secret_hints'][$name]) ? t('org_sms.key_saved', ['hint' => $settings['secret_hints'][$name]]) : '') ?>">
                    <?php if (!empty($field['hint'])): ?><div class="field-hint"><?= e(t($field['hint'])) ?></div><?php endif; ?>
                <?php endforeach; ?>
            </fieldset>
        <?php endforeach; ?>

        <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button>
    </form>
</section>

<section class="card card--form">
    <h2><?= e(t('sms.test_title')) ?></h2>
    <p class="muted"><?= e($isOwn ? t('org_sms.test_hint_own') : t('org_sms.test_hint_platform')) ?></p>
    <form method="post" action="/org/sms/test">
        <?= Csrf::field() ?>
        <label for="receptor"><?= e(t('sms.receptor')) ?></label>
        <input type="tel" id="receptor" name="receptor" required dir="ltr" placeholder="09xxxxxxxxx">
        <button type="submit" class="btn"><?= icon('send') ?><?= e(t('sms.send_test')) ?></button>
    </form>
</section>

<section class="card">
    <h2><?= e(t('sms.log_title')) ?></h2>
    <div class="table-responsive">
    <table class="table table--compact">
        <thead><tr><th><?= e(t('wallet.date')) ?></th><th><?= e(t('sms.receptor')) ?></th><th><?= e(t('sms.context')) ?></th><th><?= e(t('org_sms.account')) ?></th><th><?= e(t('deals.status')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td class="muted"><span class="ltr"><?= e($log['created_at']) ?></span></td>
                <td><span class="ltr"><?= e($log['receptor']) ?></span></td>
                <td class="muted"><?= e($log['context'] ?? '—') ?></td>
                <td><?= e(str_ends_with((string) $log['provider'], '-own') ? t('org_sms.mode_own') : t('org_sms.mode_platform')) ?></td>
                <td>
                    <?= badge(t('sms.log_status.' . $log['status']), $log['status'] === 'sent' ? 'active' : 'cancelled') ?>
                    <?php if (!empty($log['error'])): ?><div class="text-danger"><?= e($log['error']) ?></div><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($logs === []): ?>
            <tr><td colspan="5" class="muted"><?= e(t('sms.log_empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>
