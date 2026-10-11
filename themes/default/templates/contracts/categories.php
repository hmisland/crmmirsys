<?php
/** @var array $categories */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<div class="page-head">
    <div>
        <h1><?= e(t('contracts.categories_title')) ?></h1>
        <p><?= e(t('contracts.categories_subtitle')) ?></p>
    </div>
    <div class="quick-actions">
        <a href="/contracts" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('contracts.back_to_list')) ?></a>
    </div>
</div>

<section class="card">
    <form method="post" action="/contracts/categories" class="inline-form-row">
        <?= Csrf::field() ?>
        <input type="text" name="name" maxlength="150" required placeholder="<?= e(t('contracts.category_name')) ?>" aria-label="<?= e(t('contracts.category_name')) ?>">
        <button type="submit" class="btn btn--primary"><?= icon('plus') ?><?= e(t('contracts.add_category')) ?></button>
    </form>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('contracts.category_name')) ?></th><th><span class="visually-hidden"><?= e(t('contacts.row_actions')) ?></span></th></tr></thead>
        <tbody>
        <?php foreach ($categories as $cat): ?>
            <tr>
                <td>
                    <form method="post" action="/contracts/categories/<?= e($cat['id']) ?>" class="inline-form-row">
                        <?= Csrf::field() ?>
                        <input type="text" name="name" value="<?= e($cat['name']) ?>" maxlength="150" required aria-label="<?= e(t('contracts.category_name')) ?>">
                        <button type="submit" class="btn btn--sm"><?= icon('save') ?><?= e(t('common.save')) ?></button>
                    </form>
                </td>
                <td class="actions-cell">
                    <form method="post" action="/contracts/categories/<?= e($cat['id']) ?>/delete" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn--sm btn--danger" data-confirm="<?= e(t('contracts.category_delete_confirm')) ?>"><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($categories === []): ?>
            <tr><td colspan="2" class="muted"><?= e(t('contracts.categories_empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
