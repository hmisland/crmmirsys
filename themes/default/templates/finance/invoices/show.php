<?php
/** @var array $invoice */
/** @var array $items */
/** @var array|null $contact */
/** @var bool $canIssue */
/** @var bool $canCancel */
/** @var bool $canRecordPayment */
/** @var array $payments */
/** @var string $paymentStatus */
/** @var string $outstanding */
/** @var bool $canIssueCreditNote */
/** @var array $creditNotes */
/** @var string $totalCredited */
/** @var string $effectiveTotal */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;

$isDraft = $invoice['status'] === 'draft';
?>
<section class="card">
    <h1>
        <?= e(t('invoices.title_single')) ?>
        <?= $invoice['invoice_number'] !== null ? e($invoice['invoice_number']) : '#' . e($invoice['id']) . ' (' . e(t('invoices.not_issued_yet')) . ')' ?>
    </h1>
    <p class="muted">
        <?= e(t('proformas.issue_date')) ?>: <?= e($invoice['issue_date']) ?>
        <?= $invoice['due_date'] ? ' · ' . e(t('invoices.due_date')) . ' ' . e($invoice['due_date']) : '' ?>
        · <?= e(t('deals.status')) ?>: <?= e(t('invoices.status.' . $invoice['status'])) ?>
        <?php if ($invoice['status'] === 'issued'): ?>
            · <?= e(t('payments.payment_status')) ?>: <?= e(t('payments.status.' . $paymentStatus)) ?>
        <?php endif; ?>
    </p>
    <?php if ($contact !== null): ?>
        <p><?= e(t('common.contact')) ?>: <a href="/contacts/<?= e($contact['id']) ?>"><?= e(trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''))) ?></a></p>
    <?php endif; ?>

    <?php if ($isDraft): ?>
        <div class="alert alert--error"><?= e(t('invoices.draft_hint')) ?></div>
    <?php endif; ?>

    <p>
        <?php if ($isDraft): ?>
            <a href="/finance/invoices/<?= e($invoice['id']) ?>/edit" class="btn"><?= e(t('common.edit')) ?></a>
            <form method="post" action="/finance/invoices/<?= e($invoice['id']) ?>/delete" class="inline-form">
                <?= Csrf::field() ?>
                <button type="submit" class="link-button"><?= e(t('common.delete')) ?></button>
            </form>
            <?php if ($canIssue): ?>
                <form method="post" action="/finance/invoices/<?= e($invoice['id']) ?>/issue" class="inline-form">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn--primary"><?= e(t('invoices.issue_action')) ?></button>
                </form>
            <?php else: ?>
                <span class="muted"><?= e(t('invoices.awaiting_approval')) ?></span>
            <?php endif; ?>
        <?php elseif ($invoice['status'] === 'issued' && $canCancel): ?>
            <form method="post" action="/finance/invoices/<?= e($invoice['id']) ?>/cancel" class="inline-form">
                <?= Csrf::field() ?>
                <button type="submit" class="link-button"><?= e(t('invoices.cancel_action')) ?></button>
            </form>
        <?php endif; ?>
    </p>
</section>

