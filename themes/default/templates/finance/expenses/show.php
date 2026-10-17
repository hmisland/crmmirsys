<?php
/**
 * Expense detail (Phase 4): the record, its corrections and net (originals),
 * receipts, and the actions this user may take right now (each re-checked on
 * the server). Data: ExpenseService::details().
 *
 * @var array $expense
 * @var array|null $original the original (for a correction) or the expense itself
 * @var array $corrections
 * @var string|null $net net of the original (original ± recorded corrections)
 * @var array{id: int, label: ?string, url: ?string}|null $project
 * @var array{id: int, label: ?string, url: ?string}|null $contract Stage 5.1: reference only, no financial effect
 * @var string|null $payee
 * @var array $attachments
 * @var array $timeline
 * @var bool $can_edit
 * @var bool $can_void
 * @var bool $can_correct
 * @var bool $can_manage_receipts
 * @var array<int, string> $codes
 * @var array $contacts
 * @var array $companies
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\local_today;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;

$code = $codes[(int) $expense['currency_id']] ?? '';
$money = fn ($amount): string => \App\Infrastructure\Theme\money($amount, $code); // currency display policy (L2)
$isCorrection = $expense['entry_type'] === 'correction';
$signedLabel = fn (array $row): string => ($row['entry_type'] === 'correction' && $row['correction_direction'] === 'decrease' ? '−' : ($row['entry_type'] === 'correction' ? '+' : '')) . $money($row['amount']);
?>
<section class="card">
    <h1>
        <?= e($expense['description']) ?>
        <?= badge(t('finance.expense.status.' . $expense['status']), $expense['status'] === 'void' ? 'cancelled' : 'active') ?>
        <?php if ($isCorrection): ?><?= badge(t('finance.expense.correction'), 'info') ?><?php endif; ?>
    </h1>
    <div class="actions">
        <a href="/finance/expenses" class="btn btn--ghost"><?= icon('list') ?><?= e(t('finance.expense.title')) ?></a>
        <?php if ($can_edit): ?>
            <a href="/finance/expenses/<?= e($expense['id']) ?>/edit" class="btn"><?= icon('pencil') ?><?= e(t('finance.expense.edit')) ?></a>
        <?php endif; ?>
    </div>

    <dl class="fact-row">
        <div class="fact"><dt><?= icon('tag') ?><?= e(t('finance.expense.category')) ?></dt><dd><?= e(t('finance.expense.category.' . $expense['category'])) ?></dd></div>
        <div class="fact"><dt><?= icon('calendar') ?><?= e(t('finance.expense.date')) ?></dt><dd><?= e($expense['expense_date']) ?></dd></div>
        <div class="fact"><dt><?= icon('coins') ?><?= e(t($isCorrection ? 'finance.expense.correction_effect' : 'finance.expense.amount')) ?></dt><dd><?= e($signedLabel($expense)) ?> <span class="ltr"><?= e($code) ?></span></dd></div>
        <?php if ($net !== null && $corrections !== []): ?>
            <div class="fact"><dt><?= icon('bar-chart') ?><?= e(t('finance.expense.net')) ?></dt><dd><?= e($money($net)) ?> <span class="ltr"><?= e($code) ?></span></dd></div>
        <?php endif; ?>
        <?php if (!empty($expense['reference_number'])): ?>
            <div class="fact"><dt><?= icon('file-text') ?><?= e(t('finance.expense.reference')) ?></dt><dd><span class="ltr"><?= e($expense['reference_number']) ?></span></dd></div>
        <?php endif; ?>
        <?php if ($payee !== null): ?>
            <div class="fact"><dt><?= icon('user') ?><?= e(t('finance.expense.payee')) ?></dt><dd><?= e($payee) ?></dd></div>
        <?php endif; ?>
        <?php if ($project !== null): ?>
            <div class="fact"><dt><?= icon('kanban') ?><?= e(t('finance.expense.project')) ?></dt><dd>
                <?php if ($project['url'] !== null): ?><a href="<?= e($project['url']) ?>"><?= e((string) $project['label']) ?></a><?php else: ?><span class="muted"><?= e(t('finance.expense.project_unavailable')) ?></span><?php endif; ?>
            </dd></div>
        <?php endif; ?>
        <?php if (($contract ?? null) !== null): ?>
            <div class="fact"><dt><?= icon('file-text') ?><?= e(t('finance.expense.contract_reference')) ?></dt><dd>
                <?php if ($contract['url'] !== null): ?><a href="<?= e($contract['url']) ?>"><?= e((string) $contract['label']) ?></a><?php else: ?><span class="muted"><?= e(t('finance.expense.contract_unavailable')) ?></span><?php endif; ?>
            </dd></div>
        <?php endif; ?>
    </dl>
    <?php if (($contract ?? null) !== null): ?>
        <p class="field-hint"><?= icon('info') ?> <?= e(t('finance.expense.contract_note')) ?></p>
    <?php endif; ?>
    <?php if ($isCorrection && $original !== null): ?>
        <p><?= icon('undo') ?> <?= e(t('finance.expense.correction_of')) ?> <a href="/finance/expenses/<?= e($original['id']) ?>"><?= e($original['description']) ?></a></p>
    <?php endif; ?>
    <?php if ($expense['status'] === 'void'): ?>
        <div class="alert alert--warning"><div class="alert__body"><?= e(t('finance.expense.voided_because')) ?>: <?= e((string) $expense['void_reason']) ?></div></div>
    <?php endif; ?>
</section>

<?php if (!$isCorrection && ($corrections !== [] || $can_correct)): ?>
<section class="card">
    <h2><?= e(t('finance.expense.corrections')) ?></h2>
    <div class="table-responsive">
    <table class="table table--compact">
        <thead><tr><th><?= e(t('finance.expense.date')) ?></th><th><?= e(t('finance.expense.description')) ?></th><th class="num"><?= e(t('finance.expense.correction_effect')) ?></th><th><?= e(t('deals.status')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($corrections as $c): ?>
            <tr>
                <td class="nowrap"><?= e($c['expense_date']) ?></td>
                <td><a href="/finance/expenses/<?= e($c['id']) ?>"><?= e($c['description']) ?></a></td>
                <td class="num nowrap"><?= e($signedLabel($c)) ?> <span class="ltr"><?= e($code) ?></span></td>
                <td><?= badge(t('finance.expense.status.' . $c['status']), $c['status'] === 'void' ? 'cancelled' : 'active') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($corrections === []): ?>
            <tr><td colspan="4" class="muted"><?= e(t('finance.expense.no_corrections')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php if ($can_correct): ?>
    <div class="card--form">
        <h3><?= e(t('finance.expense.new_correction')) ?></h3>
        <p class="field-hint"><?= e(t('finance.expense.correction_hint')) ?></p>
        <form method="post" action="/finance/expenses/<?= e($expense['id']) ?>/corrections">
            <?= Csrf::field() ?>
            <label for="correction_direction"><?= e(t('finance.expense.direction')) ?></label>
            <select id="correction_direction" name="correction_direction" required>
                <option value="increase"><?= e(t('finance.expense.direction.increase')) ?></option>
                <option value="decrease"><?= e(t('finance.expense.direction.decrease')) ?></option>
            </select>
            <label for="correction_amount"><?= e(t('finance.expense.amount')) ?> (<span class="ltr"><?= e($code) ?></span>)</label>
            <input type="text" inputmode="decimal" id="correction_amount" name="amount" required>
            <label for="correction_date"><?= e(t('finance.expense.date')) ?></label>
            <input type="text" class="crm-datepicker" id="correction_date" name="expense_date" value="<?= e(local_today()) ?>" required>
            <label for="correction_description"><?= e(t('finance.expense.description')) ?></label>
            <input type="text" id="correction_description" name="description" maxlength="255" required>
            <label for="correction_reference"><?= e(t('finance.expense.reference')) ?></label>
            <input type="text" id="correction_reference" name="reference_number" maxlength="100">
            <button type="submit" class="btn btn--primary"><?= icon('plus') ?><?= e(t('finance.expense.record_correction')) ?></button>
        </form>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
    <h2><?= e(t('finance.expense.receipts')) ?></h2>
    <ul class="item-list">
        <?php foreach ($attachments as $a): ?>
            <li>
                <a href="/finance/expenses/<?= e($expense['id']) ?>/attachments/<?= e($a['id']) ?>"><?= icon('paperclip') ?> <?= e($a['filename']) ?></a>
                <?php if ($can_manage_receipts): ?>
                    <form method="post" action="/finance/expenses/<?= e($expense['id']) ?>/attachments/<?= e($a['id']) ?>/delete" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('finance.expense.remove_receipt')) ?></button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        <?php if ($attachments === []): ?>
            <li class="muted"><?= e(t('finance.expense.no_receipts')) ?></li>
        <?php endif; ?>
    </ul>
    <?php if ($can_manage_receipts): ?>
        <form method="post" action="/finance/expenses/<?= e($expense['id']) ?>/attachments" enctype="multipart/form-data" class="inline-form-row">
            <?= Csrf::field() ?>
            <input type="file" name="file" required aria-label="<?= e(t('finance.expense.receipt_file')) ?>">
            <button type="submit" class="btn btn--sm"><?= icon('upload') ?><?= e(t('finance.expense.add_receipt')) ?></button>
        </form>
    <?php endif; ?>
</section>

<?php if ($can_void): ?>
<section class="card card--form">
    <h2><?= e(t($isCorrection ? 'finance.expense.void_correction' : 'finance.expense.void')) ?></h2>
    <form method="post" action="/finance/expenses/<?= e($expense['id']) ?>/void">
        <?= Csrf::field() ?>
        <label for="void_reason"><?= e(t('finance.expense.void_reason')) ?></label>
        <input type="text" id="void_reason" name="reason" maxlength="450" required>
        <button type="submit" class="btn btn--danger" data-confirm><?= icon('ban') ?><?= e(t($isCorrection ? 'finance.expense.void_correction' : 'finance.expense.void')) ?></button>
    </form>
</section>
<?php endif; ?>

<section class="card">
    <h2><?= e(t('finance.expense.history')) ?></h2>
    <ul class="item-list">
        <?php foreach ($timeline as $ev): ?>
            <li><span class="muted nowrap"><?= e(local_time($ev['created_at'])) ?></span> — <?= e($ev['description']) ?></li>
        <?php endforeach; ?>
        <?php if ($timeline === []): ?><li class="muted">—</li><?php endif; ?>
    </ul>
</section>
