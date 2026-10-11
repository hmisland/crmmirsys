<?php
/**
 * Invoices tab — read-only view of this client's invoices and payments
 * as the Finance module reports them (invoices.view / payments.view
 * scopes already applied). Nothing financial is computed or stored
 * here beyond "total − paid"; every row links to the Finance page.
 * Variables come from show.php's scope.
 *
 * @var array<int, array> $payments invoice id => payments
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;

$money = fn ($amount, ?string $code = null): string => \App\Infrastructure\Theme\money($amount, $code); // currency display policy (L2)
$servicesByInvoice = [];
foreach ($services as $service) {
    if ($service['invoice_id'] !== null) {
        $servicesByInvoice[(int) $service['invoice_id']][] = $service['title'];
    }
}
$allPayments = [];
foreach ($invoices as $invoice) {
    foreach ($payments[(int) $invoice['id']] ?? [] as $payment) {
        $allPayments[] = $payment + ['invoice' => $invoice];
    }
}
usort($allPayments, fn ($a, $b) => strcmp((string) $b['payment_date'], (string) $a['payment_date']));
?>
<section class="card">
    <div class="card__header">
        <h2><?= icon('receipt') ?><?= e(t('client_records.tab.invoices')) ?></h2>
        <?php if ($can['create_invoice']): ?><a href="/finance/invoices/create" class="btn btn--sm"><?= icon('plus') ?><?= e(t('invoices.new')) ?></a><?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th><?= e(t('invoices.number')) ?></th>
                    <th><?= e(t('client_records.date')) ?></th>
                    <th><?= e(t('client_records.service')) ?></th>
                    <th class="num"><?= e(t('client_records.amount')) ?></th>
                    <th class="num"><?= e(t('client_records.paid')) ?></th>
                    <th class="num"><?= e(t('client_records.remaining')) ?></th>
                    <th><?= e(t('client_records.payment_status')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($invoices as $invoice): ?>
                <?php
                // Finance's rules (remediation P2): outstanding = grand − credited − paid;
                // status as PaymentService::paymentStatus() — unpaid / paid / partial.
                $effective = bcsub((string) $invoice['grand_total'], (string) ($invoice['credited_total'] ?? '0'), 18);
                $remaining = bcsub($effective, (string) $invoice['paid_total'], 18);
                if ($invoice['status'] !== 'issued') {
                    $payStatus = $invoice['status']; // draft / cancelled
                } elseif (bccomp((string) $invoice['paid_total'], '0', 18) <= 0) {
                    $payStatus = 'unpaid';
                } elseif (bccomp((string) $invoice['paid_total'], $effective, 18) >= 0) {
                    $payStatus = 'paid';
                } else {
                    $payStatus = 'partial';
                }
                ?>
                <tr>
                    <td><a href="/finance/invoices/<?= e($invoice['id']) ?>"><?= e($invoice['invoice_number'] ?? ('#' . $invoice['id'])) ?></a></td>
                    <td class="nowrap"><?= e($invoice['issue_date']) ?></td>
                    <td><?= e(isset($servicesByInvoice[(int) $invoice['id']]) ? implode(', ', $servicesByInvoice[(int) $invoice['id']]) : '—') ?></td>
                    <td class="num nowrap"><?= e($money($invoice['grand_total'], $invoice['currency_code'])) ?> <?= e($invoice['currency_code']) ?></td>
                    <td class="num nowrap"><?= e($money($invoice['paid_total'], $invoice['currency_code'])) ?></td>
                    <td class="num nowrap"><?= e($money($invoice['status'] === 'issued' ? $remaining : 0, $invoice['currency_code'])) ?></td>
                    <td><?= badge(t('client_records.pay_status.' . $payStatus), $payStatus) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($invoices === []): ?>
                <tr><td colspan="7" class="muted"><?= e(t('client_records.no_invoices')) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($can['payments']): ?>
<section class="card">
    <h2><?= icon('credit-card') ?> <?= e(t('client_records.payments')) ?></h2>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th><?= e(t('client_records.date')) ?></th>
                    <th><?= e(t('invoices.number')) ?></th>
                    <th><?= e(t('client_records.payment_method')) ?></th>
                    <th class="num"><?= e(t('client_records.amount')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($allPayments as $payment): ?>
                <tr>
                    <td class="nowrap"><?= e($payment['payment_date']) ?></td>
                    <td><a href="/finance/invoices/<?= e($payment['invoice']['id']) ?>"><?= e($payment['invoice']['invoice_number'] ?? ('#' . $payment['invoice']['id'])) ?></a></td>
                    <td><?= e(t('client_records.payment_method.' . $payment['payment_method'])) ?></td>
                    <td class="num nowrap"><?= e($money($payment['amount'], $payment['invoice']['currency_code'])) ?> <?= e($payment['invoice']['currency_code']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($allPayments === []): ?>
                <tr><td colspan="4" class="muted"><?= e(t('client_records.no_payments')) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