<section class="card">
    <h2><?= e(t('proformas.line_items')) ?></h2>
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('contacts.description')) ?></th><th><?= e(t('proformas.qty')) ?></th><th><?= e(t('proformas.unit_price')) ?></th><th><?= e(t('proformas.line_total')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><?= e($item['description']) ?></td>
                <td><?= e(rtrim(rtrim((string) $item['quantity'], '0'), '.')) ?></td>
                <td><?= e(number_format((float) $item['unit_price'], 0)) ?></td>
                <td><?= e(number_format((float) $item['line_total'], 0)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <table class="table table--compact" style="max-width:320px;margin-inline-start:auto;margin-top:1rem">
        <tr><td><?= e(t('proformas.subtotal')) ?></td><td><?= e(number_format((float) $invoice['subtotal'], 0)) ?></td></tr>
        <tr><td><?= e(t('proformas.invoice_discount')) ?></td><td><?= e(number_format((float) $invoice['invoice_discount'], 0)) ?></td></tr>
        <tr><td><?= e(t('proformas.tax_total')) ?></td><td><?= e(number_format((float) $invoice['tax_total'], 0)) ?></td></tr>
        <tr><td><strong><?= e(t('proformas.grand_total')) ?></strong></td><td><strong><?= e(number_format((float) $invoice['grand_total'], 0)) ?></strong></td></tr>
        <?php if (bccomp($totalCredited, '0', 8) > 0): ?>
        <tr><td><?= e(t('credit_notes.total_credited')) ?></td><td>-<?= e(number_format((float) $totalCredited, 0)) ?></td></tr>
        <tr><td><strong><?= e(t('credit_notes.effective_total')) ?></strong></td><td><strong><?= e(number_format((float) $effectiveTotal, 0)) ?></strong></td></tr>
        <?php endif; ?>
    </table>

    <?php if (!empty($invoice['terms']) || !empty($invoice['notes'])): ?>
        <div style="margin-top:1rem">
            <?php if (!empty($invoice['terms'])): ?><p><strong><?= e(t('proformas.terms')) ?>:</strong> <?= nl2br(e($invoice['terms'])) ?></p><?php endif; ?>
            <?php if (!empty($invoice['notes'])): ?><p><strong><?= e(t('common.notes')) ?>:</strong> <?= nl2br(e($invoice['notes'])) ?></p><?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($invoice['status'] === 'issued'): ?>
<section class="card">
    <h2><?= e(t('payments.title')) ?></h2>
    <p>
        <?= e(t('payments.outstanding')) ?>: <strong><?= e(number_format((float) $outstanding, 0)) ?></strong>
    </p>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('wallet.date')) ?></th><th><?= e(t('wallet.amount')) ?></th><th><?= e(t('payments.method')) ?></th><th><?= e(t('payments.reference')) ?></th><th><?= e(t('payments.recorded_by')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
            <tr>
                <td class="muted"><?= e($p['payment_date']) ?></td>
                <td><?= e(number_format((float) $p['amount'], 0)) ?></td>
                <td><?= e(t('payments.method.' . $p['payment_method'])) ?></td>
                <td class="muted"><?= e($p['payment_reference'] ?? '—') ?></td>
                <td class="muted"><?= e(trim((string) $p['recorded_by_name'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($payments === []): ?>
            <tr><td colspan="5" class="muted"><?= e(t('payments.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php if ($canRecordPayment && bccomp($outstanding, '0', 8) > 0): ?>
    <div class="card--form" style="margin-top:1rem;max-width:500px;padding:0">
    <form method="post" action="/finance/invoices/<?= e($invoice['id']) ?>/payments">
        <?= Csrf::field() ?>
        <h3><?= e(t('payments.record_a_payment')) ?></h3>

        <label for="amount"><?= e(t('wallet.amount')) ?></label>
        <input type="number" id="amount" name="amount" step="0.01" min="0.01" max="<?= e($outstanding) ?>" value="<?= e($outstanding) ?>" required>

        <label for="payment_method"><?= e(t('payments.method')) ?></label>
        <select id="payment_method" name="payment_method">
            <option value="bank_transfer"><?= e(t('payments.method.bank_transfer')) ?></option>
            <option value="cash"><?= e(t('payments.method.cash')) ?></option>
            <option value="card"><?= e(t('payments.method.card')) ?></option>
            <option value="other"><?= e(t('payments.method.other')) ?></option>
        </select>

        <label for="payment_reference"><?= e(t('payments.reference')) ?></label>
        <input type="text" id="payment_reference" name="payment_reference" placeholder="<?= e(t('payments.reference_placeholder')) ?>">

        <label for="payment_date"><?= e(t('payments.payment_date')) ?></label>
        <input type="text" class="crm-datepicker" id="payment_date" name="payment_date" value="<?= e(date('Y-m-d')) ?>" required>

        <label for="payment_notes"><?= e(t('common.notes')) ?></label>
        <input type="text" id="payment_notes" name="notes">

        <button type="submit" class="btn btn--primary" style="margin-top:0.5rem"><?= e(t('payments.record_action')) ?></button>
    </form>
    </div>
    <?php endif; ?>
</section>

<section class="card">
    <h2><?= e(t('credit_notes.title')) ?></h2>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('invoices.number')) ?></th><th><?= e(t('wallet.date')) ?></th><th><?= e(t('wallet.amount')) ?></th><th><?= e(t('credit_notes.reason')) ?></th><th><?= e(t('payments.recorded_by')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($creditNotes as $cn): ?>
            <tr>
                <td><?= e($cn['credit_note_number']) ?></td>
                <td class="muted"><?= e($cn['issue_date']) ?></td>
                <td><?= e(number_format((float) $cn['amount'], 0)) ?></td>
                <td><?= e($cn['reason']) ?></td>
                <td class="muted"><?= e(trim((string) $cn['issued_by_name'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($creditNotes === []): ?>
            <tr><td colspan="5" class="muted"><?= e(t('credit_notes.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php if ($canIssueCreditNote && $invoice['status'] === 'issued' && bccomp($effectiveTotal, '0', 8) > 0): ?>
    <div class="card--form" style="margin-top:1rem;max-width:500px;padding:0">
    <form method="post" action="/finance/invoices/<?= e($invoice['id']) ?>/credit-notes">
        <?= Csrf::field() ?>
        <h3><?= e(t('credit_notes.issue_a_credit_note')) ?></h3>

        <label for="cn_amount"><?= e(t('wallet.amount')) ?></label>
        <input type="number" id="cn_amount" name="amount" step="0.01" min="0.01" max="<?= e($effectiveTotal) ?>" required>

        <label for="cn_reason"><?= e(t('credit_notes.reason')) ?></label>
        <input type="text" id="cn_reason" name="reason" placeholder="<?= e(t('credit_notes.reason_placeholder')) ?>" required>

        <label for="cn_issue_date"><?= e(t('proformas.issue_date')) ?></label>
        <input type="text" class="crm-datepicker" id="cn_issue_date" name="issue_date" value="<?= e(date('Y-m-d')) ?>" required>

        <button type="submit" class="btn btn--primary" style="margin-top:0.5rem"><?= e(t('credit_notes.issue_action')) ?></button>
    </form>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
