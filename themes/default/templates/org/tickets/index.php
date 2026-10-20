<?php
/**
 * Organization → tickets to the platform team.
 *
 * @var list<array> $tickets
 * @var string $status
 * @var \App\Infrastructure\Pagination\Pagination $pagination
 */

use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
?>
<div class="page-head">
    <div>
        <h1><?= e(t('platform_tickets.title')) ?></h1>
        <p><?= e(t('platform_tickets.subtitle')) ?></p>
    </div>
    <div class="actions">
        <a href="/org/tickets/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('platform_tickets.new')) ?></a>
    </div>
</div>

<section class="card">
    <form method="get" action="/org/tickets" class="inline-form-row filter-bar">
        <select name="status" aria-label="<?= e(t('platform_tickets.field.status')) ?>">
            <option value=""><?= e(t('platform_tickets.all_statuses')) ?></option>
            <?php foreach (['open', 'answered', 'closed'] as $s): ?>
                <option value="<?= e($s) ?>"<?= $status === $s ? ' selected' : '' ?>><?= e(t('platform_tickets.status.' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($status !== ''): ?><a href="/org/tickets" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../../partials/pagination.php'; ?>

    <div class="table-responsive">
    <table class="table">
        <thead><tr>
            <th><?= e(t('platform_tickets.field.number')) ?></th>
            <th><?= e(t('platform_tickets.field.subject')) ?></th>
            <th><?= e(t('platform_tickets.field.status')) ?></th>
            <th><?= e(t('platform_tickets.field.creator')) ?></th>
            <th><?= e(t('platform_tickets.field.last_activity')) ?></th>
        </tr></thead>
        <tbody>
        <?php if ($tickets === []): ?>
            <tr><td colspan="5" class="muted"><?= e(t('platform_tickets.empty')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($tickets as $ticket): ?>
            <tr>
                <td class="ltr">#<?= e($ticket['id']) ?></td>
                <td><a href="/org/tickets/<?= e($ticket['id']) ?>"><?= e($ticket['subject']) ?></a></td>
                <td><?= badge(t('platform_tickets.status.' . $ticket['status']), ['open' => 'open', 'answered' => 'pending'][$ticket['status']] ?? null) ?></td>
                <td><?= e($ticket['creator_name'] !== '' ? $ticket['creator_name'] : '—') ?></td>
                <td class="nowrap ltr"><?= e(local_time($ticket['last_activity_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
