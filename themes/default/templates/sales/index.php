<?php
/** @var array $deals */
/** @var string $q */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
?>
<section class="card">
    <h1><?= e(t('deals.title')) ?></h1>

    <form method="get" action="/deals" class="inline-form-row">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('deals.search_placeholder')) ?>">
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
        <a href="/deals/board" class="btn"><?= icon('kanban') ?><?= e(t('deals.board_view')) ?></a>
        <a href="/deals/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('deals.new')) ?></a>
    </form>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('common.name')) ?></th><th><?= e(t('nav.contacts')) ?></th><th><?= e(t('common.company')) ?></th><th><?= e(t('deals.value')) ?></th><th><?= e(t('deals.status')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($deals as $deal): ?>
            <tr>
                <td><a href="/deals/<?= e($deal['id']) ?>"><?= e($deal['name']) ?></a></td>
                <td><?= e($deal['contact_name'] ?? '—') ?></td>
                <td><?= e($deal['company_name'] ?? '—') ?></td>
                <td><?= e($deal['currency_code']) ?> <?= e(number_format((float) $deal['value'], 0)) ?></td>
                <td><?= badge(t('deals.status.' . $deal['status']), $deal['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($deals === []): ?>
            <tr><td colspan="5" class="muted"><?= e(t('deals.no_deals')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
