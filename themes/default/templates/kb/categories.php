<?php
/** @var array $categories */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(t('kb.categories_title')) ?></h1>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('common.name')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($categories as $cat): ?>
            <tr>
                <td><?= e($cat['name']) ?></td>
                <td>
                    <form method="post" action="/kb/categories/<?= e($cat['id']) ?>/delete" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($categories === []): ?>
            <tr><td colspan="2" class="muted"><?= e(t('tickets.no_categories_yet')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="card card--form">
    <h2><?= e(t('tickets.add_category')) ?></h2>
    <form method="post" action="/kb/categories">
        <?= Csrf::field() ?>
        <label for="name"><?= e(t('common.name')) ?></label>
        <input type="text" id="name" name="name" required>
        <button type="submit" class="btn btn--primary"><?= icon('plus') ?><?= e(t('wallet.add')) ?></button>
    </form>
</section>
