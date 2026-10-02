<?php
/** @var string $balance */
/** @var array $transactions */
/** @var array $logs */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(t('notif.admin_title')) ?></h1>
    <p style="font-size:1.6rem;font-weight:700"><?= e(t('notif.credit_balance')) ?>: <?= e(number_format((float) $balance, 0)) ?></p>
</section>

<section class="card card--form">
    <h2><?= e(t('notif.charge_from_wallet')) ?></h2>
    <p class="muted"><?= e(t('notif.charge_hint')) ?></p>
    <form method="post" action="/notifications/admin/charge">
        <?= Csrf::field() ?>
        <label for="amount"><?= e(t('wallet.amount')) ?></label>
        <input type="number" id="amount" name="amount" min="1" required>
        <button type="submit" class="btn btn--primary"><?= e(t('notif.charge')) ?></button>
    </form>
</section>

<section class="card">
    <h2><?= e(t('notif.credit_history')) ?></h2>
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('wallet.date')) ?></th><th><?= e(t('wallet.type')) ?></th><th><?= e(t('wallet.amount')) ?></th><th><?= e(t('wallet.balance_after')) ?></th><th><?= e(t('contacts.description')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($transactions as $tx): ?>
            <tr>
                <td><?= e($tx['created_at']) ?></td>
                <td><?= e(t('notif.credit_type.' . $tx['type'])) ?></td>
                <td><?= e(number_format((float) $tx['amount'], 0)) ?></td>
                <td><?= e(number_format((float) $tx['balance_after'], 0)) ?></td>
                <td class="muted"><?= e($tx['description'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($transactions === []): ?>
            <tr><td colspan="5" class="muted"><?= e(t('wallet.no_activity')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="card">
    <h2><?= e(t('notif.delivery_log')) ?></h2>
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('wallet.date')) ?></th><th><?= e(t('notif.channel')) ?></th><th><?= e(t('deals.status')) ?></th><th><?= e(t('notif.detail')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td><?= e($log['created_at']) ?></td>
                <td><?= e(t('notif.channel.' . $log['channel'])) ?></td>
                <td><?= e(t('notif.log_status.' . $log['status'])) ?></td>
                <td class="muted"><?= e($log['detail'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($logs === []): ?>
            <tr><td colspan="4" class="muted"><?= e(t('notif.no_notifications_sent')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
