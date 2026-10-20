<?php
/**
 * Transactional messages: single messages other modules (e.g. Customer
 * Satisfaction invitations) sent through Marketing's channels. "Accepted"
 * is the provider's acceptance; delivery comes only from provider reports.
 *
 * @var array[] $rows marketing_transactional_messages (masked without contacts.view)
 * @var string[] $purposes
 * @var array{q: string, status: string, channel: string, purpose: string} $filters
 * @var \App\Infrastructure\Pagination\Pagination $pagination
 */

use Modules\Marketing\Channels\ChannelRegistry;

use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;

$active = 'transactional';
$statuses = ['sent', 'retry', 'failed', 'skipped', 'processing'];
$statusTone = ['sent' => 'sent', 'retry' => 'pending', 'processing' => 'pending', 'failed' => 'failed', 'skipped' => null];
$deliveryTone = fn (?string $d): string => match ($d) {
    'delivered' => 'delivered',
    'bounced', 'undelivered', 'failed', 'blocked', 'invalid', 'complaint' => 'failed',
    'soft_bounced', 'deferred', 'queued' => 'pending',
    default => 'neutral',
};
// A purpose has a label when it is a known source (e.g. cx_survey); otherwise it is shown as stored.
$purposeLabel = function (string $purpose): string {
    $key = 'marketing.transactional.purpose.' . $purpose;
    $label = t($key);

    return $label !== $key ? $label : $purpose;
};
?>
<div class="page-head">
    <div>
        <h1><?= e(t('marketing.nav.transactional')) ?></h1>
        <p><?= e(t('marketing.transactional.subtitle')) ?></p>
    </div>
</div>

<?php include __DIR__ . '/_nav.php'; ?>

<section class="card">
    <form method="get" action="/marketing/transactional" class="inline-form-row filter-bar">
        <input type="text" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('common.search')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
        <select name="purpose" aria-label="<?= e(t('marketing.transactional.source')) ?>">
            <option value=""><?= e(t('marketing.transactional.all_sources')) ?></option>
            <?php foreach ($purposes as $purpose): ?>
                <option value="<?= e($purpose) ?>" <?= $filters['purpose'] === $purpose ? 'selected' : '' ?>><?= e($purposeLabel((string) $purpose)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="channel" aria-label="<?= e(t('marketing.channel')) ?>">
            <option value=""><?= e(t('marketing.all_channels')) ?></option>
            <?php foreach (array_merge(['sms', 'email'], ChannelRegistry::extraChannelKeys()) as $ch): ?>
                <option value="<?= e($ch) ?>" <?= $filters['channel'] === $ch ? 'selected' : '' ?>><?= e(t('marketing.channel.' . $ch)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" aria-label="<?= e(t('marketing.status_label')) ?>">
            <option value=""><?= e(t('marketing.all_statuses')) ?></option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e(t('marketing.transactional.status.' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/marketing/transactional" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../partials/pagination.php'; ?>

    <div class="table-responsive">
        <table class="table table--compact">
            <thead><tr>
                <th><?= e(t('marketing.transactional.created')) ?></th>
                <th><?= e(t('marketing.recipient')) ?></th>
                <th><?= e(t('marketing.transactional.source')) ?></th>
                <th><?= e(t('marketing.channel')) ?></th>
                <th><?= e(t('marketing.status_label')) ?></th>
                <th><?= e(t('marketing.delivery_label')) ?></th>
                <th><?= e(t('marketing.sent_at')) ?></th>
            </tr></thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="7" class="muted"><?= e(t('marketing.transactional.none')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $m): ?>
                <?php $status = (string) $m['status']; ?>
                <tr>
                    <td class="muted"><span class="ltr"><?= e(local_time($m['created_at']) ?? '—') ?></span></td>
                    <td>
                        <?php if (!empty($m['contact_id'])): ?><a href="/contacts/<?= e($m['contact_id']) ?>"><?= e($m['recipient_name'] ?: '—') ?></a><?php else: ?><?= e($m['recipient_name'] ?: '—') ?><?php endif; ?>
                        <div class="muted text-sm"><span class="ltr"><?= e($m['address']) ?></span></div>
                    </td>
                    <td><?= e($purposeLabel((string) $m['purpose'])) ?></td>
                    <td><?= e(t('marketing.channel.' . $m['channel'])) ?></td>
                    <td>
                        <?= badge(t('marketing.transactional.status.' . $status), $statusTone[$status] ?? null) ?>
                        <?php if (!empty($m['error_key'])): ?><div class="text-danger text-sm"><?= e(t((string) $m['error_key'])) ?></div><?php endif; ?>
                        <?php if (!empty($m['error'])): ?><div class="muted text-sm"><?= e($m['error']) ?></div><?php endif; ?>
                    </td>
                    <td><?= $m['delivery_status'] !== null ? badge(t('marketing.delivery.' . $m['delivery_status']), $deliveryTone($m['delivery_status'])) : '<span class="muted">' . e(t('marketing.delivery.unknown')) . '</span>' ?></td>
                    <td class="muted"><span class="ltr"><?= e(local_time($m['sent_at']) ?? '—') ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
