<?php
/**
 * Super Admin → tickets from organizations: waiting ones first.
 *
 * @var list<array> $tickets
 * @var array{status: string, q: string} $filters
 * @var \App\Infrastructure\Pagination\Pagination $pagination
 */

use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
?>
<section class="card">
    <h1><?= e(t('platform_tickets.admin.title')) ?></h1>
    <p class="muted"><?= e(t('platform_tickets.admin.subtitle')) ?></p>

    <form method="get" action="/admin/tickets" class="inline-form-row filter-bar">
        <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('platform_tickets.admin.search_placeholder')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <select name="status" aria-label="<?= e(t('platform_tickets.field.status')) ?>">
            <option value=""><?= e(t('platform_tickets.all_statuses')) ?></option>
            <?php foreach (['open', 'answered', 'closed'] as $s): ?>
                <option value="<?= e($s) ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= e(t('platform_tickets.status.' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($filters['status'] !== '' || $filters['q'] !== ''): ?><a href="/admin/tickets" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../../partials/pagination.php'; ?>

    <div class="table-responsive">
    <table class="table">
        <thead><tr>
            <th><?= e(t('platform_tickets.field.number')) ?></th>
            <th><?= e(t('platform_tickets.field.subject')) ?></th>
            <th><?= e(t('platform_tickets.field.organization')) ?></th>
            <th><?= e(t('platform_tickets.field.category')) ?></th>
            <th><?= e(t('platform_tickets.field.priority')) ?></th>
            <th><?= e(t('platform_tickets.field.status')) ?></th>
            <th><?= e(t('platform_tickets.field.last_activity')) ?></th>
        </tr></thead>
        <tbody>
        <?php if ($tickets === []): ?>
            <tr><td colspan="7" class="muted"><?= e(t('platform_tickets.empty')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($tickets as $ticket): ?>
            <tr>
                <td class="ltr">#<?= e($ticket['id']) ?></td>
                <td><a href="/admin/tickets/<?= e($ticket['id']) ?>"><?= e($ticket['subject']) ?></a></td>
                <td><?= e($ticket['organization_name']) ?></td>
                <td><?= e(t('platform_tickets.category.' . $ticket['category'])) ?></td>
                <td><?= badge(t('platform_tickets.priority.' . $ticket['priority']), $ticket['priority'] === 'normal' ? null : ($ticket['priority'] === 'urgent' ? 'failed' : 'pending')) ?></td>
                <td><?= badge(t('platform_tickets.status.' . $ticket['status']), ['open' => 'open', 'answered' => 'pending'][$ticket['status']] ?? null) ?></td>
                <td class="nowrap ltr"><?= e(local_time($ticket['last_activity_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
