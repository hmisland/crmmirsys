<?php
/**
 * "Payment schedule" section of the contract Billing panel (Stage 2),
 * included by finance/_contract_panel.php. Rows come from
 * InstallmentService::scheduleFor(): a linked document the user may not open
 * arrives as `restricted` with nothing about it (no number, amount, status,
 * overdue or review flag). An installment is a plan, never a receivable:
 * none of these figures feeds the Billing summary above.
 *
 * @var array{rows: array, totals: array<int, string>, can_add: bool, currency_id: ?int, currencies: array} $schedule
 * @var mixed $contractValue
 * @var int|null $contractCurrencyId
 * @var array<int, string> $codes currency id => code
 * @var int $contractId
 * @var callable $money (amount, currency id) → display string
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\help_link;

// Status → badge tone (badge() picks the colour from these raw values).
$tone = ['unpaid' => 'unpaid', 'issued' => 'issued', 'partially_paid' => 'partial', 'paid' => 'paid', 'void' => 'cancelled'];
?>
<h3><?= e(t('finance.installment.title')) ?><?= help_link('finance-installments') ?></h3>
<div class="table-responsive">
<table class="table table--compact">
    <thead><tr>
        <th><?= e(t('finance.installment.sequence')) ?></th>
        <th><?= e(t('finance.installment.due_date')) ?></th>
        <th class="num"><?= e(t('finance.installment.amount')) ?></th>
        <th><?= e(t('deals.status')) ?></th>
        <th><?= e(t('finance.installment.document')) ?></th>
        <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($schedule['rows'] as $row): ?>
        <tr>
            <td><?= e($fmt->digits((string) $row['sequence'])) ?></td>
            <td class="nowrap">
                <?= e($fmt->date($row['due_date'])) ?>
                <?php if ($row['overdue']): ?> <?= badge(t('finance.installment.overdue'), 'failed') ?><?php endif; ?>
            </td>
            <td class="num">
                <?= e($money($row['amount'], $row['currency_id'])) ?>
                <?php if ($row['description'] !== ''): ?><div class="field-hint"><?= e($row['description']) ?></div><?php endif; ?>
            </td>
            <td>
                <?php if ($row['status'] !== null): ?>
                    <?= badge(t('finance.installment.status.' . $row['status']), $tone[$row['status']] ?? null) ?>
                <?php endif; ?>
                <?php if ($row['void'] && $row['void_reason'] !== null): ?><div class="field-hint"><?= e($row['void_reason']) ?></div><?php endif; ?>
            </td>
            <td>
                <?php if ($row['restricted']): ?>
                    <span class="muted"><?= icon('lock') ?> <?= e(t('finance.installment.document_restricted')) ?></span>
                <?php elseif ($row['document'] !== null): ?>
                    <?php $doc = $row['document']; $kind = $doc['type'] === 'invoice' ? 'invoices' : 'proformas'; ?>
                    <a href="/finance/<?= e($kind) ?>/<?= e($doc['id']) ?>"><span class="ltr"><?= e($doc['number']) ?></span></a>
                    <?= badge(t($kind . '.status.' . $doc['status']), $doc['status']) ?>
                    <?php if ($row['review'] === 'currency'): ?>
                        <div class="field-hint"><?= icon('alert-triangle') ?> <?= e(t('finance.installment.review_currency')) ?></div>
                    <?php elseif ($row['review'] === 'amount'): ?>
                        <div class="field-hint"><?= icon('alert-triangle') ?> <?= e(t('finance.installment.review_amount')) ?>: <?= e($money($doc['amount'], $doc['currency_id'])) ?></div>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="muted">—</span>
                <?php endif; ?>
            </td>
            <td class="nowrap">
                <?php if ($row['can_create_proforma']): ?>
                    <a href="/finance/proformas/create?from_installment=<?= e($row['id']) ?>" class="btn btn--sm"><?= icon('plus') ?><?= e(t('proformas.new')) ?></a>
                <?php endif; ?>
                <?php if ($row['can_create_invoice']): ?>
                    <a href="/finance/invoices/create?from_installment=<?= e($row['id']) ?>" class="btn btn--sm"><?= icon('plus') ?><?= e(t('invoices.new')) ?></a>
                <?php endif; ?>
                <?php if ($row['can_edit'] || $row['can_void']): ?>
                    <a href="/finance/installments/<?= e($row['id']) ?>/edit" class="btn btn--sm btn--ghost"><?= icon('pencil') ?><?= e(t('finance.installment.manage')) ?></a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($schedule['rows'] === []): ?>
        <tr><td colspan="6" class="muted"><?= e(t('finance.installment.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<?php if ($schedule['totals'] !== []): ?>
<dl class="fact-row">
    <?php foreach ($schedule['totals'] as $currencyId => $total): ?>
        <div class="fact"><dt><?= icon('calendar') ?><?= e(t('finance.installment.scheduled_total')) ?></dt><dd><?= e($money($total, (int) $currencyId)) ?></dd></div>
    <?php endforeach; ?>
</dl>
<?php foreach ($schedule['totals'] as $currencyId => $total): ?>
    <?php if ($contractValue !== null && $contractValue !== '' && $contractCurrencyId === (int) $currencyId && bccomp((string) $total, (string) $contractValue, 18) !== 0): ?>
        <p class="field-hint"><?= icon('info') ?> <?= e(t('finance.installment.differs_from_value')) ?></p>
    <?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>
<p class="field-hint"><?= e(t('finance.installment.hint')) ?></p>

<?php if ($schedule['can_add']): ?>
<form method="post" action="/finance/contracts/<?= e($contractId) ?>/installments" class="inline-form-row">
    <?= Csrf::field() ?>
    <label for="installment_due_date"><?= e(t('finance.installment.due_date')) ?></label>
    <input type="text" class="crm-datepicker" id="installment_due_date" name="due_date" required>
    <label for="installment_amount"><?= e(t('finance.installment.amount')) ?></label>
    <input type="text" inputmode="decimal" id="installment_amount" name="amount" required>
    <?php if ($schedule['currency_id'] !== null): ?>
        <span class="ltr"><?= e($codes[$schedule['currency_id']] ?? '') ?></span>
    <?php else: ?>
        <label for="installment_currency"><?= e(t('finance.installment.currency')) ?></label>
        <select id="installment_currency" name="currency_id" required>
            <?php foreach ($schedule['currencies'] as $c): ?>
                <option value="<?= e($c['id']) ?>"><?= e($c['code']) ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
    <label for="installment_description"><?= e(t('finance.installment.description')) ?></label>
    <input type="text" id="installment_description" name="description" maxlength="255">
    <button type="submit" class="btn btn--sm btn--primary"><?= icon('plus') ?><?= e(t('finance.installment.add')) ?></button>
</form>
<?php endif; ?>
