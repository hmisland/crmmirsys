<?php
/** @var array $campaigns */
/** @var array{q: string, status: string, channel: string} $filters */
/** @var \App\Infrastructure\Pagination\Pagination $pagination */
/** @var bool $canCreate */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;

$active = 'campaigns';
?>
<div class="page-head">
    <div><h1><?= e(t('marketing.nav.campaigns')) ?></h1></div>
</div>

<?php include __DIR__ . '/../_nav.php'; ?>

<section class="card">
    <?php if ($canCreate): ?>
    <div class="actions">
        <a href="/marketing/campaigns/create?channel=sms" class="btn btn--primary"><?= icon('plus') ?><?= e(t('marketing.new_campaign')) ?></a>
    </div>
    <?php endif; ?>

    <form method="get" action="/marketing/campaigns" class="inline-form-row filter-bar">
        <input type="text" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('common.search')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
        <select name="channel" aria-label="<?= e(t('marketing.channel')) ?>">
            <option value=""><?= e(t('marketing.all_channels')) ?></option>
            <?php foreach (array_merge(['sms', 'email'], \Modules\Marketing\Channels\ChannelRegistry::extraChannelKeys()) as $ch): ?>
                <option value="<?= e($ch) ?>" <?= $filters['channel'] === $ch ? 'selected' : '' ?>><?= e(t('marketing.channel.' . $ch)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" aria-label="<?= e(t('marketing.status_label')) ?>">
            <option value=""><?= e(t('marketing.all_statuses')) ?></option>
            <?php foreach (['draft', 'queued', 'sending', 'completed', 'cancelled'] as $s): ?>
                <option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e(t('marketing.status.' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/marketing/campaigns" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../../partials/pagination.php'; ?>

    <div class="table-responsive">
    <table class="table">
        <thead><tr>
            <th><?= e(t('common.name')) ?></th>
            <th><?= e(t('marketing.channel')) ?></th>
            <th><?= e(t('marketing.status_label')) ?></th>
            <th class="num"><?= e(t('marketing.recipients')) ?></th>
            <th><?= e(t('marketing.created_at')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($campaigns as $c): ?>
            <tr>
                <td><a href="/marketing/campaigns/<?= e($c['id']) ?>"><?= e($c['name']) ?></a></td>
                <td><?= e(t('marketing.channel.' . $c['channel'])) ?><?= $c['provider'] ? ' <span class="muted text-sm">' . e($c['provider']) . '</span>' : '' ?></td>
                <td><?= \Modules\Marketing\Services\CampaignService::isScheduled($c) ? badge(t('marketing.status.scheduled'), 'scheduled') : badge(t('marketing.status.' . $c['status']), $c['status']) ?></td>
                <td class="num"><?= e(number_format((int) $c['total_recipients'])) ?></td>
                <td class="muted"><span class="ltr"><?= e(local_time($c['created_at'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($campaigns === []): ?>
            <tr><td colspan="5" class="muted"><?= e(t('marketing.no_campaigns')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
