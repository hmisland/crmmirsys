<?php
/**
 * The invoice / proforma document itself — rendered both inside the
 * browser print page (finance/print/page) and, unchanged, by the PDF
 * engine. Tables only (see finance/print/styles). All values are
 * view-model data from DocumentPrintService; formatting goes through
 * LocaleFormatter (digits, grouping, Jalali dates in Persian, words).
 *
 * @var array $doc
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 * @var string|null $logoSrc
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;

$isInvoice = $doc['type'] === 'invoice';
// Patch B: an invoice's payable (grand total minus credits) is null without
// credit_notes.view; the grand total is shown in its place.
$showPayable = $doc['totals']['payable'] !== null;
$headline = $showPayable ? $doc['totals']['payable'] : $doc['totals']['grand'];
$decimals = $doc['currency']['decimals'];
$code = $doc['currency']['code'];
$keyOr = function (string $key, string $fallback): string {
    $value = t($key);

    return $value === $key ? $fallback : $value;
};
$currencyShort = $keyOr('print.currency_short.' . $code, $code);
$currencyWords = $keyOr('print.currency_words.' . $code, $doc['currency']['name']);
// Stage 5.2 (L2/L3): the currency display policy (IRR up to 3 significant decimals, others their precision).
$money = fn (?string $amount): string => \App\Infrastructure\Theme\money((string) ($amount ?? '0'), $code, $fmt);
$moneyWithUnit = fn (?string $amount): string => $money($amount) . ' ' . $currencyShort;
$listSeparator = in_array($fmt->locale(), ['fa', 'ar'], true) ? '، ' : ', ';

$statusLabel = match (true) {
    !$isInvoice => t('proformas.status.' . $doc['status']),
    in_array($doc['status'], ['paid', 'partial', 'unpaid'], true) => t('payments.status.' . $doc['status']),
    default => t('invoices.status.' . $doc['status']),
};

/**
 * Lays label/value pairs out three per row (a "wide" pair takes two
 * cells), skipping empty values so missing details leave no blank rows.
 *
 * @param array<int, array{0: string, 1: ?string, 2?: bool, 3?: bool}> $pairs [label, value, wide, ltr]
 * @return array<int, array<int, array{label: string, value: string, span: int, ltr: bool}>>
 */
$rows = function (array $pairs): array {
    $rows = [];
    $row = [];
    $used = 0;
    foreach ($pairs as $pair) {
        if ($pair[1] === null || trim($pair[1]) === '') {
            continue;
        }
        $span = !empty($pair[2]) ? 2 : 1;
        if ($used + $span > 3) {
            $rows[] = $row;
            $row = [];
            $used = 0;
        }
        $row[] = ['label' => $pair[0], 'value' => $pair[1], 'span' => $span, 'ltr' => !empty($pair[3])];
        $used += $span;
    }
    if ($row !== []) {
        $rows[] = $row;
    }

    return $rows;
};

$seller = $doc['seller'];
$sellerRows = $rows([
    [t('print.name'), $seller['name']],
    [t('print.national_id'), $seller['national_id'] !== null ? $fmt->digits($seller['national_id']) : null],
    [t('print.economic_code'), $seller['economic_code'] !== null ? $fmt->digits($seller['economic_code']) : null],
    [t('print.registration_number'), $seller['registration_number'] !== null ? $fmt->digits($seller['registration_number']) : null],
    [t('print.address'), $seller['address'], true],
    [t('print.postal_code'), $seller['postal_code'] !== null ? $fmt->digits($seller['postal_code']) : null],
    [t('print.phone'), $seller['phone'] !== null ? $fmt->digits($seller['phone']) : null, false, true],
]);

