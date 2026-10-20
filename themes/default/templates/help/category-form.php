<?php
/**
 * Super Admin → Help → Categories → new / edit: key, order, "listed even
 * while empty", and a name per language (English required, the fallback).
 *
 * @var array|null $category
 * @var array{key: string, sort_order: int, always_listed: bool|int} $values
 * @var array<string, string> $names locale => name
 * @var array<string, string> $errors
 * @var string[] $locales
 */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;

$action = $category !== null ? '/admin/help-categories/' . $category['id'] : '/admin/help-categories';
$languageNames = ['en' => 'English', 'fa' => 'فارسی', 'ar' => 'العربية', 'tr' => 'Türkçe', 'fr' => 'Français'];
$rtl = ['fa', 'ar'];
$err = fn (string $key) => $errors[$key] ?? null;
?>
<div class="page-head">
    <div>
        <h1><?= e(t($category !== null ? 'help.categories_admin.edit' : 'help.categories_admin.new')) ?></h1>
    </div>
    <div class="actions">
        <a href="/admin/help-categories" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('help.categories_admin.title')) ?></a>
    </div>
</div>

<section class="card card--form">
    <form method="post" action="<?= e($action) ?>">
        <?= Csrf::field() ?>

        <?php foreach ($locales as $locale): ?>
            <label for="name-<?= e($locale) ?>"><?= e(t('help.categories_admin.name')) ?> — <?= e($languageNames[$locale] ?? $locale) ?><?= $locale === 'en' ? ' *' : '' ?></label>
            <input type="text" id="name-<?= e($locale) ?>" name="names[<?= e($locale) ?>]" maxlength="100" dir="<?= in_array($locale, $rtl, true) ? 'rtl' : 'ltr' ?>" value="<?= e($names[$locale] ?? '') ?>"<?= $locale === 'en' ? ' required' : '' ?>>
            <?php if ($err('names.' . $locale)): ?><div class="field-error"><?= e($err('names.' . $locale)) ?></div><?php endif; ?>
        <?php endforeach; ?>
        <p class="field-hint"><?= e(t('help.categories_admin.names_hint')) ?></p>

        <label for="key"><?= e(t('help.categories_admin.key')) ?></label>
        <input type="text" id="key" name="key" class="ltr field-md" maxlength="40" required pattern="[a-z0-9]+([\-_][a-z0-9]+)*" value="<?= e((string) ($values['key'] ?? '')) ?>">
        <?php if ($err('key')): ?><div class="field-error"><?= e($err('key')) ?></div><?php endif; ?>
        <p class="field-hint"><?= e(t('help.categories_admin.key_hint')) ?></p>

        <label for="sort_order"><?= e(t('help.sort_order')) ?></label>
        <input type="number" id="sort_order" name="sort_order" step="1" value="<?= e((string) (int) ($values['sort_order'] ?? 0)) ?>">

        <label class="check">
            <input type="checkbox" name="always_listed" value="1"<?= !empty($values['always_listed']) ? ' checked' : '' ?>>
            <?= e(t('help.categories_admin.always_listed')) ?>
        </label>
        <p class="field-hint"><?= e(t('help.categories_admin.always_listed_hint')) ?></p>

        <div class="actions">
            <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('help.categories_admin.save')) ?></button>
        </div>
    </form>
</section>
