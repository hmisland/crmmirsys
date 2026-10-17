<?php
/**
 * Per-recipient rows (campaign page and message history).
 *
 * @var array $recipients
 * @var bool|null $showCampaign
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;

$showCampaign ??= false;
// Set by the campaign page when the channel allows a confirmed resend of unknown outcomes (and the user may send).
$retryUnknownBase ??= null;
$deliveryTone = fn (?string $d): string => match ($d) {
    'delivered' => 'delivered',
    'bounced', 'undelivered', 'failed', 'blocked', 'invalid', 'complaint' => 'failed',
    'soft_bounced', 'deferred', 'queued' => 'pending',
    default => 'neutral',
};
?>
<div class="table-responsive">
<table class="table table--compact">
    <thead><tr>
        <?php if ($showCampaign): ?><th><?= e(t('marketing.campaign')) ?></th><?php endif; ?>
        <th><?= e(t('marketing.recipient')) ?></th>
        <th><?= e(t('marketing.status_label')) ?></th>
        <th><?= e(t('marketing.delivery_label')) ?></th>
        <th><?= e(t('marketing.provider_reference')) ?></th>
        <th><?= e(t('marketing.sent_at')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($recipients as $m): ?>
        <tr>
            <?php if ($showCampaign): ?><td><a href="/marketing/campaigns/<?= e($m['campaign_id']) ?>"><?= e($m['campaign_name'] ?? '#' . $m['campaign_id']) ?></a></td><?php endif; ?>
            <td>
                <?php if (!empty($m['contact_id'])): ?><a href="/contacts/<?= e($m['contact_id']) ?>"><?= e($m['recipient_name'] ?: '—') ?></a><?php else: ?><?= e($m['recipient_name'] ?: '—') ?><?php endif; ?>
                <div class="muted text-sm"><span class="ltr"><?= e($m['address']) ?></span></div>
            </td>
            <td>
                <?= badge(t('marketing.message_status.' . $m['status']), $m['status']) ?>
                <?php if (!empty($m['skip_reason'])): ?><div class="muted text-sm"><?= e(t('marketing.skip.' . $m['skip_reason'])) ?></div><?php endif; ?>
                <?php if (!empty($m['error'])): ?><div class="text-danger text-sm"><?= e($m['error']) ?></div><?php endif; ?>
                <?php if (!empty($m['ambiguous'])): ?><div class="muted text-sm"><?= e(t('marketing.ambiguous_hint')) ?></div><?php endif; ?>
                <?php if ($retryUnknownBase !== null && !empty($m['ambiguous']) && $m['status'] === 'failed'): ?>
                    <form method="post" action="<?= e($retryUnknownBase . (int) $m['id'] . '/retry-unknown') ?>" class="inline-form-row">
                        <?= \App\Infrastructure\Security\Csrf::field() ?>
                        <label class="check text-sm"><input type="checkbox" name="confirm_duplicate_risk" value="1" required> <?= e(t('marketing.confirm_duplicate_risk')) ?></label>
                        <button type="submit" class="btn btn--sm" data-confirm="<?= e(t('marketing.retry_unknown_confirm')) ?>"><?= icon('refresh') ?><?= e(t('marketing.retry_unknown')) ?></button>
                    </form>
                <?php endif; ?>
            </td>
            <td><?= $m['delivery_status'] !== null ? badge(t('marketing.delivery.' . $m['delivery_status']), $deliveryTone($m['delivery_status'])) : '<span class="muted">' . e(t('marketing.delivery.unknown')) . '</span>' ?></td>
            <td class="muted text-sm"><span class="ltr cell-clip"><?= e($m['provider_message_id'] ?? '—') ?></span></td>
            <td class="muted"><span class="ltr"><?= e(local_time($m['sent_at']) ?? '—') ?></span></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($recipients === []): ?>
        <tr><td colspan="<?= $showCampaign ? 6 : 5 ?>" class="muted"><?= e(t('marketing.no_messages')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
