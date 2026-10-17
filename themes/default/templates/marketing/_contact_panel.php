<?php
/** @var array $contact */
/** @var array $rows */
/** @var bool $canCreate */
/** @var string[] $channels */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;
?>
<?php if ($canCreate): ?>
    <p class="actions">
        <a href="/marketing/campaigns/create?channel=sms&amp;contact=<?= e($contact['id']) ?>" class="btn btn--sm"><?= icon('message') ?><?= e(t('marketing.send_sms_to_contact')) ?></a>
        <?php if (in_array('bale', $channels ?? [], true)): ?>
            <a href="/marketing/campaigns/create?channel=bale&amp;contact=<?= e($contact['id']) ?>" class="btn btn--sm"><?= icon('send') ?><?= e(t('marketing.send_bale_to_contact')) ?></a>
        <?php endif; ?>
    </p>
<?php endif; ?>
<div class="table-responsive">
<table class="table table--compact">
    <thead><tr>
        <th><?= e(t('marketing.campaign')) ?></th>
        <th><?= e(t('marketing.channel')) ?></th>
        <th><?= e(t('marketing.status_label')) ?></th>
        <th><?= e(t('marketing.sent_at')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $m): ?>
        <tr>
            <td><a href="/marketing/campaigns/<?= e($m['campaign_id']) ?>"><?= e($m['campaign_name']) ?></a></td>
            <td><?= e(t('marketing.channel.' . $m['channel'])) ?></td>
            <td><?= badge(t('marketing.message_status.' . $m['status']), $m['status']) ?><?php if ($m['delivery_status'] !== null): ?> <?= badge(t('marketing.delivery.' . $m['delivery_status']), $m['delivery_status'] === 'delivered' ? 'delivered' : 'neutral') ?><?php endif; ?></td>
            <td class="muted"><span class="ltr"><?= e(local_time($m['sent_at']) ?? '—') ?></span></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?>
        <tr><td colspan="4" class="muted"><?= e(t('marketing.no_messages')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
