<?php
/** @var array|null $proforma */
/** @var array $items */
/** @var array $contacts */
/** @var array $currencies */
/** @var int|null $defaultCurrencyId */
/** @var array $taxProfiles */
/** @var array $products */
/** @var array $errors */
/** @var array $old */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_today;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
$val = fn (string $field, string $default = '') => e($old[$field] ?? $proforma[$field] ?? $default);
$action = $proforma !== null ? '/finance/proformas/' . $proforma['id'] : '/finance/proformas';
$rows = $old['items'] ?? $items;
if ($rows === []) {
    $rows = [['description' => '', 'quantity' => '1', 'unit_price' => '0', 'discount_type' => 'none', 'discount_value' => '0', 'product_id' => null]];
}
?>
<section class="card card--form">
    <h1><?= $proforma !== null ? e(t('proformas.edit')) : e(t('proformas.new')) ?></h1>

    <form method="post" action="<?= e($action) ?>">
        <?= Csrf::field() ?>
        <?php include __DIR__ . '/../_contract_source.php'; ?>

        <label for="contact_id"><?= e(t('common.contact')) ?></label>
        <select id="contact_id" name="contact_id">
            <option value=""><?= e(t('common.none')) ?></option>
            <?php foreach ($contacts as $c): ?>
                <option value="<?= e($c['id']) ?>" <?= (string) ($old['contact_id'] ?? $proforma['contact_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>>
                    <?= e(trim($c['first_name'] . ' ' . ($c['last_name'] ?? ''))) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($fieldError('contact_id')): ?><div class="field-error"><?= e($fieldError('contact_id')) ?></div><?php endif; ?>

        <label for="custom_number"><?= e(t('finance.custom_number')) ?></label>
        <input type="text" id="custom_number" name="custom_number" value="<?= $val('custom_number') ?>" maxlength="50" dir="ltr" class="field-md">
        <div class="field-hint"><?= e(t('finance.custom_number_hint')) ?></div>

        <label for="issue_date"><?= e(t('proformas.issue_date')) ?></label>
        <input type="text" class="crm-datepicker" id="issue_date" name="issue_date" value="<?= $val('issue_date', local_today()) ?>" required>
        <?php if ($fieldError('issue_date')): ?><div class="field-error"><?= e($fieldError('issue_date')) ?></div><?php endif; ?>

        <label for="expiry_date"><?= e(t('proformas.expiry_date')) ?></label>
        <input type="text" class="crm-datepicker" id="expiry_date" name="expiry_date" value="<?= $val('expiry_date') ?>">

        <label for="currency_id"><?= e(t('wallet.currency')) ?></label>
        <select id="currency_id" name="currency_id" required>
            <option value="" <?= ($old['currency_id'] ?? $proforma['currency_id'] ?? $defaultCurrencyId ?? '') === '' ? 'selected' : '' ?>><?= e(t('finance.choose_currency')) ?></option>
            <?php foreach ($currencies as $c): ?>
                <option value="<?= e($c['id']) ?>" <?= (string) ($old['currency_id'] ?? $proforma['currency_id'] ?? $defaultCurrencyId ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?> (<?= e($c['code']) ?>)</option>
            <?php endforeach; ?>
        </select>
        <?php if ($fieldError('currency_id')): ?><div class="field-error"><?= e($fieldError('currency_id')) ?></div><?php endif; ?>

        <label for="pricing_mode"><?= e(t('finance.pricing_mode')) ?></label>
        <select id="pricing_mode" name="pricing_mode">
            <option value="tax_exclusive" <?= ($old['pricing_mode'] ?? $proforma['pricing_mode'] ?? 'tax_exclusive') === 'tax_exclusive' ? 'selected' : '' ?>><?= e(t('finance.pricing_mode.tax_exclusive')) ?></option>
            <option value="tax_inclusive" <?= ($old['pricing_mode'] ?? $proforma['pricing_mode'] ?? '') === 'tax_inclusive' ? 'selected' : '' ?>><?= e(t('finance.pricing_mode.tax_inclusive')) ?></option>
        </select>

        <label for="tax_profile_id"><?= e(t('finance.tax_profile')) ?></label>
        <select id="tax_profile_id" name="tax_profile_id">
            <option value=""><?= e(t('products.use_org_default')) ?></option>
            <?php foreach ($taxProfiles as $tp): ?>
                <option value="<?= e($tp['id']) ?>" <?= (string) ($old['tax_profile_id'] ?? $proforma['tax_profile_id'] ?? '') === (string) $tp['id'] ? 'selected' : '' ?>><?= e($tp['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <?php include __DIR__ . '/../_line-items.php'; ?>

        <label for="invoice_discount"><?= e(t('proformas.invoice_discount')) ?></label>
        <input type="number" id="invoice_discount" name="invoice_discount" step="0.01" min="0" value="<?= $val('invoice_discount', '0') ?>">
        <div class="field-hint"><?= e(t('proformas.invoice_discount_hint')) ?></div>

        <label for="terms"><?= e(t('proformas.terms')) ?></label>
        <textarea id="terms" name="terms" rows="3"><?= $val('terms') ?></textarea>

        <label for="notes"><?= e(t('common.notes')) ?></label>
        <textarea id="notes" name="notes" rows="3"><?= $val('notes') ?></textarea>

        <p style="margin-top:1rem"><button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button></p>
    </form>
</section>


