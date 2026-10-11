<?php
/**
 * HTML fragment (no layout) for one related list on the contact page,
 * loaded into the related-records dialog.
 *
 * @var string $type invoices|proformas|deals|tasks|followups|appointments|tickets
 * @var array $rows
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;

$money = fn ($amount, ?string $currency) => \App\Infrastructure\Theme\money($amount, $currency) . ($currency ? ' ' . $currency : '');
$dateOnly = fn (?string $value) => $value ? substr($value, 0, 10) : '—';
$dateTime = fn (?string $value) => $value ? substr($value, 0, 16) : '—';

$columns = match ($type) {
    'invoices' => [t('invoices.number'), t('wallet.date'), t('deals.value'), t('contacts.related_paid'), t('deals.status')],
    'proformas' => [t('invoices.number'), t('wallet.date'), t('deals.value'), t('deals.status')],
    'deals' => [t('common.name'), t('contacts.related_stage'), t('deals.value'), t('deals.status')],
    'tasks' => [t('contacts.related_title'), t('contacts.related_due'), t('contacts.related_priority'), t('deals.status')],
    'followups' => [t('contacts.related_title'), t('contacts.related_type'), t('contacts.related_due'), t('deals.status')],
    'appointments' => [t('contacts.related_title'), t('contacts.related_starts'), t('deals.status')],
    'tickets' => [t('contacts.related_subject'), t('contacts.related_priority'), t('wallet.date'), t('deals.status')],
    default => [],
};

// Per-currency totals for the money lists (invoices: billed vs paid), decimal-safe.
// Balance due = billed − credited − paid: the invoice page's outstanding rule
// (remediation P2); credit-note details themselves are not shown here.
$totals = [];
if (in_array($type, ['invoices', 'proformas'], true)) {
    foreach ($rows as $r) {
        if (in_array($r['status'], ['cancelled', 'rejected', 'expired', 'draft'], true)) {
            continue;
        }
        $code = (string) ($r['currency_code'] ?? '');
        $totals[$code]['total'] = bcadd($totals[$code]['total'] ?? '0', (string) $r['grand_total'], 18);
        $totals[$code]['paid'] = bcadd($totals[$code]['paid'] ?? '0', (string) ($r['paid_total'] ?? '0'), 18);
        $totals[$code]['credited'] = bcadd($totals[$code]['credited'] ?? '0', (string) ($r['credited_total'] ?? '0'), 18);
    }
}
?>
<?php if ($totals !== []): ?>
    <dl class="fact-row">
        <?php foreach ($totals as $code => $sum): ?>
            <div class="fact"><dt><?= e(t('contacts.related_total')) ?></dt><dd class="ltr"><?= e($money($sum['total'], $code)) ?></dd></div>
            <?php if ($type === 'invoices'): ?>
                <div class="fact"><dt><?= e(t('contacts.related_paid')) ?></dt><dd class="ltr"><?= e($money($sum['paid'], $code)) ?></dd></div>
                <div class="fact"><dt><?= e(t('contacts.related_balance')) ?></dt><dd class="ltr"><?= e($money(bcsub(bcsub($sum['total'], $sum['credited'], 18), $sum['paid'], 18), $code)) ?></dd></div>
            <?php endif; ?>
        <?php endforeach; ?>
    </dl>
<?php endif; ?>

<div class="table-responsive">
<table class="table table--compact">
    <thead><tr><?php foreach ($columns as $label): ?><th><?= e($label) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
        <?php if ($type === 'invoices'): ?>
            <td><a href="/finance/invoices/<?= e($r['id']) ?>"><?= e($r['invoice_number'] ?? ('#' . $r['id'])) ?></a></td>
            <td><span class="ltr"><?= e($dateOnly($r['issue_date'])) ?></span></td>
            <td class="num"><span class="ltr"><?= e($money($r['grand_total'], $r['currency_code'])) ?></span></td>
            <td class="num"><span class="ltr"><?= e($money($r['paid_total'], $r['currency_code'])) ?></span></td>
            <td><?= badge(t('invoices.status.' . $r['status']), $r['status']) ?></td>
        <?php elseif ($type === 'proformas'): ?>
            <td><a href="/finance/proformas/<?= e($r['id']) ?>">#<?= e($r['id']) ?></a></td>
            <td><span class="ltr"><?= e($dateOnly($r['issue_date'])) ?></span></td>
            <td class="num"><span class="ltr"><?= e($money($r['grand_total'], $r['currency_code'])) ?></span></td>
            <td><?= badge(t('proformas.status.' . $r['status']), $r['status']) ?></td>
        <?php elseif ($type === 'deals'): ?>
            <td><a href="/deals/<?= e($r['id']) ?>"><?= e($r['name']) ?></a></td>
            <td><?= e($r['stage_name'] ?? '—') ?></td>
            <td class="num"><span class="ltr"><?= e($money($r['value'], $r['currency_code'])) ?></span></td>
            <td><?= badge(t('deals.status.' . $r['status']), $r['status']) ?></td>
        <?php elseif ($type === 'tasks'): ?>
            <td><a href="/tasks/<?= e($r['id']) ?>"><?= e($r['title']) ?></a></td>
            <td><span class="ltr"><?= e($dateTime($r['due_at'])) ?></span></td>
            <td><?= e(t('tasks.priority.' . $r['priority'])) ?></td>
            <td><?= badge(t('tasks.status.' . $r['status']), $r['status']) ?></td>
        <?php elseif ($type === 'followups'): ?>
            <td><a href="/followups/<?= e($r['id']) ?>"><?= e($r['title']) ?></a></td>
            <td><?= e(t('followups.type.' . $r['type'])) ?></td>
            <td><span class="ltr"><?= e($dateTime($r['due_at'])) ?></span></td>
            <td><?= badge(t('followups.status.' . $r['status']), $r['status']) ?></td>
        <?php elseif ($type === 'appointments'): ?>
            <td><a href="/appointments/<?= e($r['id']) ?>"><?= e($r['title'] ?: t('appointments.title')) ?></a></td>
            <td><span class="ltr"><?= e($dateTime($r['starts_at'])) ?></span></td>
            <td><?= badge(t('appointments.status.' . $r['status']), $r['status']) ?></td>
        <?php elseif ($type === 'tickets'): ?>
            <td><a href="/support/tickets/<?= e($r['id']) ?>"><?= e($r['subject']) ?></a></td>
            <td><?= e(t('tickets.priority.' . $r['priority'])) ?></td>
            <td><span class="ltr"><?= e($dateOnly($r['created_at'])) ?></span></td>
            <td><?= badge(t('tickets.status.' . $r['status']), $r['status']) ?></td>
        <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?>
        <tr><td colspan="<?= count($columns) ?>" class="muted"><?= e(t('contacts.related_empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
