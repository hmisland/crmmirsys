<?php
/** @var array $messages */
/** @var array{q: string, status: string, channel: string} $filters */
/** @var \App\Infrastructure\Pagination\Pagination $pagination */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$active = 'history';
$recipients = $messages;
$showCampaign = true;
?>
<div class="page-head">
    <div><h1><?= e(t('marketing.nav.history')) ?></h1></div>
</div>

<?php include __DIR__ . '/_nav.php'; ?>

<section class="card">
    <form method="get" action="/marketing/history" class="inline-form-row filter-bar">
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
            <?php foreach (['pending', 'sent', 'failed', 'skipped', 'cancelled'] as $s): ?>
                <option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e(t('marketing.message_status.' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/marketing/history" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../partials/pagination.php'; ?>

    <?php include __DIR__ . '/_messages_table.php'; ?>
</section>
