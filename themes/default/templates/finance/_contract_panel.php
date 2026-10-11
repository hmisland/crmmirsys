<?php
/**
 * Body of the "Billing" panel on a contract page (Modules\Finance\FinancePanels).
 * The heading and card come from partials/related-panels.php.
 *
 * @var array|null $proformas null = section hidden (no quotes.view); rows already in the user's scope
 * @var array|null $invoices  null = section hidden (no invoices.view)
 * @var array<int, array{currency_id: int, issued: bool, billed: string, credited: ?string, net_billed: ?string, paid: string, balance: string, refund_due: string, paid_on_cancelled: string}> $summary per currency (ContractBillingService::panelFor)
 * @var mixed $contractValue
 * @var int|null $contractCurrencyId
 * @var array<int, string> $codes currency id => code
 * @var bool $canCreateProforma
 * @var bool $canCreateInvoice
 * @var int $contractId
 * @var array|null $schedule  null = section hidden (no invoices.view / contract not viewable); InstallmentService::scheduleFor()
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;

/** Stored decimal for display: locale grouping, decimals only when there are any. */
$money = function ($amount, ?int $currencyId) use ($fmt, $codes): string {
    $code = $currencyId !== null ? ($codes[$currencyId] ?? null) : null;

    return \App\Infrastructure\Theme\money((string) $amount, $code, $fmt) . ($currencyId !== null && ($codes[$currencyId] ?? '') !== '' ? ' ' . $codes[$currencyId] : '');
};
?>
<?php if ($canCreateProforma || $canCreateInvoice): ?>
<div class="actions">
    <?php if ($canCreateProforma): ?>
        <a href="/finance/proformas/create?from_contract=<?= e($contractId) ?>" class="btn btn--sm"><?= icon('plus') ?><?= e(t('proformas.new')) ?></a>
    <?php endif; ?>
    <?php if ($canCreateInvoice): ?>
        <a href="/finance/invoices/create?from_contract=<?= e($contractId) ?>" class="btn btn--sm"><?= icon('plus') ?><?= e(t('invoices.new')) ?></a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($invoices !== null): ?>
<dl class="fact-row">
    <?php if (array_filter($summary, fn (array $s): bool => !empty($s['issued'])) === []): ?>
        <div class="fact"><dt><?= icon('receipt') ?><?= e(t('finance.billed')) ?></dt><dd>—</dd></div>
    <?php endif; ?>
    <?php foreach ($summary as $s): ?>
        <?php if (!empty($s['issued'])): ?>
            <div class="fact"><dt><?= icon('receipt') ?><?= e(t('finance.billed')) ?></dt><dd><?= e($money($s['billed'], $s['currency_id'])) ?></dd></div>
            <?php if (($s['credited'] ?? null) !== null): ?>
                <div class="fact"><dt><?= icon('undo') ?><?= e(t('finance.credited')) ?></dt><dd><?= e($money($s['credited'], $s['currency_id'])) ?></dd></div>
                <div class="fact"><dt><?= icon('receipt') ?><?= e(t('finance.net_billed')) ?></dt><dd><?= e($money($s['net_billed'], $s['currency_id'])) ?></dd></div>
            <?php endif; ?>
            <div class="fact"><dt><?= icon('check-circle') ?><?= e(t('finance.paid')) ?></dt><dd><?= e($money($s['paid'], $s['currency_id'])) ?></dd></div>
            <div class="fact"><dt><?= icon('coins') ?><?= e(t('finance.balance')) ?></dt><dd><?= e($money($s['balance'], $s['currency_id'])) ?></dd></div>
            <?php if (bccomp((string) ($s['refund_due'] ?? '0'), '0', 18) > 0): ?>
                <div class="fact"><dt><?= icon('alert-triangle') ?><?= e(t('finance.refund_due')) ?></dt><dd><?= e($money($s['refund_due'], $s['currency_id'])) ?></dd></div>
            <?php endif; ?>
            <?php if ($contractValue !== null && $contractValue !== '' && $contractCurrencyId === (int) $s['currency_id']): ?>
                <div class="fact"><dt><?= icon('file-text') ?><?= e(t('finance.contract_value')) ?></dt><dd><?= e($money($contractValue, $contractCurrencyId)) ?></dd></div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endforeach; ?>
</dl>
<?php foreach ($summary as $s): ?>
    <?php if (bccomp((string) ($s['paid_on_cancelled'] ?? '0'), '0', 18) > 0): ?>
        <div class="alert alert--warning"><div class="alert__body">
            <?= icon('alert-triangle') ?> <?= e(t('finance.paid_on_cancelled')) ?>: <strong><?= e($money($s['paid_on_cancelled'], $s['currency_id'])) ?></strong>
            <div class="field-hint"><?= e(t('finance.paid_on_cancelled_hint')) ?></div>
        </div></div>
    <?php endif; ?>
<?php endforeach; ?>
<p class="field-hint"><?= e(t('finance.billing_hint')) ?></p>
<?php endif; ?>

<?php foreach (['proformas' => $proformas, 'invoices' => $invoices] as $kind => $rows): ?>
    <?php if ($rows === null) { continue; } ?>
    <h3><?= e(t($kind . '.title')) ?></h3>
    <div class="table-responsive">
    <table class="table table--compact">
        <thead><tr>
            <th><?= e(t($kind . '.number')) ?></th>
            <th><?= e(t('wallet.date')) ?></th>
            <th class="num"><?= e(t('deals.value')) ?></th>
            <th><?= e(t('deals.status')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $d): ?>
            <?php
            $number = !empty($d['custom_number']) ? $d['custom_number'] : ($kind === 'invoices' && !empty($d['invoice_number']) ? $d['invoice_number'] : '#' . $d['id']);
            ?>
            <tr>
                <td><a href="/finance/<?= e($kind) ?>/<?= e($d['id']) ?>"><span class="ltr"><?= e($number) ?></span></a></td>
                <td class="nowrap"><?= e($fmt->date($d['issue_date'])) ?></td>
                <td class="num"><?= e($money($d['grand_total'], (int) $d['currency_id'])) ?></td>
                <td><?= badge(t($kind . '.status.' . $d['status']), $d['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?>
            <tr><td colspan="4" class="muted"><?= e(t($kind . '.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
<?php endforeach; ?>

<?php if (($schedule ?? null) !== null): ?>
    <?php include __DIR__ . '/_installments.php'; ?>
<?php endif; ?>
