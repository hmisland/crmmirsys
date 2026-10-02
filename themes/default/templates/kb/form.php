<?php
/** @var array|null $article */
/** @var array $categories */
/** @var array $errors */
/** @var array $old */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
$val = fn (string $field, string $default = '') => e($old[$field] ?? $article[$field] ?? $default);
$action = $article !== null ? '/kb/articles/' . $article['id'] : '/kb/articles';
?>
<section class="card card--form">
    <h1><?= $article !== null ? e(t('kb.edit_article')) : e(t('kb.new_article')) ?></h1>

    <form method="post" action="<?= e($action) ?>">
        <?= Csrf::field() ?>

        <label for="title"><?= e(t('kb.article_title')) ?></label>
        <input type="text" id="title" name="title" value="<?= $val('title') ?>" required autofocus>
        <?php if ($fieldError('title')): ?><div class="field-error"><?= e($fieldError('title')) ?></div><?php endif; ?>

        <label for="category_id"><?= e(t('tickets.category')) ?></label>
        <select id="category_id" name="category_id">
            <option value=""><?= e(t('common.none')) ?></option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= e($cat['id']) ?>" <?= (string) ($old['category_id'] ?? $article['category_id'] ?? '') === (string) $cat['id'] ? 'selected' : '' ?>>
                    <?= e($cat['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="body"><?= e(t('kb.body')) ?></label>
        <textarea id="body" name="body" rows="10" required><?= $val('body') ?></textarea>
        <?php if ($fieldError('body')): ?><div class="field-error"><?= e($fieldError('body')) ?></div><?php endif; ?>

        <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button>
    </form>
</section>
