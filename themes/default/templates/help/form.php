<?php
/** @var array|null $article */
/** @var array $values */
/** @var array<string, array> $translations keyed by locale */
/** @var array<string, string> $errors */
/** @var string[] $locales */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$action = $article !== null ? '/admin/help/' . $article['id'] : '/admin/help';
$languageNames = ['en' => 'English', 'fa' => 'فارسی', 'ar' => 'العربية', 'tr' => 'Türkçe', 'fr' => 'Français'];
$rtl = ['fa', 'ar'];
$err = fn (string $key) => $errors[$key] ?? null;
?>
<form method="post" action="<?= e($action) ?>" class="page-stack">
    <?= Csrf::field() ?>

    <section class="card card--form">
        <h1><?= e(t($article !== null ? 'help.edit' : 'help.new')) ?></h1>

        <label for="slug"><?= e(t('help.slug')) ?></label>
        <input type="text" id="slug" name="slug" class="ltr field-md" value="<?= e($values['slug'] ?? '') ?>" maxlength="100" required pattern="[a-z0-9]+(-[a-z0-9]+)*">
        <?php if ($err('slug')): ?><div class="field-error"><?= e($err('slug')) ?></div><?php endif; ?>
        <p class="field-hint"><?= e(t('help.slug_hint')) ?></p>

        <label for="sort_order"><?= e(t('help.sort_order')) ?></label>
        <input type="number" id="sort_order" name="sort_order" value="<?= e((int) ($values['sort_order'] ?? 0)) ?>" step="1">

        <label class="check">
            <input type="checkbox" name="is_published" value="1" <?= !empty($values['is_published']) ? 'checked' : '' ?>>
            <?= e(t('help.published')) ?>
        </label>
        <p class="field-hint"><?= e(t('help.published_hint')) ?></p>
    </section>

    <section class="card card--form" data-tabs>
        <h2><?= e(t('help.content')) ?></h2>
        <p class="field-hint"><?= e(t('help.content_hint')) ?></p>

        <div class="tabs" role="tablist">
            <?php foreach ($locales as $i => $locale): ?>
                <button type="button" class="tab-btn<?= $i === 0 ? ' active' : '' ?>" data-tab="lang-<?= e($locale) ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"><?= icon('globe') ?><?= e($languageNames[$locale] ?? strtoupper($locale)) ?></button>
            <?php endforeach; ?>
        </div>

        <?php foreach ($locales as $i => $locale):
            $tr = $translations[$locale] ?? [];
            $dir = in_array($locale, $rtl, true) ? 'rtl' : 'ltr';
            $prefix = 'tr.' . $locale . '.';
        ?>
            <div class="tab-panel" data-panel="lang-<?= e($locale) ?>"<?= $i > 0 ? ' hidden' : '' ?>>
                <label for="title-<?= e($locale) ?>"><?= e(t('help.article_title')) ?></label>
                <input type="text" id="title-<?= e($locale) ?>" name="tr[<?= e($locale) ?>][title]" dir="<?= $dir ?>" lang="<?= e($locale) ?>" maxlength="200" value="<?= e($tr['title'] ?? '') ?>">
                <?php if ($err($prefix . 'title')): ?><div class="field-error"><?= e($err($prefix . 'title')) ?></div><?php endif; ?>

                <label for="summary-<?= e($locale) ?>"><?= e(t('help.summary')) ?></label>
                <input type="text" id="summary-<?= e($locale) ?>" name="tr[<?= e($locale) ?>][summary]" dir="<?= $dir ?>" lang="<?= e($locale) ?>" maxlength="500" value="<?= e($tr['summary'] ?? '') ?>">
                <?php if ($err($prefix . 'summary')): ?><div class="field-error"><?= e($err($prefix . 'summary')) ?></div><?php endif; ?>

                <label for="body-<?= e($locale) ?>"><?= e(t('help.body')) ?></label>
                <textarea id="body-<?= e($locale) ?>" name="tr[<?= e($locale) ?>][body]" rows="22" class="help-source ltr" spellcheck="false"><?= e($tr['body'] ?? '') ?></textarea>
                <?php if ($err($prefix . 'body')): ?><div class="field-error"><?= e($err($prefix . 'body')) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>

        <p class="field-hint"><?= e(t('help.allowed_html')) ?></p>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button>
            <a href="<?= $article !== null ? '/help/' . e(rawurlencode((string) $article['slug'])) : '/help' ?>" class="btn"><?= icon('x') ?><?= e(t('common.cancel')) ?></a>
        </div>
    </section>
</form>