$buyer = $doc['buyer'];
$buyerRows = $buyer === null ? [] : $rows([
    [t('print.name'), $buyer['name']],
    [t('print.company'), $buyer['company']],
    [t('print.buyer_national_id'), $buyer['national_id'] !== null ? $fmt->digits($buyer['national_id']) : null],
    [t('print.economic_code'), $buyer['economic_code'] !== null ? $fmt->digits($buyer['economic_code']) : null],
    [t('print.phone'), $buyer['phone'], false, true],
    [t('print.address'), $buyer['address_parts'] !== [] ? implode($listSeparator, $buyer['address_parts']) : null, true],
    [t('print.postal_code'), $buyer['postal_code'] !== null ? $fmt->digits($buyer['postal_code']) : null],
    [t('print.email'), $buyer['address_parts'] === [] ? $buyer['email'] : null, false, true],
]);

$totals = $doc['totals'];
// mPDF does not inherit direction into tables, so every table carries it explicitly.
$dir = in_array($fmt->locale(), ['fa', 'ar'], true) ? 'rtl' : 'ltr';
?>
<div class="doc" dir="<?= e($dir) ?>">
    <table class="doc-head" dir="<?= e($dir) ?>">
        <tr>
            <td class="w30">
                <?php if ($logoSrc !== null): ?>
                    <img class="doc-logo" src="<?= e($logoSrc) ?>" alt="<?= e(t('print.logo')) ?>">
                <?php endif; ?>
            </td>
            <td class="doc-title">
                <div class="doc-t1"><?= e(t($isInvoice ? 'print.invoice_title' : 'print.proforma_title')) ?></div>
                <?php if ($fmt->locale() !== 'en'): ?>
                    <div class="doc-t2"><?= e(t($isInvoice ? 'print.invoice_subtitle' : 'print.proforma_subtitle')) ?></div>
                <?php endif; ?>
            </td>
            <td class="w30">
                <table class="doc-meta" dir="<?= e($dir) ?>">
                    <tr><td class="k"><?= e(t('print.number')) ?>:</td><td class="v"><span class="ltr"><?= e($doc['number']) ?></span></td></tr>
                    <tr><td class="k"><?= e(t('print.issue_date')) ?>:</td><td class="v"><span class="ltr"><?= e($fmt->date($doc['issue_date'])) ?></span></td></tr>
                    <tr><td class="k"><?= e(t($isInvoice ? 'print.due_date' : 'print.valid_until')) ?>:</td><td class="v"><span class="ltr"><?= e($fmt->date($doc['second_date'])) ?></span></td></tr>
                    <tr><td class="k"><?= e(t('print.status')) ?>:</td><td class="v"><span class="doc-status"><?= e($statusLabel) ?></span></td></tr>
                </table>
            </td>
        </tr>
    </table>

    <?php foreach ([['print.seller', $sellerRows], ['print.buyer', $buyerRows]] as [$headingKey, $partyRows]): ?>
        <div class="doc-party">
            <div class="hd"><?= e(t($headingKey)) ?></div>
            <table dir="<?= e($dir) ?>">
                <?php foreach ($partyRows as $partyRow): ?>
                    <tr>
                        <?php $cells = 0; foreach ($partyRow as $cell): $cells += $cell['span']; ?>
                            <td<?= $cell['span'] > 1 ? ' colspan="2"' : '' ?>><span class="k"><?= e($cell['label']) ?>:</span>
                                <?php if ($cell['ltr']): ?><span class="ltr"><?= e($cell['value']) ?></span><?php else: ?><?= e($cell['value']) ?><?php endif; ?></td>
                        <?php endforeach; ?>
                        <?php for (; $cells < 3; $cells++): ?><td></td><?php endfor; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($partyRows === []): ?>
                    <tr><td colspan="3" class="k"><?= e(t('print.no_buyer')) ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
    <?php endforeach; ?>

    <table class="doc-items" dir="<?= e($dir) ?>">
        <thead>
            <tr>
                <th><?= e(t('print.row')) ?></th>
                <th><?= e(t('print.item_code')) ?></th>
                <th><?= e(t('print.description')) ?></th>
                <th><?= e(t('print.quantity')) ?></th>
                <th><?= e(t('print.unit_price')) ?></th>
                <th><?= e(t('print.gross')) ?></th>
                <th><?= e(t('print.discount')) ?></th>
                <th><?= e(t('print.net')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($doc['items'] as $i => $item): ?>
                <tr>
                    <td><?= e($fmt->digits((string) ($i + 1))) ?></td>
                    <td class="code"><?= e($item['code']) ?></td>
                    <td class="desc"><?= e($item['name']) ?><?php if ($item['note'] !== ''): ?><div class="desc-note"><?= e($item['note']) ?></div><?php endif; ?></td>
                    <td class="num"><?= e($fmt->digits($item['quantity'])) ?></td>
                    <td class="num"><?= e($money($item['unit_price'])) ?></td>
                    <td class="num"><?= e($money($item['gross'])) ?></td>
                    <td class="num"><?= e($money($item['discount'])) ?><?php if ($item['discount_percent'] !== null): ?> (<?= e($fmt->digits(($fmt->locale() === 'fa' || $fmt->locale() === 'ar') ? '٪' . $item['discount_percent'] : $item['discount_percent'] . '%')) ?>)<?php endif; ?></td>
                    <td class="num"><?= e($money($item['net'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <table class="doc-bottom" dir="<?= e($dir) ?>">
        <tr>
            <td class="col-main">
                <table class="doc-words" dir="<?= e($dir) ?>"><tr><td>
                    <div class="doc-words-k"><?= e(t($isInvoice && $showPayable ? 'print.words_payable' : 'print.words_total')) ?></div>
                    <?= e($fmt->words($headline) . ' ' . $currencyWords) ?>
                </td></tr></table>
                <div class="doc-notes">
                    <?php if (!empty($doc['terms'])): ?><div><span class="k"><?= e(t('print.terms')) ?>:</span> <?= e($doc['terms']) ?></div><?php endif; ?>
                    <?php if (!empty($doc['notes'])): ?><div><span class="k"><?= e(t('print.notes')) ?>:</span> <?= e($doc['notes']) ?></div><?php endif; ?>
                </div>
            </td>
            <td class="col-totals">
                <table class="doc-totals" dir="<?= e($dir) ?>">
                    <tr><td><?= e(t('print.subtotal')) ?></td><td class="v"><?= e($moneyWithUnit($totals['subtotal'])) ?></td></tr>
                    <tr><td><?= e(t('print.invoice_discount')) ?></td><td class="v"><?= e($moneyWithUnit($totals['discount'])) ?></td></tr>
                    <tr><td><?= e(t('print.vat')) ?></td><td class="v"><?= e($moneyWithUnit($totals['tax'])) ?></td></tr>
                    <?php if (($totals['unrounded'] ?? null) !== null): ?>
                        <tr><td><?= e(t('finance.final_rounding.before')) ?></td><td class="v"><?= e($moneyWithUnit($totals['unrounded'])) ?></td></tr>
                        <tr><td><?= e(t('finance.final_rounding.adjustment')) ?></td><td class="v"><?= e($moneyWithUnit($totals['rounding_adjustment'])) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($totals['credited'] !== null): ?>
                        <tr><td><?= e(t('print.credited')) ?></td><td class="v"><?= e($moneyWithUnit($totals['credited'])) ?></td></tr>
                    <?php endif; ?>
                    <tr class="grand"><td><?= e(t($isInvoice && $showPayable ? 'print.payable' : 'print.grand_total')) ?></td><td class="v"><?= e($moneyWithUnit($headline)) ?></td></tr>
                    <?php if ($isInvoice): ?>
                        <tr><td><?= e(t('print.paid')) ?></td><td class="v"><?= e($moneyWithUnit($totals['paid'])) ?></td></tr>
                        <tr><td><?= e(t('print.remaining')) ?></td><td class="v"><?= e($moneyWithUnit($totals['remaining'])) ?></td></tr>
                    <?php endif; ?>
                </table>
            </td>
        </tr>
    </table>

    <table class="doc-sign" dir="<?= e($dir) ?>">
        <tr>
            <td><?= e(t('print.seller_sign')) ?></td>
            <td class="gap"></td>
            <td><?= e(t('print.buyer_sign')) ?></td>
        </tr>
    </table>

    <div class="doc-foot"><?= e($isInvoice ? t('print.footer_invoice', ['app' => t('app.name')]) : t('print.footer_proforma')) ?></div>
</div>
