<?php
/**
 * Line items for the proforma and invoice forms. Each row: product code,
 * product name and a free description (شرح), then quantity, unit price
 * and discount. Code and name are search-as-you-type fields
 * (data-product-lookup → /finance/products/lookup); picking a product
 * fills code, name, description, price and the hidden product_id. Rows
 * are added/removed by app.js ([data-line-items]); there is no product
 * dropdown, so it works with any number of products.
 *
 * @var array $rows existing rows (posted or from the database)
 * @var callable $fieldError
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$lineRow = function (string $key, array $row): string {
    $code = (string) ($row['item_code'] ?? $row['product_sku'] ?? '');
    $name = (string) ($row['item_name'] ?? $row['product_name'] ?? '');
    $description = (string) ($row['description'] ?? '');
    // Rows saved before code/name existed: the description was the name.
    if ($name === '' && $code === '' && $description !== '') {
        $name = $description;
        $description = '';
    } elseif ($description === $name) {
        $description = '';
    }
    $discountType = (string) ($row['discount_type'] ?? 'none');
    $n = fn (string $field) => 'items[' . $key . '][' . $field . ']';

    ob_start(); ?>
    <div class="line-item" data-line-item>
        <input type="hidden" name="<?= e($n('product_id')) ?>" value="<?= e($row['product_id'] ?? '') ?>" data-li="product_id">
        <div class="line-item__main">
            <label class="li-field li-field--code">
                <span><?= e(t('finance.item_code')) ?></span>
                <input type="text" name="<?= e($n('item_code')) ?>" value="<?= e($code) ?>" data-li="item_code" data-product-lookup="sku" autocomplete="off" dir="ltr">
            </label>
            <label class="li-field li-field--name">
                <span><?= e(t('finance.item_name')) ?></span>
                <input type="text" name="<?= e($n('item_name')) ?>" value="<?= e($name) ?>" data-li="item_name" data-product-lookup="name" autocomplete="off">
            </label>
            <label class="li-field li-field--desc">
                <span><?= e(t('finance.item_description')) ?></span>
                <input type="text" name="<?= e($n('description')) ?>" value="<?= e($description) ?>" data-li="description">
            </label>
        </div>
        <div class="line-item__amounts">
            <label class="li-field">
                <span><?= e(t('proformas.qty')) ?></span>
                <input type="number" name="<?= e($n('quantity')) ?>" value="<?= e($row['quantity'] ?? '1') ?>" step="0.001" min="0" data-li="quantity">
            </label>
            <label class="li-field">
                <span><?= e(t('proformas.unit_price')) ?></span>
                <input type="number" name="<?= e($n('unit_price')) ?>" value="<?= e($row['unit_price'] ?? '0') ?>" step="0.001" min="0" data-li="unit_price">
            </label>
            <label class="li-field">
                <span><?= e(t('finance.item_discount')) ?></span>
                <select name="<?= e($n('discount_type')) ?>">
                    <option value="none" <?= $discountType === 'none' ? 'selected' : '' ?>><?= e(t('proformas.discount_none')) ?></option>
                    <option value="percentage" <?= $discountType === 'percentage' ? 'selected' : '' ?>><?= e(t('proformas.discount_percent')) ?></option>
                    <option value="fixed" <?= $discountType === 'fixed' ? 'selected' : '' ?>><?= e(t('proformas.discount_fixed')) ?></option>
                </select>
            </label>
            <label class="li-field">
                <span><?= e(t('finance.item_discount_value')) ?></span>
                <input type="number" name="<?= e($n('discount_value')) ?>" value="<?= e($row['discount_value'] ?? '0') ?>" step="0.001" min="0">
            </label>
            <button type="button" class="link-button link-button--danger line-item__remove" data-li-remove><?= icon('trash') ?><?= e(t('common.remove')) ?></button>
        </div>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<div class="line-items" data-line-items data-lookup-url="/finance/products/lookup" data-empty-text="<?= e(t('finance.no_product_found')) ?>">
    <h3><?= e(t('proformas.line_items')) ?></h3>
    <p class="field-hint"><?= e(t('finance.item_search_hint')) ?></p>
    <div class="line-items__rows" data-li-rows>
        <?php foreach (array_values($rows) as $i => $row): ?>
            <?= $lineRow((string) $i, (array) $row) ?>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn" data-li-add><?= icon('plus') ?><?= e(t('proformas.add_line')) ?></button>
    <?php if ($fieldError('items')): ?><div class="field-error"><?= e($fieldError('items')) ?></div><?php endif; ?>
    <template data-li-template><?= $lineRow('__INDEX__', ['quantity' => '1', 'unit_price' => '0', 'discount_value' => '0']) ?></template>
</div>
