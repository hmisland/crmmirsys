<?php
/** @var array $statuses */
/** @var array $ticketsByStatus */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(t('tickets.title')) ?></h1>
    <p>
        <a href="/support/tickets/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('tickets.new')) ?></a>
        <a href="/support/tickets" class="btn"><?= icon('list') ?><?= e(t('deals.list_view')) ?></a>
        <a href="/support/categories" class="btn"><?= icon('tag') ?><?= e(t('tickets.categories')) ?></a>
    </p>
</section>

<div class="kanban">
    <?php foreach ($statuses as $status): ?>
        <?php $tickets = $ticketsByStatus[$status] ?? []; ?>
        <div class="kanban__column">
            <h3><?= e(t('tickets.status.' . $status)) ?> <span class="muted">(<?= count($tickets) ?>)</span></h3>
            <?php foreach ($tickets as $ticket): ?>
                <div class="kanban__card">
                    <a href="/support/tickets/<?= e($ticket['id']) ?>"><?= e($ticket['subject']) ?></a>
                    <div class="muted">
                        <?= e(t('tickets.priority.' . $ticket['priority'])) ?>
                        <?= $ticket['contact_name'] ? ' · ' . e($ticket['contact_name']) : '' ?>
                        <?= $ticket['assignee_name'] ? ' · ' . e($ticket['assignee_name']) : '' ?>
                    </div>
                    <form method="post" action="/support/tickets/<?= e($ticket['id']) ?>/status" class="kanban__move">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="return_to" value="/support/tickets/board">
                        <select aria-label="<?= e(t('deals.status')) ?>" name="status" onchange="this.form.submit()">
                            <?php foreach ($statuses as $s): ?>
                                <option value="<?= e($s) ?>" <?= $s === $status ? 'selected' : '' ?>><?= e(t('tickets.status.' . $s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if ($tickets === []): ?><p class="muted"><?= e(t('tickets.no_tickets')) ?></p><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
