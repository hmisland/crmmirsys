<?php
/** @var array $rows */
/** @var array{q: string, channel: string} $filters */
/** @var \App\Infrastructure\Pagination\Pagination $pagination */
/** @var bool $canManage */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\help_link;

$active = 'suppressions';
?>
<div class="page-head">
    <div>
        <h1><?= e(t('marketing.nav.suppressions')) ?><?= help_link('marketing-unsubscribe-optout') ?></h1>
        <p><?= e(t('marketing.suppressions_hint')) ?></p>
    </div>
</div>

<?php include __DIR__ . '/_nav.php'; ?>

<?php if ($canManage): ?>
<section class="card card--form">
    <h2><?= e(t('marketing.add_suppression')) ?></h2>
    <form method="post" action="/marketing/suppressions" class="inline-form-row">
        <?= Csrf::field() ?>
        <label for="sup-channel" class="visually-hidden"><?= e(t('marketing.channel')) ?></label>
        <select id="sup-channel" name="channel">
            <option value="sms"><?= e(t('marketing.channel.sms')) ?></option>
            <option value="email"><?= e(t('marketing.channel.email')) ?></option>
            <?php foreach (\Modules\Marketing\Channels\ChannelRegistry::extraChannelKeys() as $ch): ?>
                <option value="<?= e($ch) ?>"><?= e(t('marketing.channel.' . $ch)) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="sup-address" class="visually-hidden"><?= e(t('marketing.address')) ?></label>
        <input type="text" id="sup-address" name="address" dir="ltr" required placeholder="<?= e(t('marketing.address')) ?>">
        <button type="submit" class="btn"><?= icon('ban') ?><?= e(t('marketing.add_suppression')) ?></button>
    </form>
</section>
<?php endif; ?>

<section class="card">
    <form method="get" action="/marketing/suppressions" class="inline-form-row filter-bar">
        <input type="text" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('common.search')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
        <select name="channel" aria-label="<?= e(t('marketing.channel')) ?>">
            <option value=""><?= e(t('marketing.all_channels')) ?></option>
            <option value="sms" <?= $filters['channel'] === 'sms' ? 'selected' : '' ?>><?= e(t('marketing.channel.sms')) ?></option>
            <option value="email" <?= $filters['channel'] === 'email' ? 'selected' : '' ?>><?= e(t('marketing.channel.email')) ?></option>
            <?php foreach (\Modules\Marketing\Channels\ChannelRegistry::extraChannelKeys() as $ch): ?>
                <option value="<?= e($ch) ?>" <?= $filters['channel'] === $ch ? 'selected' : '' ?>><?= e(t('marketing.channel.' . $ch)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/marketing/suppressions" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../partials/pagination.php'; ?>

    <div class="table-responsive">
    <table class="table">
        <thead><tr>
            <th><?= e(t('marketing.address')) ?></th>
            <th><?= e(t('marketing.channel')) ?></th>
            <th><?= e(t('marketing.reason')) ?></th>
            <th><?= e(t('marketing.created_at')) ?></th>
            <th><span class="visually-hidden"><?= e(t('contacts.row_actions')) ?></span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><span class="ltr"><?= e($row['address']) ?></span></td>
                <td><?= e(t('marketing.channel.' . $row['channel'])) ?></td>
                <td><?= badge(t('marketing.reason.' . $row['reason']), $row['reason'] === 'manual' ? 'neutral' : 'cancelled') ?> <span class="muted text-sm"><?= e(t('marketing.source.' . $row['source'])) ?></span></td>
                <td class="muted"><span class="ltr"><?= e(local_time($row['created_at'])) ?></span></td>
                <td class="actions-cell">
                    <?php if ($canManage): ?>
                        <form method="post" action="/marketing/suppressions/<?= e($row['id']) ?>/delete">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button link-button--danger" data-confirm="<?= e(t('marketing.remove_suppression_confirm')) ?>"><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?>
            <tr><td colspan="5" class="muted"><?= e(t('marketing.no_suppressions')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
