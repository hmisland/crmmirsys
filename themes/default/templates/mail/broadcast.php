<?php
/** @var array $recentBroadcasts */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(t('mail.broadcast')) ?></h1>
    <p class="muted"><?= e(t('mail.broadcast_hint')) ?></p>
    <p><a href="/mail" class="btn"><?= icon('arrow-left') ?><?= e(t('mail.back_to_inbox')) ?></a></p>
</section>

<section class="card card--form">
    <h2><?= e(t('mail.send_to_team')) ?></h2>
    <p class="muted"><?= e(t('mail.send_to_team_hint')) ?></p>
    <form method="post" action="/mail/broadcast/team">
        <?= Csrf::field() ?>
        <label for="team_subject"><?= e(t('mail.subject')) ?></label>
        <input type="text" id="team_subject" name="subject" required>
        <label for="team_body"><?= e(t('mail.message')) ?></label>
        <textarea id="team_body" name="body" rows="5" required></textarea>
        <button type="submit" class="btn btn--primary"><?= icon('send') ?><?= e(t('mail.send_to_team_btn')) ?></button>
    </form>
</section>

<section class="card card--form">
    <h2><?= e(t('mail.send_to_customers')) ?></h2>
    <p class="muted"><?= e(t('mail.send_to_customers_hint')) ?></p>
    <form method="post" action="/mail/broadcast/customers">
        <?= Csrf::field() ?>
        <label for="cust_subject"><?= e(t('mail.subject')) ?></label>
        <input type="text" id="cust_subject" name="subject" required>
        <label for="cust_body"><?= e(t('mail.message')) ?></label>
        <textarea id="cust_body" name="body" rows="5" required></textarea>
        <button type="submit" class="btn btn--primary"><?= icon('send') ?><?= e(t('mail.send_to_customers_btn')) ?></button>
    </form>
</section>

<section class="card">
    <h2><?= e(t('mail.recent_broadcasts')) ?></h2>
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('mail.subject')) ?></th><th><?= e(t('mail.sent_count')) ?></th><th><?= e(t('mail.failed_count')) ?></th><th><?= e(t('wallet.date')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($recentBroadcasts as $b): ?>
            <tr>
                <td><?= e($b['subject']) ?></td>
                <td><?= e($b['recipient_count']) ?></td>
                <td><?= e($b['failed_count']) ?></td>
                <td class="muted"><?= e(local_time($b['created_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($recentBroadcasts === []): ?>
            <tr><td colspan="4" class="muted"><?= e(t('mail.no_broadcasts_yet')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
