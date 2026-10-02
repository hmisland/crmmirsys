<?php
/** @var array|null $product */
/** @var array $currencies */
/** @var array $taxProfiles */
/** @var array $errors */
/** @var array $old */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
$val = fn (string $field, string $default = '') => e($old[$field] ?? $product[$field] ?? $default);
$action = $product !== null ? '/finance/products/' . $product['id'] : '/finance/products';
$isActive = $old !== [] ? isset($old['is_active']) : ($product['is_active'] ?? true);
?>
<section class="card card--form">
    <h1><?= $product !== null ? e(t('products.edit')) : e(t('products.new')) ?></h1>

    <form method="post" action="<?= e($action) ?>">
        <?= Csrf::field() ?>

        <label for="name"><?= e(t('common.name')) ?></label>
        <input type="text" id="name" name="name" value="<?= $val('name') ?>" required autofocus>
        <?php if ($fieldError('name')): ?><div class="field-error"><?= e($fieldError('name')) ?></div><?php endif; ?>

        <label for="sku"><?= e(t('products.sku')) ?></label>
        <input type="text" id="sku" name="sku" value="<?= $val('sku') ?>">

        <label for="description"><?= e(t('contacts.description')) ?></label>
        <textarea id="description" name="description" rows="3"><?= $val('description') ?></textarea>

        <label for="unit"><?= e(t('products.unit')) ?></label>
        <input type="text" id="unit" name="unit" value="<?= $val('unit') ?>" placeholder="<?= e(t('products.unit_placeholder')) ?>">

        <label for="price"><?= e(t('products.price')) ?></label>
        <input type="number" id="price" name="price" step="0.01" min="0" value="<?= $val('price', '0') ?>" required>
        <?php if ($fieldError('price')): ?><div class="field-error"><?= e($fieldError('price')) ?></div><?php endif; ?>

        <label for="currency_id"><?= e(t('wallet.currency')) ?></label>
        <select id="currency_id" name="currency_id" required>
            <?php foreach ($currencies as $c): ?>
                <option value="<?= e($c['id']) ?>" <?= (string) ($old['currency_id'] ?? $product['currency_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?> (<?= e($c['code']) ?>)</option>
            <?php endforeach; ?>
        </select>
        <?php if ($fieldError('currency_id')): ?><div class="field-error"><?= e($fieldError('currency_id')) ?></div><?php endif; ?>

        <label for="tax_profile_id"><?= e(t('finance.tax_profile')) ?></label>
        <select id="tax_profile_id" name="tax_profile_id">
            <option value=""><?= e(t('products.use_org_default')) ?></option>
            <?php foreach ($taxProfiles as $tp): ?>
                <option value="<?= e($tp['id']) ?>" <?= (string) ($old['tax_profile_id'] ?? $product['tax_profile_id'] ?? '') === (string) $tp['id'] ? 'selected' : '' ?>><?= e($tp['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="field-hint"><?= e(t('products.tax_profile_hint')) ?></div>

        <label class="check check--spaced">
            <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>> <?= e(t('products.is_active')) ?>
        </label>

        <p style="margin-top:1rem">
            <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button>
        </p>
    </form>

    <?php if ($product !== null): ?>
        <form method="post" action="/finance/products/<?= e($product['id']) ?>/delete" class="inline-form">
            <?= Csrf::field() ?>
            <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
        </form>
    <?php endif; ?>
</section>
