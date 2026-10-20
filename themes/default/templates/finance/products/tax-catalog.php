<?php
/**
 * Products → new product from the tax product ID: "my products" (products
 * with a tax ID), "frequently used" (green star; "+" opens the product form
 * prefilled), and a live search in the national list (star → frequently
 * used). Behaviour in assets/js/tax-catalog.js; works on the JSON routes
 * /finance/products/tax-catalog/*.
 *
 * @var list<array> $myProducts
 * @var list<array> $favorites
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;

$labels = [
    'star_add' => t('products.tax_catalog.star_add'),
    'star_remove' => t('products.tax_catalog.star_remove'),
    'add_product' => t('products.tax_catalog.add_product'),
    'vat' => t('products.tax_catalog.vat'),
    'no_results' => t('products.tax_catalog.no_results'),
    'min_chars' => t('products.tax_catalog.min_chars'),
    'error' => t('products.tax_catalog.error'),
];
?>
<div class="page-head">
    <div>
        <h1><?= e(t('products.tax_catalog.button')) ?></h1>
        <p><?= e(t('products.tax_catalog.subtitle')) ?></p>
    </div>
    <div class="actions">
        <a href="/finance/products" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('products.tax_catalog.back')) ?></a>
    </div>
</div>

<div data-tax-catalog
     data-search-url="/finance/products/tax-catalog/search"
     data-add-url="/finance/products/tax-catalog/favorites"
     data-remove-url="/finance/products/tax-catalog/favorites/remove"
     data-create-url="/finance/products/create?tax_code="
     data-csrf="<?= e(Csrf::token()) ?>"
     data-labels="<?= e((string) json_encode($labels, JSON_UNESCAPED_UNICODE)) ?>">

    <section class="card">
        <h2><?= icon('package') ?><?= e(t('products.tax_catalog.my_products')) ?></h2>
        <div class="table-responsive">
        <table class="table">
            <thead><tr><th><?= e(t('common.name')) ?></th><th><?= e(t('products.tax_product_code')) ?></th><th><?= e(t('products.vat_rate')) ?></th></tr></thead>
            <tbody>
            <?php if ($myProducts === []): ?>
                <tr><td colspan="3" class="muted"><?= e(t('products.tax_catalog.my_products_empty')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($myProducts as $p): ?>
                <tr>
                    <td><a href="/finance/products/<?= e($p['id']) ?>/edit"><?= e($p['name']) ?></a></td>
                    <td><span class="ltr"><?= e($p['tax_product_code']) ?></span></td>
                    <td><?= $p['vat_rate'] !== null ? e(rtrim(rtrim((string) $p['vat_rate'], '0'), '.')) . '٪' : '<span class="muted">—</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

    <section class="card">
        <h2><?= icon('star') ?><?= e(t('products.tax_catalog.favorites')) ?></h2>
        <p class="field-hint"><?= e(t('products.tax_catalog.favorites_hint')) ?></p>
        <ul class="tax-catalog-list" data-favorites>
            <?php foreach ($favorites as $f): ?>
                <li data-code="<?= e($f['tax_product_code']) ?>">
                    <button type="button" class="tax-star tax-star--on" data-unstar title="<?= e($labels['star_remove']) ?>" aria-label="<?= e($labels['star_remove']) ?>"><?= icon('star') ?></button>
                    <span class="tax-catalog-list__title"><?= e($f['title']) ?></span>
                    <span class="muted ltr"><?= e($f['tax_product_code']) ?></span>
                    <?php if ($f['vat'] !== null): ?><span class="badge"><?= e($labels['vat']) ?> <?= e((string) $f['vat']) ?>٪</span><?php endif; ?>
                    <?php if (!empty($f['taxable'])): ?><span class="badge"><?= e($f['taxable']) ?></span><?php endif; ?>
                    <a href="/finance/products/create?tax_code=<?= e(rawurlencode($f['tax_product_code'])) ?>" class="btn btn--sm btn--primary"><?= icon('plus') ?><?= e($labels['add_product']) ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="muted" data-favorites-empty<?= $favorites !== [] ? ' hidden' : '' ?>><?= e(t('products.tax_catalog.favorites_empty')) ?></p>
    </section>

    <section class="card">
        <h2><?= icon('search') ?><?= e(t('products.tax_catalog.search')) ?></h2>
        <label for="tax-catalog-q" class="visually-hidden"><?= e(t('products.tax_catalog.search')) ?></label>
        <input type="search" id="tax-catalog-q" data-search autocomplete="off" placeholder="<?= e(t('products.tax_catalog.search_placeholder')) ?>">
        <p class="muted" data-status><?= e($labels['min_chars']) ?></p>
        <ul class="tax-catalog-list" data-results></ul>
    </section>
</div>
<?php $js = dirname(__DIR__, 3) . '/assets/js/tax-catalog.js'; ?>
<script src="/assets/themes/default/js/tax-catalog.js<?= is_file($js) ? '?v=' . filemtime($js) : '' ?>" defer></script>
