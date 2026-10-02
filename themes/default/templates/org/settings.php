<?php
/** @var array $organization */
/** @var array $errors */
/** @var array<string, bool> $enabledModules */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
?>
<section class="card">
    <h1><?= e(t('org.settings_title')) ?></h1>
    <p class="muted"><?= e(t('org.settings_hint')) ?></p>

    <h2 style="margin-top:1rem"><?= e(t('org.team_and_access')) ?></h2>
    <p>
        <a href="/org/users" class="btn"><?= e(t('org.team')) ?></a>
        <a href="/org/roles" class="btn"><?= e(t('org.roles')) ?></a>
        <a href="/org/structure" class="btn"><?= e(t('org.departments')) ?></a>
    </p>

    <h2><?= e(t('org.modules')) ?></h2>
    <p>
        <a href="/org/modules" class="btn"><?= e(t('org.modules_view_restrict')) ?></a>
        <a href="/org/custom-fields" class="btn"><?= e(t('org.custom_fields')) ?></a>
    </p>

    <?php if ($enabledModules['sales']): ?>
        <h2><?= e(t('deals.title')) ?></h2>
        <p><a href="/sales/pipeline" class="btn"><?= e(t('org.pipeline_and_stages')) ?></a></p>
    <?php endif; ?>

    <?php if ($enabledModules['support']): ?>
        <h2><?= e(t('tickets.title')) ?></h2>
        <p><a href="/support/categories" class="btn"><?= e(t('tickets.categories')) ?></a></p>
    <?php endif; ?>

    <?php if ($enabledModules['wallet']): ?>
        <h2><?= e(t('wallet.my_wallet')) ?></h2>
        <p><a href="/org/wallet" class="btn"><?= e(t('org.wallet_settings_link')) ?></a></p>
    <?php endif; ?>

    <?php if ($enabledModules['notifications']): ?>
        <h2><?= e(t('notif.admin_title')) ?></h2>
        <p><a href="/notifications/admin" class="btn"><?= e(t('org.notif_settings_link')) ?></a></p>
    <?php endif; ?>

    <?php if ($enabledModules['finance']): ?>
        <h2><?= e(t('finance.settings_title')) ?></h2>
        <p>
            <a href="/finance/settings" class="btn"><?= e(t('finance.settings_title')) ?></a>
            <a href="/finance/periods" class="btn"><?= e(t('periods.title')) ?></a>
            <a href="/finance/reports" class="btn"><?= e(t('reports.title')) ?></a>
        </p>
    <?php endif; ?>

    <?php if ($enabledModules['knowledge']): ?>
        <h2><?= e(t('nav.knowledge_base')) ?></h2>
        <p>
            <a href="/kb/categories" class="btn"><?= e(t('kb.categories_title')) ?></a>
            <a href="/files/uploaders" class="btn"><?= e(t('files.manage_upload_access')) ?></a>
        </p>
    <?php endif; ?>
</section>

<section class="card card--form">
    <h2><?= e(t('org.general')) ?></h2>

    <form method="post" action="/org/settings">
        <?= Csrf::field() ?>

        <label for="name"><?= e(t('common.name')) ?></label>
        <input type="text" id="name" name="name" value="<?= e($organization['name']) ?>" required>
        <?php if ($fieldError('name')): ?><div class="field-error"><?= e($fieldError('name')) ?></div><?php endif; ?>

        <label for="default_language"><?= e(t('org.default_language')) ?></label>
        <select id="default_language" name="default_language">
            <option value="en" <?= $organization['default_language'] === 'en' ? 'selected' : '' ?>>English</option>
            <option value="fa" <?= $organization['default_language'] === 'fa' ? 'selected' : '' ?>>فارسی (Persian)</option>
            <option value="ar" <?= $organization['default_language'] === 'ar' ? 'selected' : '' ?>>العربية (Arabic)</option>
            <option value="tr" <?= $organization['default_language'] === 'tr' ? 'selected' : '' ?>>Türkçe (Turkish)</option>
        </select>

        <label for="default_timezone"><?= e(t('org.default_timezone')) ?></label>
        <input type="text" id="default_timezone" name="default_timezone" value="<?= e($organization['default_timezone']) ?>">

        <button type="submit" class="btn btn--primary"><?= e(t('profile.save_changes')) ?></button>
    </form>
</section>
