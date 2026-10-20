<?php
/**
 * Rows of an invoice / proforma (show pages): row number, code, name,
 * quantity, unit price, row discount, and — when the document is taxed
 * per row from its products — VAT % and VAT of each row.
 *
 * @var list<array> $items
 * @var ?string $rowsCurrency
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\money;
use function App\Infrastructure\Theme\t;

$perRowTax = array_filter($items, fn (array $i) => ($i['tax_rate'] ?? null) !== null) !== [];
$columns = $perRowTax ? 9 : 7;
?>
<div class="table-responsive">
<table class="table">
    <thead><tr>
        <th>#</th>
        <th><?= e(t('finance.item_code')) ?></th>
        <th><?= e(t('contacts.description')) ?></th>
        <th><?= e(t('proformas.qty')) ?></th>
        <th><?= e(t('proformas.unit_price')) ?></th>
        <th><?= e(t('finance.row_discount')) ?></th>
        <?php if ($perRowTax): ?>
            <th><?= e(t('finance.row_tax_rate')) ?></th>
            <th><?= e(t('finance.row_tax')) ?></th>
        <?php endif; ?>
        <th><?= e(t('proformas.line_total')) ?></th>
    </tr></thead>
    <tbody>
    <?php if ($items === []): ?>
        <tr><td colspan="<?= e((string) $columns) ?>" class="muted">—</td></tr>
    <?php endif; ?>
    <?php foreach (array_values($items) as $n => $item): ?>
        <?php
        $code = (string) ($item['item_code'] ?? $item['product_sku'] ?? '');
        $name = (string) ($item['item_name'] ?? $item['product_name'] ?? '');
        $gross = bcmul((string) $item['quantity'], (string) $item['unit_price'], 18);
        $rowDiscount = bcsub($gross, (string) $item['line_total'], 18);
        ?>
        <tr>
            <td><?= e((string) ($n + 1)) ?></td>
            <td><span class="ltr"><?= e($code) ?></span></td>
            <td><?= e($name !== '' ? $name : (string) $item['description']) ?><?php if ($name !== '' && $item['description'] !== $name): ?><div class="muted text-sm"><?= e($item['description']) ?></div><?php endif; ?></td>
            <td><?= e(rtrim(rtrim((string) $item['quantity'], '0'), '.')) ?></td>
            <td><?= e(money($item['unit_price'], $rowsCurrency)) ?></td>
            <td><?= e(money(bccomp($rowDiscount, '0', 18) > 0 ? $rowDiscount : '0', $rowsCurrency)) ?></td>
            <?php if ($perRowTax): ?>
                <td><?= $item['tax_rate'] !== null ? e(rtrim(rtrim((string) $item['tax_rate'], '0'), '.') ?: '0') . '٪' : '' ?></td>
                <td><?= $item['tax_amount'] !== null ? e(money($item['tax_amount'], $rowsCurrency)) : '' ?></td>
            <?php endif; ?>
            <td><?= e(money($item['line_total'], $rowsCurrency)) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
