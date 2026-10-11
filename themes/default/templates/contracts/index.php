<?php
/** @var array $contracts */
/** @var array<int, array{edit: bool, delete: bool, reason: string}> $rowActions */
/** @var \App\Infrastructure\Pagination\Pagination $pagination */
/** @var array $categories */
/** @var string $q */
/** @var string $status */
/** @var int $category */
/** @var bool $mine */
/** @var bool $expiring */
/** @var bool $canCreate */
/** @var bool $canManageSettings */
/** @var \App\Infrastructure\Localization\LocaleFormatter $fmt */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\help_link;

include __DIR__ . '/_helpers.php';
?>
<section class="card">
    <h1><?= e(t('contracts.title')) ?><?= help_link('deal-contract-sales-project') ?></h1>

    <?php if ($canCreate || $canManageSettings): ?>
    <div class="actions">
        <?php if ($canCreate): ?>
            <a href="/contracts/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('contracts.new')) ?></a>
        <?php endif; ?>
        <?php if ($canManageSettings): ?>
            <a href="/contracts/categories" class="btn"><?= icon('tag') ?><?= e(t('contracts.categories_title')) ?></a>
            <a href="/contracts/settings" class="btn"><?= icon('settings') ?><?= e(t('contracts.settings_title')) ?></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <form method="get" action="/contracts" class="inline-form-row filter-bar">
        <?php foreach ($partyFilter ?? [] as $partyKey => $partyId): ?>
            <input type="hidden" name="<?= e($partyKey) ?>" value="<?= e($partyId) ?>">
        <?php endforeach; ?>
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('contracts.search_placeholder')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <select name="status" aria-label="<?= e(t('contracts.status')) ?>">
            <option value=""><?= e(t('contracts.all_statuses')) ?></option>
            <?php foreach (['draft', 'pending_approval', 'active', 'expired', 'terminated', 'cancelled'] as $s): ?>
                <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(t('contracts.status.' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($categories !== []): ?>
            <select name="category" aria-label="<?= e(t('contracts.category')) ?>">
                <option value=""><?= e(t('contracts.all_categories')) ?></option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= e($cat['id']) ?>" <?= $category === (int) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <label class="check"><input type="checkbox" name="expiring" value="1" <?= $expiring ? 'checked' : '' ?>> <?= e(t('contracts.expiring_soon')) ?></label>
        <label class="check"><input type="checkbox" name="mine" value="1" <?= $mine ? 'checked' : '' ?>> <?= e(t('filters.only_mine')) ?></label>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/contracts" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../partials/pagination.php'; ?>

    <div class="table-responsive">
    <table class="table">
        <thead><tr>
            <th><?= e(t('contracts.title_field')) ?></th>
            <th><?= e(t('contracts.party')) ?></th>
            <th><?= e(t('contracts.status')) ?></th>
            <th><?= e(t('contracts.period')) ?></th>
            <th class="num"><?= e(t('contracts.value')) ?></th>
            <th><span class="visually-hidden"><?= e(t('contacts.row_actions')) ?></span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($contracts as $c): ?>
            <?php $actions = $rowActions[(int) $c['id']] ?? ['edit' => false, 'delete' => false]; ?>
            <tr>
                <td>
                    <a href="/contracts/<?= e($c['id']) ?>"><?= e($c['title']) ?></a>
                    <?php if (!empty($c['contract_number'])): ?><div class="muted text-sm"><span class="ltr"><?= e($c['contract_number']) ?></span></div><?php endif; ?>
                </td>
                <td><?= e($contractParty($c)) ?></td>
                <td><?= badge(t('contracts.status.' . $c['effective_status']), $contractTone($c['effective_status'])) ?></td>
                <td class="nowrap"><?= e($fmt->date($c['start_date'])) ?> – <?= e($fmt->date($c['end_date'])) ?></td>
                <td class="num"><?= e($contractValue($fmt, $c['value'] ?? null, $c['currency_code'] ?? null)) ?></td>
                <td class="actions-cell"><?php $editUrl = '/contracts/' . $c['id'] . '/edit'; $deleteUrl = '/contracts/' . $c['id'] . '/delete'; include __DIR__ . '/../partials/row-actions.php'; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($contracts === []): ?>
            <tr><td colspan="6" class="muted"><?= e(t('contracts.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
