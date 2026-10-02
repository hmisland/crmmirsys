<?php
/** @var array $products */
/** @var string $q */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
?>
<section class="card">
    <h1><?= e(t('products.title')) ?></h1>

    <form method="get" action="/finance/products" class="inline-form-row">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('products.search_placeholder')) ?>">
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
        <a href="/finance/products/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('products.new')) ?></a>
    </form>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('products.sku')) ?></th><th><?= e(t('common.name')) ?></th><th><?= e(t('products.price')) ?></th><th><?= e(t('deals.status')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($products as $p): ?>
            <tr>
                <td class="muted"><?= e($p['sku'] ?? '—') ?></td>
                <td><a href="/finance/products/<?= e($p['id']) ?>/edit"><?= e($p['name']) ?></a></td>
                <td><?= e(number_format((float) $p['price'], 0)) ?> <?= e($p['currency_code']) ?></td>
                <td><?= $p['is_active'] ? e(t('products.active')) : e(t('products.inactive')) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($products === []): ?>
            <tr><td colspan="4" class="muted"><?= e(t('products.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
