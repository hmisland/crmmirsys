<?php
/** @var array $categories */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(t('companies.categories_title')) ?></h1>
    <p><a href="/companies" class="btn"><?= icon('arrow-left') ?><?= e(t('companies.back_to_list')) ?></a></p>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('common.name')) ?></th><th><span class="visually-hidden"><?= e(t('contacts.row_actions')) ?></span></th></tr></thead>
        <tbody>
        <?php foreach ($categories as $cat): ?>
            <tr>
                <td><a href="/companies?category_id=<?= e($cat['id']) ?>"><?= e($cat['name']) ?></a></td>
                <td class="actions-cell">
                    <form method="post" action="/companies/categories/<?= e($cat['id']) ?>/delete" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button link-button--danger" data-confirm="<?= e(t('companies.delete_category_confirm')) ?>"><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($categories === []): ?>
            <tr><td colspan="2" class="muted"><?= e(t('companies.no_categories_yet')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="card card--form">
    <h2><?= e(t('companies.add_category')) ?></h2>
    <form method="post" action="/companies/categories">
        <?= Csrf::field() ?>
        <label for="category_name"><?= e(t('common.name')) ?></label>
        <input type="text" id="category_name" name="name" maxlength="100" required>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= icon('plus') ?><?= e(t('companies.add_category')) ?></button>
        </div>
    </form>
</section>
