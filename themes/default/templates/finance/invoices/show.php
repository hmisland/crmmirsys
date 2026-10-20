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
/** @var bool $canViewCreditNotes credit_notes.view (Patch B) */
/** @var bool $canCreditMore */
/** @var array $creditNotes empty without credit_notes.view */
/** @var string|null $totalCredited null without credit_notes.view */
/** @var string|null $effectiveTotal null without credit_notes.view */
/** @var string|null $cancelBlockedReason why an issued invoice can't be cancelled (translated); null = it can */
/** @var array<int, array{at: string, by: string, description: string}> $cancellations cancel history from the timeline */
/** @var string|null $currencyCode the invoice currency (display precision) */
/** @var array<int, array{type: string, at: string, by: string, description: string}> $financialHistory payment / credit-note events the user may see */
/** @var string $moneyStep smallest amount a money input accepts */
/** @var bool $roundingDefault the organization's final-rounding default */
/** @var int $roundingPrecision final-rounding target decimals (IRR 0) */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_today;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\help_link;
use function App\Infrastructure\Theme\money;
use App\Infrastructure\Security\Csrf;
use Modules\Finance\SubmissionKeyRepository;

$isDraft = $invoice['status'] === 'draft';
?>
<section class="card">
    <h1>
        <?= e(t('invoices.title_single')) ?>
        <?= !empty($invoice['custom_number']) ? e($invoice['custom_number']) : ($invoice['invoice_number'] !== null ? e($invoice['invoice_number']) : '#' . e($invoice['id']) . ' (' . e(t('invoices.not_issued_yet')) . ')') ?>
        <?= help_link('finance-proformas-invoices') ?>
    </h1>
    <p class="muted">
        <?= e(t('proformas.issue_date')) ?>: <?= e($invoice['issue_date']) ?>
        <?= $invoice['due_date'] ? ' · ' . e(t('invoices.due_date')) . ' ' . e($invoice['due_date']) : '' ?>
        · <?= e(t('deals.status')) ?>: <?= badge(t('invoices.status.' . $invoice['status']), $invoice['status']) ?>
        <?php if ($invoice['status'] === 'issued'): ?>
            · <?= e(t('payments.payment_status')) ?>: <?= badge(t('payments.status.' . $paymentStatus), $paymentStatus) ?>
        <?php endif; ?>
    </p>
    <?php if (($contractLink ?? null) !== null): ?>
        <p><?= e(t('finance.contract')) ?>: <?php if ($contractLink['url'] !== null): ?><a href="<?= e($contractLink['url']) ?>"><?= e($contractLink['label']) ?></a><?php else: ?><?= e($contractLink['label']) ?><?php endif; ?></p>
    <?php endif; ?>
    <?php if ($contact !== null): ?>
        <p><?= e(t('common.contact')) ?>: <a href="/contacts/<?= e($contact['id']) ?>"><?= e(trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''))) ?></a></p>
    <?php endif; ?>

    <?php if ($isDraft): ?>
        <div class="alert alert--error"><?= e(t('invoices.draft_hint')) ?></div>
    <?php endif; ?>

    <div class="actions">
        <a href="/finance/invoices/<?= e($invoice['id']) ?>/print" class="btn" target="_blank" rel="noopener"><?= icon('file-text') ?><?= e(t('print.print_view')) ?></a>
        <a href="/finance/invoices/<?= e($invoice['id']) ?>/pdf" class="btn"><?= icon('download') ?><?= e(t('print.download_pdf')) ?></a>
        <?php if ($invoice['status'] === 'issued'): ?>
            <a href="/finance/invoices/tax-export/download?ids[]=<?= e($invoice['id']) ?>" class="btn"><?= icon('download') ?><?= e(t('tax_export.download_one')) ?></a>
        <?php endif; ?>
        <?php if ($isDraft): ?>
            <a href="/finance/invoices/<?= e($invoice['id']) ?>/edit" class="btn"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
            <form method="post" action="/finance/invoices/<?= e($invoice['id']) ?>/delete" class="inline-form">
                <?= Csrf::field() ?>
                <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
            </form>
            <?php if ($canIssue): ?>
                <form method="post" action="/finance/invoices/<?= e($invoice['id']) ?>/issue" class="inline-form">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="final_rounding" value="0">
                    <label class="check" title="<?= e(t('finance.final_rounding.issue_hint', ['decimals' => $roundingPrecision])) ?>"><input type="checkbox" name="final_rounding" value="1"<?= $roundingDefault ? ' checked' : '' ?>> <?= e(t('finance.final_rounding.issue_option', ['decimals' => $roundingPrecision])) ?></label><?= help_link('finance-final-rounding') ?>
                    <button type="submit" class="btn btn--primary"><?= icon('check-circle') ?><?= e(t('invoices.issue_action')) ?></button>
                </form>
            <?php else: ?>
                <span class="muted"><?= e(t('invoices.awaiting_approval')) ?></span>
            <?php endif; ?>
        <?php elseif ($invoice['status'] === 'issued' && $canCancel && ($cancelBlockedReason ?? null) !== null): ?>
            <span data-tooltip="<?= e($cancelBlockedReason) ?>"><button type="button" class="link-button" disabled><?= icon('ban') ?><?= e(t('invoices.cancel_action')) ?></button></span>
        <?php endif; ?>
    </div>

    <?php if ($invoice['status'] === 'issued' && $canCancel && ($cancelBlockedReason ?? null) === null): ?>
        <form method="post" action="/finance/invoices/<?= e($invoice['id']) ?>/cancel" class="inline-form-row">
            <?= Csrf::field() ?>
            <label for="cancel_reason"><?= e(t('invoices.cancel_reason')) ?></label>
            <input type="text" id="cancel_reason" name="reason" maxlength="450" required>
            <button type="submit" class="btn btn--danger" data-confirm><?= icon('ban') ?><?= e(t('invoices.cancel_action')) ?></button>
        </form>
    <?php elseif ($invoice['status'] === 'issued' && $canCancel): ?>
        <p class="field-hint"><?= icon('info') ?> <?= e($cancelBlockedReason) ?></p>
    <?php endif; ?>

    <?php if ($invoice['status'] === 'cancelled' && bccomp((string) $invoice['paid_total'], '0', 8) > 0): ?>
        <div class="alert alert--warning"><div class="alert__body">
            <?= icon('alert-triangle') ?> <?= e(t('invoices.cancelled_with_payments')) ?>
        </div></div>
    <?php endif; ?>

    <?php if (($cancellations ?? []) !== []): ?>
        <h2><?= e(t('invoices.cancellation_history')) ?></h2>
        <ul class="item-list">
            <?php foreach ($cancellations as $c): ?>
                <li><span class="muted"><?= e($c['at']) ?> · <?= e($c['by']) ?></span> — <?= e($c['description']) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="card">
    <h2><?= e(t('proformas.line_items')) ?><?= help_link('finance-amounts-discounts-tax') ?></h2>
    <?php $rowsCurrency = $currencyCode; include __DIR__ . '/../_document-rows.php'; ?>

    <table class="table table--compact" style="max-width:320px;margin-inline-start:auto;margin-top:1rem">
        <tr><td><?= e(t('proformas.subtotal')) ?></td><td><?= e(money($invoice['subtotal'], $currencyCode)) ?></td></tr>
        <tr><td><?= e(t('proformas.invoice_discount')) ?></td><td><?= e(money($invoice['invoice_discount'], $currencyCode)) ?></td></tr>
        <tr><td><?= e(t('proformas.tax_total')) ?></td><td><?= e(money($invoice['tax_total'], $currencyCode)) ?></td></tr>
        <?php if ((int) ($invoice['final_rounding'] ?? 0) === 1): ?>
        <tr><td><?= e(t('finance.final_rounding.before')) ?></td><td><?= e(money($invoice['unrounded_total'], $currencyCode)) ?></td></tr>
        <tr><td><?= e(t('finance.final_rounding.adjustment')) ?></td><td><?= e(money($invoice['rounding_adjustment'], $currencyCode)) ?></td></tr>
        <?php endif; ?>
        <tr><td><strong><?= e(t('proformas.grand_total')) ?></strong></td><td><strong><?= e(money($invoice['grand_total'], $currencyCode)) ?></strong></td></tr>
        <?php if ($canViewCreditNotes && bccomp($totalCredited, '0', 18) > 0): ?>
        <tr><td><?= e(t('credit_notes.total_credited')) ?></td><td>-<?= e(money($totalCredited, $currencyCode)) ?></td></tr>
        <tr><td><strong><?= e(t('credit_notes.effective_total')) ?></strong></td><td><strong><?= e(money($effectiveTotal, $currencyCode)) ?></strong></td></tr>
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
    <h2><?= e(t('payments.title')) ?><?= help_link('finance-payments-balance') ?></h2>
    <p>
        <?= e(t('payments.outstanding')) ?>: <strong><?= e(money($outstanding, $currencyCode)) ?></strong>
    </p>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('wallet.date')) ?></th><th><?= e(t('wallet.amount')) ?></th><th><?= e(t('payments.method')) ?></th><th><?= e(t('payments.reference')) ?></th><th><?= e(t('payments.recorded_by')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
            <tr>
                <td class="muted"><?= e($p['payment_date']) ?></td>
                <td><?= e(money($p['amount'], $currencyCode)) ?></td>
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

    <?php if ($canRecordPayment && bccomp($outstanding, '0', 18) > 0): ?>
    <div class="card--form" style="margin-top:1rem;max-width:500px;padding:0">
    <form method="post" action="/finance/invoices/<?= e($invoice['id']) ?>/payments">
        <?= Csrf::field() ?>
        <input type="hidden" name="submission_key" value="<?= e(SubmissionKeyRepository::newKey()) ?>">
        <h3><?= e(t('payments.record_a_payment')) ?></h3>

        <label for="amount"><?= e(t('wallet.amount')) ?></label>
        <input type="number" id="amount" name="amount" step="<?= e($moneyStep) ?>" min="<?= e($moneyStep) ?>" max="<?= e($outstanding) ?>" value="<?= e($outstanding) ?>" required>

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
        <input type="text" class="crm-datepicker" id="payment_date" name="payment_date" value="<?= e(local_today()) ?>" required>

        <label for="payment_notes"><?= e(t('common.notes')) ?></label>
        <input type="text" id="payment_notes" name="notes">

        <button type="submit" class="btn btn--primary" style="margin-top:0.5rem"><?= icon('credit-card') ?><?= e(t('payments.record_action')) ?></button>
    </form>
    </div>
    <?php endif; ?>
</section>

<?php $showCreditNoteForm = $canIssueCreditNote && $invoice['status'] === 'issued' && $canCreditMore; ?>
<?php if ($canViewCreditNotes || $showCreditNoteForm): ?>
<section class="card">
    <h2><?= e(t('credit_notes.title')) ?><?= help_link('finance-credit-notes-cancel') ?></h2>

    <?php if ($canViewCreditNotes): ?>
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('invoices.number')) ?></th><th><?= e(t('wallet.date')) ?></th><th><?= e(t('wallet.amount')) ?></th><th><?= e(t('credit_notes.reason')) ?></th><th><?= e(t('payments.recorded_by')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($creditNotes as $cn): ?>
            <tr>
                <td><?= e($cn['credit_note_number']) ?></td>
                <td class="muted"><?= e($cn['issue_date']) ?></td>
                <td><?= e(money($cn['amount'], $currencyCode)) ?></td>
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
    <?php endif; ?>

    <?php if ($showCreditNoteForm): ?>
    <div class="card--form" style="margin-top:1rem;max-width:500px;padding:0">
    <form method="post" action="/finance/invoices/<?= e($invoice['id']) ?>/credit-notes">
        <?= Csrf::field() ?>
        <input type="hidden" name="submission_key" value="<?= e(SubmissionKeyRepository::newKey()) ?>">
        <h3><?= e(t('credit_notes.issue_a_credit_note')) ?></h3>

        <label for="cn_amount"><?= e(t('wallet.amount')) ?></label>
        <input type="number" id="cn_amount" name="amount" step="<?= e($moneyStep) ?>" min="<?= e($moneyStep) ?>"<?php if ($canViewCreditNotes): ?> max="<?= e($effectiveTotal) ?>"<?php endif; ?> required>

        <label for="cn_reason"><?= e(t('credit_notes.reason')) ?></label>
        <input type="text" id="cn_reason" name="reason" placeholder="<?= e(t('credit_notes.reason_placeholder')) ?>" required>

        <label for="cn_issue_date"><?= e(t('proformas.issue_date')) ?></label>
        <input type="text" class="crm-datepicker" id="cn_issue_date" name="issue_date" value="<?= e(local_today()) ?>" required>

        <button type="submit" class="btn btn--primary" style="margin-top:0.5rem"><?= icon('receipt') ?><?= e(t('credit_notes.issue_action')) ?></button>
    </form>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php endif; ?>

<?php if ($financialHistory !== []): ?>
<section class="card">
    <h2><?= icon('activity') ?><?= e(t('finance.history.title')) ?><?= help_link('finance-permissions-audit') ?></h2>
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('finance.history.at')) ?></th><th><?= e(t('finance.history.action')) ?></th><th><?= e(t('finance.history.details')) ?></th><th><?= e(t('finance.history.by')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($financialHistory as $event): ?>
            <tr>
                <td class="muted"><span class="ltr"><?= e(substr($event['at'], 0, 16)) ?></span></td>
                <td><?= e(t('finance.history.' . $event['type'])) ?></td>
                <td><span class="ltr"><?= e($event['description']) ?></span></td>
                <td class="muted"><?= e($event['by']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>
