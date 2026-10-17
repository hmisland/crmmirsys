<?php
/** @var array $followUps */
/** @var string $statusFilter */
/** @var bool $overdueFilter */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_now;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
?>
<section class="card">
    <h1><?= e(t('followups.title')) ?></h1>
    <p>
        <a href="/followups/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('followups.new')) ?></a>
        <a href="/followups/rules" class="btn"><?= icon('zap') ?><?= e(t('followups.rules_title')) ?></a>
    </p>

    <form method="get" action="/followups" class="inline-form-row">
        <select aria-label="<?= e(t('deals.status')) ?>" name="status" data-autosubmit>
            <option value=""><?= e(t('followups.all_statuses')) ?></option>
            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>><?= e(t('followups.status.pending')) ?></option>
            <option value="scheduled" <?= $statusFilter === 'scheduled' ? 'selected' : '' ?>><?= e(t('followups.status.scheduled')) ?></option>
            <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>><?= e(t('followups.status.in_progress')) ?></option>
            <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>><?= e(t('followups.status.completed')) ?></option>
            <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>><?= e(t('followups.status.cancelled')) ?></option>
        </select>
        <label class="check">
            <input type="checkbox" name="overdue" value="1" <?= $overdueFilter ? 'checked' : '' ?>> <?= e(t('followups.overdue_only')) ?>
        </label>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/followups" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../partials/pagination.php'; ?>

    <?php $bulkAction = '/followups/bulk-delete'; include __DIR__ . '/../partials/bulk-bar.php'; ?>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th class="cell-check"><input type="checkbox" data-bulk-all aria-label="<?= e(t('bulk.select_page')) ?>" title="<?= e(t('bulk.select_page')) ?>"></th><th><?= e(t('common.name')) ?></th><th><?= e(t('followups.type')) ?></th><th><?= e(t('followups.due_at')) ?></th><th><?= e(t('followups.priority')) ?></th><th><?= e(t('deals.status')) ?></th><th><?= e(t('followups.assignee')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($followUps as $f): ?>
            <?php
                $isOverdue = $f['due_at'] !== null && in_array($f['status'], ['pending', 'scheduled', 'in_progress'], true) && $f['due_at'] < local_now();
            ?>
            <tr>
                <td class="cell-check"><input type="checkbox" name="ids[]" value="<?= e($f['id']) ?>" form="bulk-form" data-bulk-item aria-label="<?= e(t('bulk.select_row')) ?>" <?= ($canDelete[(int) $f['id']] ?? false) ? '' : 'disabled' ?>></td>
                <td><a href="/followups/<?= e($f['id']) ?>"><?= e($f['title']) ?></a><?= $f['is_automated'] ? ' <span class="muted">(' . e(t('followups.automated_badge')) . ')</span>' : '' ?></td>
                <td><?= e(t('followups.type.' . $f['type'])) ?></td>
                <td class="<?= $isOverdue ? 'text-danger' : 'muted' ?>"><?= e($f['due_at'] ?? '—') ?><?= $isOverdue ? ' (' . e(t('followups.overdue')) . ')' : '' ?></td>
                <td><?= e(t('followups.priority.' . $f['priority'])) ?></td>
                <td><?= badge(t('followups.status.' . $f['status']), $f['status']) ?></td>
                <td class="muted"><?= e(trim((string) ($f['assignee_name'] ?? ''))) ?: '—' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($followUps === []): ?>
            <tr><td colspan="7" class="muted"><?= e(t('followups.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
