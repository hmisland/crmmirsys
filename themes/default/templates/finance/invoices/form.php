<?php
/** @var array|null $invoice */
/** @var array $items */
/** @var array $contacts */
/** @var array $currencies */
/** @var int|null $defaultCurrencyId */
/** @var array $taxProfiles */
/** @var array $products */
/** @var array $errors */
/** @var array $old */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
$val = fn (string $field, string $default = '') => e($old[$field] ?? $invoice[$field] ?? $default);
$action = $invoice !== null ? '/finance/invoices/' . $invoice['id'] : '/finance/invoices';
$rows = $old['items'] ?? $items;
if ($rows === []) {
    $rows = [['description' => '', 'quantity' => '1', 'unit_price' => '0', 'discount_type' => 'none', 'discount_value' => '0', 'product_id' => null]];
}
?>
<section class="card card--form">
    <h1><?= $invoice !== null ? e(t('invoices.edit')) : e(t('invoices.new')) ?></h1>

    <form method="post" action="<?= e($action) ?>">
        <?= Csrf::field() ?>

        <label for="contact_id"><?= e(t('common.contact')) ?></label>
        <select id="contact_id" name="contact_id">
            <option value=""><?= e(t('common.none')) ?></option>
            <?php foreach ($contacts as $c): ?>
                <option value="<?= e($c['id']) ?>" <?= (string) ($old['contact_id'] ?? $invoice['contact_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>>
                    <?= e(trim($c['first_name'] . ' ' . ($c['last_name'] ?? ''))) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="issue_date"><?= e(t('proformas.issue_date')) ?></label>
        <input type="text" class="crm-datepicker" id="issue_date" name="issue_date" value="<?= $val('issue_date', date('Y-m-d')) ?>" required>
        <?php if ($fieldError('issue_date')): ?><div class="field-error"><?= e($fieldError('issue_date')) ?></div><?php endif; ?>

        <label for="due_date"><?= e(t('invoices.due_date')) ?></label>
        <input type="text" class="crm-datepicker" id="due_date" name="due_date" value="<?= $val('due_date') ?>">

        <label for="currency_id"><?= e(t('wallet.currency')) ?></label>
        <select id="currency_id" name="currency_id" required>
            <option value="" <?= ($old['currency_id'] ?? $invoice['currency_id'] ?? $defaultCurrencyId ?? '') === '' ? 'selected' : '' ?>><?= e(t('finance.choose_currency')) ?></option>
            <?php foreach ($currencies as $c): ?>
                <option value="<?= e($c['id']) ?>" <?= (string) ($old['currency_id'] ?? $invoice['currency_id'] ?? $defaultCurrencyId ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?> (<?= e($c['code']) ?>)</option>
            <?php endforeach; ?>
        </select>
        <?php if ($fieldError('currency_id')): ?><div class="field-error"><?= e($fieldError('currency_id')) ?></div><?php endif; ?>

        <label for="pricing_mode"><?= e(t('finance.pricing_mode')) ?></label>
        <select id="pricing_mode" name="pricing_mode">
            <option value="tax_exclusive" <?= ($old['pricing_mode'] ?? $invoice['pricing_mode'] ?? 'tax_exclusive') === 'tax_exclusive' ? 'selected' : '' ?>><?= e(t('finance.pricing_mode.tax_exclusive')) ?></option>
            <option value="tax_inclusive" <?= ($old['pricing_mode'] ?? $invoice['pricing_mode'] ?? '') === 'tax_inclusive' ? 'selected' : '' ?>><?= e(t('finance.pricing_mode.tax_inclusive')) ?></option>
        </select>

        <label for="tax_profile_id"><?= e(t('finance.tax_profile')) ?></label>
        <select id="tax_profile_id" name="tax_profile_id">
            <option value=""><?= e(t('products.use_org_default')) ?></option>
            <?php foreach ($taxProfiles as $tp): ?>
                <option value="<?= e($tp['id']) ?>" <?= (string) ($old['tax_profile_id'] ?? $invoice['tax_profile_id'] ?? '') === (string) $tp['id'] ? 'selected' : '' ?>><?= e($tp['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <h3 style="margin-top:1.5rem"><?= e(t('proformas.line_items')) ?></h3>
        <div id="item-rows">
            <?php foreach ($rows as $key => $row): ?>
                <div class="repeat-row invoice-item-row" style="flex-wrap:wrap">
                    <select name="items[<?= e($key) ?>][product_id]" class="invoice-product-select">
                        <option value=""><?= e(t('proformas.free_text_line')) ?></option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?= e($p['id']) ?>" data-name="<?= e($p['name']) ?>" data-price="<?= e($p['price']) ?>"
                                <?= (string) ($row['product_id'] ?? '') === (string) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="items[<?= e($key) ?>][description]" value="<?= e($row['description'] ?? '') ?>" placeholder="<?= e(t('contacts.description')) ?>" class="invoice-description" style="flex:2;min-width:160px">
                    <input type="number" name="items[<?= e($key) ?>][quantity]" value="<?= e($row['quantity'] ?? '1') ?>" step="0.01" min="0" placeholder="<?= e(t('proformas.qty')) ?>" style="width:90px">
                    <input type="number" name="items[<?= e($key) ?>][unit_price]" value="<?= e($row['unit_price'] ?? '0') ?>" step="0.01" min="0" placeholder="<?= e(t('proformas.unit_price')) ?>" class="invoice-unit-price" style="width:120px">
                    <select name="items[<?= e($key) ?>][discount_type]" style="width:110px">
                        <option value="none" <?= ($row['discount_type'] ?? 'none') === 'none' ? 'selected' : '' ?>><?= e(t('proformas.discount_none')) ?></option>
                        <option value="percentage" <?= ($row['discount_type'] ?? '') === 'percentage' ? 'selected' : '' ?>><?= e(t('proformas.discount_percent')) ?></option>
                        <option value="fixed" <?= ($row['discount_type'] ?? '') === 'fixed' ? 'selected' : '' ?>><?= e(t('proformas.discount_fixed')) ?></option>
                    </select>
                    <input type="number" name="items[<?= e($key) ?>][discount_value]" value="<?= e($row['discount_value'] ?? '0') ?>" step="0.01" min="0" style="width:90px">
                    <button type="button" class="link-button remove-row"><?= e(t('common.remove')) ?></button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn" id="add-item"><?= e(t('proformas.add_line')) ?></button>
        <?php if ($fieldError('items')): ?><div class="field-error"><?= e($fieldError('items')) ?></div><?php endif; ?>

        <label for="invoice_discount"><?= e(t('proformas.invoice_discount')) ?></label>
        <input type="number" id="invoice_discount" name="invoice_discount" step="0.01" min="0" value="<?= $val('invoice_discount', '0') ?>">

        <label for="terms"><?= e(t('proformas.terms')) ?></label>
        <textarea id="terms" name="terms" rows="3" style="width:100%;padding:0.55rem;border:1px solid var(--color-border);border-radius:6px;font-family:inherit;"><?= $val('terms') ?></textarea>

        <label for="notes"><?= e(t('common.notes')) ?></label>
        <textarea id="notes" name="notes" rows="3" style="width:100%;padding:0.55rem;border:1px solid var(--color-border);border-radius:6px;font-family:inherit;"><?= $val('notes') ?></textarea>

        <p style="margin-top:1rem"><button type="submit" class="btn btn--primary"><?= e(t('common.save')) ?></button></p>
    </form>
</section>

<template id="item-row-template">
    <div class="repeat-row invoice-item-row" style="flex-wrap:wrap">
        <select name="items[__INDEX__][product_id]" class="invoice-product-select">
            <option value=""><?= e(t('proformas.free_text_line')) ?></option>
            <?php foreach ($products as $p): ?>
                <option value="<?= e($p['id']) ?>" data-name="<?= e($p['name']) ?>" data-price="<?= e($p['price']) ?>"><?= e($p['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="items[__INDEX__][description]" placeholder="<?= e(t('contacts.description')) ?>" class="invoice-description" style="flex:2;min-width:160px">
        <input type="number" name="items[__INDEX__][quantity]" value="1" step="0.01" min="0" placeholder="<?= e(t('proformas.qty')) ?>" style="width:90px">
        <input type="number" name="items[__INDEX__][unit_price]" value="0" step="0.01" min="0" placeholder="<?= e(t('proformas.unit_price')) ?>" class="invoice-unit-price" style="width:120px">
        <select name="items[__INDEX__][discount_type]" style="width:110px">
            <option value="none"><?= e(t('proformas.discount_none')) ?></option>
            <option value="percentage"><?= e(t('proformas.discount_percent')) ?></option>
            <option value="fixed"><?= e(t('proformas.discount_fixed')) ?></option>
        </select>
        <input type="number" name="items[__INDEX__][discount_value]" value="0" step="0.01" min="0" style="width:90px">
        <button type="button" class="link-button remove-row"><?= e(t('common.remove')) ?></button>
    </div>
</template>

<script>
(function () {
    var counter = <?= (int) count($rows) + 1000 ?>;

    document.getElementById('add-item').addEventListener('click', function () {
        var template = document.getElementById('item-row-template');
        var html = template.innerHTML.split('__INDEX__').join(String(counter++));
        var wrapper = document.createElement('div');
        wrapper.innerHTML = html.trim();
        document.getElementById('item-rows').appendChild(wrapper.firstElementChild);
    });

    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-row')) {
            var rows = document.querySelectorAll('.invoice-item-row');
            if (rows.length > 1) {
                e.target.closest('.invoice-item-row').remove();
            }
        }
    });

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('invoice-product-select')) {
            var row = e.target.closest('.invoice-item-row');
            var option = e.target.options[e.target.selectedIndex];
            var descriptionInput = row.querySelector('.invoice-description');
            var priceInput = row.querySelector('.invoice-unit-price');
            if (option.value !== '') {
                descriptionInput.value = option.dataset.name || '';
                priceInput.value = option.dataset.price || '0';
            }
        }
    });
})();
</script>
