<?php
/** @var array $tickets */
/** @var string $q */
/** @var string $status */
/** @var array $statuses */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
?>
<section class="card">
    <h1><?= e(t('tickets.title')) ?></h1>

    <form method="get" action="/support/tickets" class="inline-form-row">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('tickets.search_placeholder')) ?>">
        <select aria-label="<?= e(t('deals.status')) ?>" name="status">
            <option value=""><?= e(t('leads.all_statuses')) ?></option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(t('tickets.status.' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
        <a href="/support/tickets/board" class="btn"><?= icon('kanban') ?><?= e(t('deals.board_view')) ?></a>
        <a href="/support/tickets/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('tickets.new')) ?></a>
    </form>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('tickets.subject')) ?></th><th><?= e(t('common.contact')) ?></th><th><?= e(t('deals.status')) ?></th><th><?= e(t('tickets.priority')) ?></th><th><?= e(t('tickets.assignee')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($tickets as $ticket): ?>
            <tr>
                <td><a href="/support/tickets/<?= e($ticket['id']) ?>"><?= e($ticket['subject']) ?></a></td>
                <td><?= e($ticket['contact_name'] ?? '—') ?></td>
                <td><?= badge(t('tickets.status.' . $ticket['status']), $ticket['status']) ?></td>
                <td><?= e(t('tickets.priority.' . $ticket['priority'])) ?></td>
                <td><?= e($ticket['assignee_name'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($tickets === []): ?>
            <tr><td colspan="5" class="muted"><?= e(t('tickets.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
