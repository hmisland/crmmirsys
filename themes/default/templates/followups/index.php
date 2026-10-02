<?php
/** @var array $followUps */
/** @var string $statusFilter */
/** @var bool $overdueFilter */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
?>
<section class="card">
    <h1><?= e(t('followups.title')) ?></h1>
    <p>
        <a href="/followups/create" class="btn btn--primary"><?= e(t('followups.new')) ?></a>
        <a href="/followups/rules" class="btn"><?= e(t('followups.rules_title')) ?></a>
    </p>

    <form method="get" action="/followups" class="inline-form-row">
        <select name="status" onchange="this.form.submit()">
            <option value=""><?= e(t('followups.all_statuses')) ?></option>
            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>><?= e(t('followups.status.pending')) ?></option>
            <option value="scheduled" <?= $statusFilter === 'scheduled' ? 'selected' : '' ?>><?= e(t('followups.status.scheduled')) ?></option>
            <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>><?= e(t('followups.status.in_progress')) ?></option>
            <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>><?= e(t('followups.status.completed')) ?></option>
            <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>><?= e(t('followups.status.cancelled')) ?></option>
        </select>
        <label style="display:inline-flex;align-items:center;gap:0.3rem;font-weight:normal">
            <input type="checkbox" name="overdue" value="1" <?= $overdueFilter ? 'checked' : '' ?> onchange="this.form.submit()"> <?= e(t('followups.overdue_only')) ?>
        </label>
    </form>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('common.name')) ?></th><th><?= e(t('followups.type')) ?></th><th><?= e(t('followups.due_at')) ?></th><th><?= e(t('followups.priority')) ?></th><th><?= e(t('deals.status')) ?></th><th><?= e(t('followups.assignee')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($followUps as $f): ?>
            <?php
                $isOverdue = $f['due_at'] !== null && in_array($f['status'], ['pending', 'scheduled', 'in_progress'], true) && $f['due_at'] < date('Y-m-d H:i:s');
            ?>
            <tr>
                <td><a href="/followups/<?= e($f['id']) ?>"><?= e($f['title']) ?></a><?= $f['is_automated'] ? ' <span class="muted">(' . e(t('followups.automated_badge')) . ')</span>' : '' ?></td>
                <td><?= e(t('followups.type.' . $f['type'])) ?></td>
                <td class="<?= $isOverdue ? 'text-danger' : 'muted' ?>"><?= e($f['due_at'] ?? '—') ?><?= $isOverdue ? ' (' . e(t('followups.overdue')) . ')' : '' ?></td>
                <td><?= e(t('followups.priority.' . $f['priority'])) ?></td>
                <td><?= e(t('followups.status.' . $f['status'])) ?></td>
                <td class="muted"><?= e(trim((string) ($f['assignee_name'] ?? ''))) ?: '—' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($followUps === []): ?>
            <tr><td colspan="6" class="muted"><?= e(t('followups.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
