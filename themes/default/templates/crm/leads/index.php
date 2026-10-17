<?php
/** @var array $leads */
/** @var string $q */
/** @var string $status */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;

$statuses = ['new', 'contacted', 'qualified', 'unqualified', 'converted'];
?>
<section class="card">
    <h1><?= e(t('leads.title')) ?></h1>

    <div class="actions">
        <a href="/leads/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('leads.new')) ?></a>
    </div>

    <form method="get" action="/leads" class="inline-form-row filter-bar">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('leads.search_placeholder')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
        <select aria-label="<?= e(t('leads.status')) ?>" name="status">
            <option value=""><?= e(t('leads.all_statuses')) ?></option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(t('leads.status.' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="check"><input type="checkbox" name="mine" value="1" <?= ($mine ?? false) ? 'checked' : '' ?>> <?= e(t('filters.only_mine')) ?></label>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/leads" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../../partials/pagination.php'; ?>

    <?php $bulkAction = '/leads/bulk-delete'; include __DIR__ . '/../../partials/bulk-bar.php'; ?>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th class="cell-check"><input type="checkbox" data-bulk-all aria-label="<?= e(t('bulk.select_page')) ?>" title="<?= e(t('bulk.select_page')) ?>"></th><th><?= e(t('common.name')) ?></th><th><?= e(t('common.company')) ?></th><th><?= e(t('leads.status')) ?></th><th><?= e(t('leads.score')) ?></th><th><span class="visually-hidden"><?= e(t('contacts.row_actions')) ?></span></th></tr></thead>
        <tbody>
        <?php foreach ($leads as $lead): ?>
            <?php $actions = $rowActions[(int) $lead['id']] ?? ['edit' => false, 'delete' => false]; ?>
            <tr>
                <td class="cell-check"><input type="checkbox" name="ids[]" value="<?= e($lead['id']) ?>" form="bulk-form" data-bulk-item aria-label="<?= e(t('bulk.select_row')) ?>" <?= $actions['delete'] ? '' : 'disabled' ?>></td>
                <td><a href="/leads/<?= e($lead['id']) ?>"><?= e(trim($lead['first_name'] . ' ' . ($lead['last_name'] ?? ''))) ?></a></td>
                <td><?= e($lead['company_name'] ?? '—') ?></td>
                <td><?= badge(t('leads.status.' . $lead['status']), $lead['status']) ?></td>
                <td><?= e($lead['score']) ?></td>
                <td class="actions-cell"><?php if ($canCreateFollowUp ?? false): ?><a href="/followups/create?related_type=lead&amp;related_id=<?= e($lead['id']) ?>" class="btn btn--sm"><?= icon('calendar-check') ?><?= e(t('followups.new')) ?></a><?php endif; ?><?php $editUrl = '/leads/' . $lead['id'] . '/edit'; $deleteUrl = '/leads/' . $lead['id'] . '/delete'; include __DIR__ . '/../../partials/row-actions.php'; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($leads === []): ?>
            <tr><td colspan="6" class="muted"><?= e(t('leads.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
