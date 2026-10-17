<?php
/** @var array<string, array<string, int>> $stats */
/** @var array $recent */
/** @var bool $sms_ready */
/** @var string[] $email_providers */
/** @var bool $queue_stalled */
/** @var bool $canCreate */
/** @var string[] $channels */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\help_link;

$active = 'dashboard';
$sum = fn (string $key): int => array_sum(array_map(fn ($row) => (int) ($row[$key] ?? 0), $stats));
$kpis = [
    ['label' => t('marketing.kpi.sent'), 'value' => $sum('sent'), 'icon' => 'send', 'tone' => 'info'],
    ['label' => t('marketing.kpi.delivered'), 'value' => $sum('delivered'), 'icon' => 'check-circle', 'tone' => 'success'],
    ['label' => t('marketing.kpi.failed'), 'value' => $sum('failed') + $sum('bounced'), 'icon' => 'x-circle', 'tone' => 'danger'],
    ['label' => t('marketing.kpi.skipped'), 'value' => $sum('skipped'), 'icon' => 'ban', 'tone' => 'warning'],
];
?>
<div class="page-head">
    <div>
        <h1><?= e(t('marketing.title')) ?><?= help_link('marketing-email-basics') ?></h1>
        <p><?= e(t('marketing.subtitle')) ?></p>
    </div>
    <?php if ($canCreate): ?>
    <div class="actions">
        <a href="/marketing/campaigns/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('marketing.new_campaign')) ?></a>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_nav.php'; ?>

<?php if ($queue_stalled): ?>
    <div class="alert alert--warning"><?= e(t('marketing.queue_stalled')) ?></div>
<?php endif; ?>

<section aria-labelledby="marketing-kpis">
    <h2 id="marketing-kpis" class="visually-hidden"><?= e(t('marketing.last_30_days')) ?></h2>
    <div class="kpi-grid">
        <?php foreach ($kpis as $kpi): ?>
            <div class="card kpi">
                <span class="kpi__top">
                    <span class="kpi__label"><?= e($kpi['label']) ?></span>
                    <span class="kpi__icon kpi__icon--<?= e($kpi['tone']) ?>"><?= icon($kpi['icon']) ?></span>
                </span>
                <span class="kpi__value"><?= e(number_format($kpi['value'])) ?></span>
                <span class="muted text-sm"><?= e(t('marketing.last_30_days')) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="card">
    <h2><?= e(t('marketing.channels_title')) ?></h2>
    <div class="table-responsive">
    <table class="table table--compact">
        <thead><tr>
            <th><?= e(t('marketing.channel')) ?></th>
            <th><?= e(t('marketing.status_label')) ?></th>
            <th class="num"><?= e(t('marketing.kpi.sent')) ?></th>
            <th class="num"><?= e(t('marketing.kpi.delivered')) ?></th>
            <th class="num"><?= e(t('marketing.kpi.failed')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach (['sms', 'email'] as $ch): ?>
            <?php
            $entitled = in_array($ch, $channels, true);
            $ready = $ch === 'sms' ? $sms_ready : $email_providers !== [];
            $row = $stats[$ch] ?? [];
            ?>
            <tr>
                <td><?= e(t('marketing.channel.' . $ch)) ?><?php if ($ch === 'email' && $email_providers !== []): ?> <span class="muted text-sm">(<?= e(implode(', ', $email_providers)) ?>)</span><?php endif; ?></td>
                <td>
                    <?php if (!$entitled): ?>
                        <?= badge(t('messaging.module_not_purchased'), 'disabled') ?>
                    <?php elseif ($ready): ?>
                        <?= badge(t('marketing.ready'), 'active') ?>
                    <?php else: ?>
                        <?= badge(t('marketing.not_configured'), 'pending') ?> <a href="/org/messaging"><?= e(t('messaging.title')) ?></a>
                    <?php endif; ?>
                </td>
                <td class="num"><?= e(number_format((int) ($row['sent'] ?? 0))) ?></td>
                <td class="num"><?= e(number_format((int) ($row['delivered'] ?? 0))) ?></td>
                <td class="num"><?= e(number_format((int) ($row['failed'] ?? 0) + (int) ($row['bounced'] ?? 0))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="card">
    <h2><?= e(t('marketing.recent_campaigns')) ?></h2>
    <div class="table-responsive">
    <table class="table">
        <thead><tr>
            <th><?= e(t('common.name')) ?></th>
            <th><?= e(t('marketing.channel')) ?></th>
            <th><?= e(t('marketing.status_label')) ?></th>
            <th class="num"><?= e(t('marketing.recipients')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($recent as $c): ?>
            <tr>
                <td><a href="/marketing/campaigns/<?= e($c['id']) ?>"><?= e($c['name']) ?></a></td>
                <td><?= e(t('marketing.channel.' . $c['channel'])) ?></td>
                <td><?= badge(t('marketing.status.' . $c['status']), $c['status']) ?></td>
                <td class="num"><?= e(number_format((int) $c['total_recipients'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($recent === []): ?>
            <tr><td colspan="4" class="muted"><?= e(t('marketing.no_campaigns')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
