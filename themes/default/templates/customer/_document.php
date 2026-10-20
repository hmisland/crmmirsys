<?php
/**
 * Customer Portal: one invoice or proforma (shared by invoice.php and
 * proforma.php) — header facts, lines and totals. Customer-facing values
 * only: no owner, notes, terms, tax profile or internal ids.
 *
 * @var array $doc
 * @var string $kind 'invoice' | 'proforma'
 * @var string $number
 * @var string $lang
 */

use App\Infrastructure\Localization\LocaleFormatter;

use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\money;
use function App\Infrastructure\Theme\t;

$fmt = new LocaleFormatter($lang);
$code = (string) ($doc['currency_code'] ?? '');
$amount = fn ($v): string => money((string) $v, $code, $fmt) . ($code !== '' ? ' ' . $code : '');
$isInvoice = $kind === 'invoice';
// 2.5000 → "2.5", 3.0000 → "3" (stored with four decimals).
$quantity = function (string $q) use ($fmt): string {
    $q = str_contains($q, '.') ? rtrim(rtrim($q, '0'), '.') : $q;

    return $fmt->number($q === '' ? '0' : $q, str_contains($q, '.') ? strlen(substr($q, strpos($q, '.') + 1)) : 0);
};
?>
<div class="page-head">
    <div>
        <h1><?= e(t('customer_portal.' . $kind . 's.one')) ?> <span class="ltr"><?= e($number) ?></span></h1>
    </div>
    <div class="actions">
        <a href="/customer/<?= e($kind) ?>s" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('customer_portal.' . $kind . 's.back')) ?></a>
    </div>
</div>

<section class="card">
    <dl class="customer-facts">
        <dt><?= e(t('customer_portal.doc.status')) ?></dt>
        <dd><?= badge(t('customer_portal.' . $kind . '_status.' . $doc['status']), (string) $doc['status']) ?></dd>
        <dt><?= e(t('customer_portal.doc.date')) ?></dt>
        <dd><?= e($fmt->date((string) $doc['issue_date'])) ?></dd>
        <?php if ($isInvoice && !empty($doc['due_date'])): ?>
            <dt><?= e(t('customer_portal.doc.due_date')) ?></dt>
            <dd><?= e($fmt->date((string) $doc['due_date'])) ?></dd>
        <?php elseif (!$isInvoice && !empty($doc['expiry_date'])): ?>
            <dt><?= e(t('customer_portal.doc.expiry_date')) ?></dt>
            <dd><?= e($fmt->date((string) $doc['expiry_date'])) ?></dd>
        <?php endif; ?>
    </dl>
</section>

<section class="card">
    <h2><?= e(t('customer_portal.doc.lines')) ?></h2>
    <div class="table-responsive">
    <table class="table">
        <thead><tr>
            <th><?= e(t('customer_portal.doc.description')) ?></th>
            <th class="num"><?= e(t('customer_portal.doc.quantity')) ?></th>
            <th class="num"><?= e(t('customer_portal.doc.unit_price')) ?></th>
            <th class="num"><?= e(t('customer_portal.doc.line_total')) ?></th>
        </tr></thead>
        <tbody>
        <?php if ($doc['items'] === []): ?>
            <tr><td colspan="4" class="muted"><?= e(t('customer_portal.doc.no_lines')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($doc['items'] as $item): ?>
            <tr>
                <td><?= e($item['description']) ?></td>
                <td class="num"><?= e($quantity((string) $item['quantity'])) ?></td>
                <td class="num"><?= e(money((string) $item['unit_price'], $code, $fmt)) ?></td>
                <td class="num"><?= e(money((string) $item['line_total'], $code, $fmt)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <dl class="customer-facts">
        <dt><?= e(t('customer_portal.doc.subtotal')) ?></dt><dd><?= e($amount($doc['subtotal'])) ?></dd>
        <?php if (bccomp((string) $doc['invoice_discount'], '0', 18) > 0): ?>
            <dt><?= e(t('customer_portal.doc.discount')) ?></dt><dd><?= e($amount($doc['invoice_discount'])) ?></dd>
        <?php endif; ?>
        <dt><?= e(t('customer_portal.doc.tax')) ?></dt><dd><?= e($amount($doc['tax_total'])) ?></dd>
        <dt><strong><?= e(t('customer_portal.doc.total')) ?></strong></dt><dd><strong><?= e($amount($doc['grand_total'])) ?></strong></dd>
        <?php if ($isInvoice && $doc['status'] === 'issued'): ?>
            <?php
            $credited = (string) $doc['credited_total'];
            $outstanding = bcsub(bcsub((string) $doc['grand_total'], $credited, 18), (string) $doc['paid_total'], 18);
            ?>
            <dt><?= e(t('customer_portal.doc.paid')) ?></dt><dd><?= e($amount($doc['paid_total'])) ?></dd>
            <?php if (bccomp($credited, '0', 18) > 0): ?>
                <dt><?= e(t('customer_portal.doc.credited')) ?></dt><dd><?= e($amount($credited)) ?></dd>
            <?php endif; ?>
            <dt><?= e(t('customer_portal.doc.outstanding')) ?></dt><dd><?= e($amount(bccomp($outstanding, '0', 18) > 0 ? $outstanding : '0')) ?></dd>
        <?php endif; ?>
    </dl>
</section>
