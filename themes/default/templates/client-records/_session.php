<?php
/**
 * One session/visit row. Expects in scope: $session, $record,
 * $servicesById, $users, $invoices, $can, and optionally
 * $quickStatus (bool) to show complete / no-show / cancel buttons.
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;
use App\Infrastructure\Security\Csrf;

$statusIcon = ['scheduled' => ['calendar', 'info'], 'completed' => ['check-circle', 'success'], 'cancelled' => ['x-circle', 'danger'], 'no_show' => ['ban', 'warning']];
[$sIcon, $sTone] = $statusIcon[$session['status']] ?? ['activity', 'brand'];
$service = $session['service_record_id'] !== null ? ($servicesById[$session['service_record_id']] ?? null) : null;
$title = $service !== null ? $service['title'] : (string) $session['title'];
$base = '/client-records/' . (int) $record['id'] . '/sessions/' . (int) $session['id'];
$invoiceNumbers = array_column($invoices, 'invoice_number', 'id');
$hasDetails = !empty($session['notes']) || !empty($session['observations']) || !empty($session['client_feedback']);
?>
<li>
    <span class="event__icon event__icon--<?= e($sTone) ?>"><?= icon($sIcon) ?></span>
    <div class="event__body">
        <span class="event__title">
            <?= e($title) ?><?php if (!empty($session['session_number'])): ?> <span class="muted">· <?= e(t('client_records.session_n', ['n' => $session['session_number']])) ?></span><?php endif; ?>
            <?= badge(t('client_records.session_status.' . $session['status']), $session['status']) ?>
        </span>
        <span class="event__meta">
            <span class="nowrap"><?= icon('clock', 'icon--sm') ?> <span class="ltr"><?= e(substr((string) $session['session_at'], 0, 16)) ?></span></span>
            <?php if ($session['provider_user_id'] !== null): ?><span><?= icon('user', 'icon--sm') ?> <?= e($users[$session['provider_user_id']] ?? '—') ?></span><?php endif; ?>
            <?php if (!empty($session['next_recommended_on'])): ?><span><?= e(t('client_records.next_recommended')) ?>: <span class="ltr"><?= e($session['next_recommended_on']) ?></span></span><?php endif; ?>
            <?php if ($session['follow_up_id'] !== null && $can['followups']): ?><a href="/followups/<?= e($session['follow_up_id']) ?>"><?= icon('calendar-check', 'icon--sm') ?> <?= e(t('client_records.linked_followup')) ?></a><?php endif; ?>
            <?php if ($session['invoice_id'] !== null && $can['invoices']): ?><a href="/finance/invoices/<?= e($session['invoice_id']) ?>"><?= icon('receipt', 'icon--sm') ?> <?= e($invoiceNumbers[$session['invoice_id']] ?? ('#' . $session['invoice_id'])) ?></a><?php endif; ?>
        </span>
        <?php if ($hasDetails): ?>
            <details>
                <summary class="link-button"><?= e(t('client_records.session_details')) ?></summary>
                <dl class="detail-list">
                    <?php if (!empty($session['notes'])): ?><dt><?= e(t('client_records.session_notes')) ?></dt><dd><p class="prose-text"><?= e($session['notes']) ?></p></dd><?php endif; ?>
                    <?php if (!empty($session['observations'])): ?><dt><?= icon('lock', 'icon--sm') ?> <?= e(t('client_records.observations')) ?></dt><dd><p class="prose-text"><?= e($session['observations']) ?></p></dd><?php endif; ?>
                    <?php if (!empty($session['client_feedback'])): ?><dt><?= e(t('client_records.client_feedback')) ?></dt><dd><p class="prose-text"><?= e($session['client_feedback']) ?></p></dd><?php endif; ?>
                </dl>
            </details>
        <?php endif; ?>
        <?php if ($can['sessions']): ?>
            <div class="actions">
                <?php if (!empty($quickStatus) && $session['status'] === 'scheduled'): ?>
                    <?php foreach (['completed' => 'check', 'no_show' => 'ban', 'cancelled' => 'x'] as $to => $toIcon): ?>
                        <form method="post" action="<?= e($base) ?>/status" class="inline-form">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="status" value="<?= e($to) ?>">
                            <button type="submit" class="btn btn--sm<?= $to === 'completed' ? ' btn--primary' : '' ?>"><?= icon($toIcon) ?><?= e(t('client_records.mark_' . $to)) ?></button>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
                <a href="<?= e($base) ?>/edit" class="btn btn--ghost btn--sm"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
                <form method="post" action="<?= e($base) ?>/delete" class="inline-form">
                    <?= Csrf::field() ?>
                    <button type="submit" class="link-button link-button--danger" data-confirm="<?= e(t('client_records.delete_session_confirm')) ?>"><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</li>
