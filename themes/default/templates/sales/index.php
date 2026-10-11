<?php
/** @var array $deals */
/** @var string $q */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\help_link;
?>
<section class="card">
    <h1><?= e(t('deals.title')) ?><?= help_link('deal-contract-sales-project') ?></h1>

    <div class="actions">
        <a href="/deals/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('deals.new')) ?></a>
        <a href="/deals/board" class="btn"><?= icon('kanban') ?><?= e(t('deals.board_view')) ?></a>
    </div>

    <form method="get" action="/deals" class="inline-form-row filter-bar">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('deals.search_placeholder')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <select name="status" aria-label="<?= e(t('deals.status')) ?>">
            <option value=""><?= e(t('filters.all_statuses')) ?></option>
            <?php foreach (['open', 'won', 'lost'] as $s): ?>
                <option value="<?= e($s) ?>" <?= ($status ?? '') === $s ? 'selected' : '' ?>><?= e(t('deals.status.' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/deals" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../partials/pagination.php'; ?>

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
