<?php
/** @var bool $configured */
/** @var \App\Infrastructure\Sms\SmsProviderDefinition|null $provider platform provider */
/** @var string $sender */
/** @var string $senderInternational */
/** @var array{credit: ?int, expires_at: ?int, error: ?string}|null $account */
/** @var array $logs */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;
?>
<section class="card">
    <h1><?= e(t('sms.title')) ?></h1>
    <p class="muted"><?= e(t('sms.subtitle')) ?></p>

    <dl class="fact-row">
        <div class="fact">
            <dt><?= icon('zap') ?><?= e(t('sms.provider')) ?></dt>
            <dd><?= e($provider?->label ?? '—') ?> <?= $configured ? badge(t('sms.status_connected'), 'active') : badge(t('sms.status_not_configured'), 'cancelled') ?></dd>
        </div>
        <div class="fact">
            <dt><?= icon('phone') ?><?= e(t('sms_field.sender')) ?></dt>
            <dd class="ltr"><?= e($sender !== '' ? $sender : '—') ?></dd>
        </div>
        <div class="fact">
            <dt><?= icon('globe') ?><?= e(t('sms_field.sender_international')) ?></dt>
            <dd class="ltr"><?= e($senderInternational !== '' ? $senderInternational : '—') ?></dd>
        </div>
        <?php if ($account !== null): ?>
            <div class="fact">
                <dt><?= icon('wallet') ?><?= e(t('sms.credit')) ?></dt>
                <dd>
                    <?php if ($account['error'] !== null): ?>
                        <span class="text-danger"><?= e($account['error']) ?></span>
                    <?php else: ?>
                        <span class="ltr"><?= e(number_format((int) $account['credit'])) ?></span> <?= e(t('sms.rials')) ?>
                    <?php endif; ?>
                </dd>
            </div>
            <?php if (!empty($account['expires_at'])): ?>
                <div class="fact">
                    <dt><?= icon('calendar') ?><?= e(t('sms.expires')) ?></dt>
                    <dd class="ltr"><?= e(date('Y-m-d', (int) $account['expires_at'])) ?></dd>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </dl>

    <?php if (!$configured): ?>
        <div class="alert alert--info"><?= e(t('sms.setup_hint')) ?></div>
    <?php endif; ?>
    <?php if ($provider !== null): ?>
        <p class="muted"><?= e(t('sms.provider_scope', ['languages' => implode(', ', $provider->languages), 'countries' => implode(', ', $provider->countries)])) ?></p>
    <?php endif; ?>
</section>

<section class="card card--form">
    <h2><?= e(t('sms.test_title')) ?></h2>
    <form method="post" action="/admin/sms/test">
        <?= Csrf::field() ?>

        <label for="receptor"><?= e(t('sms.receptor')) ?></label>
        <input type="tel" id="receptor" name="receptor" required dir="ltr" placeholder="09xxxxxxxxx">

        <label for="message"><?= e(t('sms.message')) ?></label>
        <textarea id="message" name="message" rows="3"><?= e(t('sms.test_default_text')) ?></textarea>

        <label for="template"><?= e(t('sms.template')) ?></label>
        <input type="text" id="template" name="template" dir="ltr" maxlength="100">
        <div class="field-hint"><?= e(t('sms.template_hint')) ?></div>

        <label for="token"><?= e(t('sms.token')) ?></label>
        <input type="text" id="token" name="token" dir="ltr" maxlength="100" class="field-sm">

        <button type="submit" class="btn btn--primary" <?= $configured ? '' : 'disabled' ?>><?= icon('send') ?><?= e(t('sms.send_test')) ?></button>
    </form>
</section>

<section class="card">
    <h2><?= e(t('sms.log_title')) ?></h2>
    <div class="table-responsive">
    <table class="table table--compact">
        <thead>
            <tr>
                <th><?= e(t('wallet.date')) ?></th>
                <th><?= e(t('sms.receptor')) ?></th>
                <th><?= e(t('sms.context')) ?></th>
                <th><?= e(t('sms.message')) ?></th>
                <th><?= e(t('deals.status')) ?></th>
                <th><?= e(t('sms.cost')) ?></th>
                <th><?= e(t('sms.organization')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td class="muted"><span class="ltr"><?= e($log['created_at']) ?></span></td>
                <td><span class="ltr"><?= e($log['receptor']) ?></span></td>
                <td class="muted"><?= e($log['context'] ?? '—') ?></td>
                <td><span class="cell-clip"><?= e($log['kind'] === 'template' ? t('sms.template') . ': ' . $log['template'] : (string) $log['message']) ?></span></td>
                <td>
                    <?= badge(t('sms.log_status.' . $log['status']), $log['status'] === 'sent' ? 'active' : 'cancelled') ?>
                    <?php if (!empty($log['error'])): ?><div class="text-danger"><?= e($log['error']) ?></div><?php endif; ?>
                </td>
                <td class="num"><?= e(number_format((int) $log['cost'])) ?></td>
                <td class="muted"><?= e($log['organization_name'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($logs === []): ?>
            <tr><td colspan="7" class="muted"><?= e(t('sms.log_empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
