<?php
/** @var array $profile */
/** @var array $countries */
/** @var array $jurisdictions */
/** @var array $taxProfiles */
/** @var array $currencies */
/** @var array $errors */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
?>
<section class="card card--form">
    <h1><?= e(t('finance.settings_title')) ?></h1>
    <p class="muted"><?= e(t('finance.settings_hint')) ?></p>

    <form method="post" action="/finance/settings">
        <?= Csrf::field() ?>

        <label><?= e(t('finance.entity_type')) ?></label>
        <label class="check check--spaced">
            <input type="radio" name="entity_type" value="legal" id="entity_type_legal" <?= ($profile['entity_type'] ?? 'legal') === 'legal' ? 'checked' : '' ?>> <?= e(t('finance.entity_type.legal')) ?>
        </label>
        <label class="check">
            <input type="radio" name="entity_type" value="natural" id="entity_type_natural" <?= ($profile['entity_type'] ?? '') === 'natural' ? 'checked' : '' ?>> <?= e(t('finance.entity_type.natural')) ?>
        </label>

        <div id="legal-fields" <?= ($profile['entity_type'] ?? 'legal') !== 'legal' ? 'hidden' : '' ?>>
            <label for="legal_name"><?= e(t('finance.legal_name')) ?></label>
            <input type="text" id="legal_name" name="legal_name" value="<?= e($profile['legal_name'] ?? '') ?>">
            <?php if ($fieldError('legal_name')): ?><div class="field-error"><?= e($fieldError('legal_name')) ?></div><?php endif; ?>
        </div>

        <div id="natural-fields" <?= ($profile['entity_type'] ?? 'legal') !== 'natural' ? 'hidden' : '' ?>>
            <label for="first_name"><?= e(t('auth.first_name')) ?></label>
            <input type="text" id="first_name" name="first_name" value="<?= e($profile['first_name'] ?? '') ?>">
            <?php if ($fieldError('first_name')): ?><div class="field-error"><?= e($fieldError('first_name')) ?></div><?php endif; ?>

            <label for="last_name"><?= e(t('auth.last_name')) ?></label>
            <input type="text" id="last_name" name="last_name" value="<?= e($profile['last_name'] ?? '') ?>">
        </div>

        <label for="tax_identification_number"><?= e(t('finance.tax_id')) ?></label>
        <input type="text" id="tax_identification_number" name="tax_identification_number" value="<?= e($profile['tax_identification_number'] ?? '') ?>">
        <div class="field-hint"><?= e(t('finance.tax_id_hint')) ?></div>

        <label for="country_id"><?= e(t('finance.country')) ?></label>
        <select id="country_id" name="country_id">
            <option value=""><?= e(t('common.none')) ?></option>
            <?php foreach ($countries as $c): ?>
                <option value="<?= e($c['id']) ?>" <?= (string) ($profile['country_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="tax_jurisdiction_id"><?= e(t('finance.tax_jurisdiction')) ?></label>
        <select id="tax_jurisdiction_id" name="tax_jurisdiction_id">
            <option value=""><?= e(t('common.none')) ?></option>
            <?php foreach ($jurisdictions as $j): ?>
                <option value="<?= e($j['id']) ?>" <?= (string) ($profile['tax_jurisdiction_id'] ?? '') === (string) $j['id'] ? 'selected' : '' ?>><?= e($j['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="tax_profile_id"><?= e(t('finance.tax_profile')) ?></label>
        <select id="tax_profile_id" name="tax_profile_id">
            <option value=""><?= e(t('common.none')) ?></option>
            <?php foreach ($taxProfiles as $tp): ?>
                <option value="<?= e($tp['id']) ?>" <?= (string) ($profile['tax_profile_id'] ?? '') === (string) $tp['id'] ? 'selected' : '' ?>><?= e($tp['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="field-hint"><?= e(t('finance.tax_profile_hint')) ?></div>

        <label for="base_currency_id"><?= e(t('finance.base_currency')) ?></label>
        <select id="base_currency_id" name="base_currency_id">
            <option value=""><?= e(t('common.none')) ?></option>
            <?php foreach ($currencies as $c): ?>
                <option value="<?= e($c['id']) ?>" <?= (string) ($profile['base_currency_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?> (<?= e($c['code']) ?>)</option>
            <?php endforeach; ?>
        </select>

        <label for="invoice_prefix"><?= e(t('finance.invoice_prefix')) ?></label>
        <input type="text" id="invoice_prefix" name="invoice_prefix" value="<?= e($profile['invoice_prefix'] ?? 'INV-') ?>" required>
        <?php if ($fieldError('invoice_prefix')): ?><div class="field-error"><?= e($fieldError('invoice_prefix')) ?></div><?php endif; ?>

        <label for="pricing_mode"><?= e(t('finance.pricing_mode')) ?></label>
        <select id="pricing_mode" name="pricing_mode">
            <option value="tax_exclusive" <?= ($profile['pricing_mode'] ?? 'tax_exclusive') === 'tax_exclusive' ? 'selected' : '' ?>><?= e(t('finance.pricing_mode.tax_exclusive')) ?></option>
            <option value="tax_inclusive" <?= ($profile['pricing_mode'] ?? '') === 'tax_inclusive' ? 'selected' : '' ?>><?= e(t('finance.pricing_mode.tax_inclusive')) ?></option>
        </select>

        <label class="check check--block">
            <input type="checkbox" name="invoice_final_rounding" value="1"<?= (int) ($profile['invoice_final_rounding'] ?? 0) === 1 ? ' checked' : '' ?>>
            <span><?= e(t('finance.final_rounding.setting')) ?><br><span class="muted"><?= e(t('finance.final_rounding.setting_hint')) ?></span></span>
        </label>

        <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('profile.save_changes')) ?></button>
    </form>
</section>

<script>
(function () {
    var legalRadio = document.getElementById('entity_type_legal');
    var naturalRadio = document.getElementById('entity_type_natural');
    var legalFields = document.getElementById('legal-fields');
    var naturalFields = document.getElementById('natural-fields');

    function sync() {
        legalFields.hidden = !legalRadio.checked;
        naturalFields.hidden = !naturalRadio.checked;
    }

    legalRadio.addEventListener('change', sync);
    naturalRadio.addEventListener('change', sync);
})();
</script>
