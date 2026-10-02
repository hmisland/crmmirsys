<?php
/** @var array $organization */
/** @var array $errors */
/** @var array<string, bool> $enabledModules */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
?>
<div class="page-head">
    <div>
        <h1><?= e(t('org.settings_title')) ?></h1>
        <p><?= e(t('org.settings_hint')) ?></p>
    </div>
</div>

<div class="settings-grid">
    <section class="card settings-tile">
        <h2><span class="kpi__icon"><?= icon('users') ?></span><?= e(t('org.team_and_access')) ?></h2>
        <div class="settings-tile__links">
            <a href="/org/users"><?= icon('users') ?><?= e(t('org.team')) ?></a>
            <a href="/org/roles"><?= icon('key') ?><?= e(t('org.roles')) ?></a>
            <a href="/org/structure"><?= icon('network') ?><?= e(t('org.departments')) ?></a>
        </div>
    </section>

    <section class="card settings-tile">
        <h2><span class="kpi__icon"><?= icon('layers') ?></span><?= e(t('org.modules')) ?></h2>
        <div class="settings-tile__links">
            <a href="/org/modules"><?= icon('layers') ?><?= e(t('org.modules_view_restrict')) ?></a>
            <a href="/org/custom-fields"><?= icon('sliders') ?><?= e(t('org.custom_fields')) ?></a>
        </div>
    </section>

    <?php if ($enabledModules['sales']): ?>
    <section class="card settings-tile">
        <h2><span class="kpi__icon"><?= icon('briefcase') ?></span><?= e(t('deals.title')) ?></h2>
        <div class="settings-tile__links">
            <a href="/sales/pipeline"><?= icon('kanban') ?><?= e(t('org.pipeline_and_stages')) ?></a>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($enabledModules['support']): ?>
    <section class="card settings-tile">
        <h2><span class="kpi__icon"><?= icon('life-buoy') ?></span><?= e(t('tickets.title')) ?></h2>
        <div class="settings-tile__links">
            <a href="/support/categories"><?= icon('tag') ?><?= e(t('tickets.categories')) ?></a>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($enabledModules['wallet']): ?>
    <section class="card settings-tile">
        <h2><span class="kpi__icon kpi__icon--success"><?= icon('wallet') ?></span><?= e(t('wallet.my_wallet')) ?></h2>
        <div class="settings-tile__links">
            <a href="/org/wallet"><?= icon('wallet') ?><?= e(t('org.wallet_settings_link')) ?></a>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($enabledModules['notifications']): ?>
    <section class="card settings-tile">
        <h2><span class="kpi__icon kpi__icon--warning"><?= icon('bell') ?></span><?= e(t('notif.admin_title')) ?></h2>
        <div class="settings-tile__links">
            <a href="/notifications/admin"><?= icon('bell') ?><?= e(t('org.notif_settings_link')) ?></a>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($enabledModules['finance']): ?>
    <section class="card settings-tile">
        <h2><span class="kpi__icon kpi__icon--info"><?= icon('receipt') ?></span><?= e(t('finance.settings_title')) ?></h2>
        <div class="settings-tile__links">
            <a href="/finance/settings"><?= icon('settings') ?><?= e(t('finance.settings_title')) ?></a>
            <a href="/finance/periods"><?= icon('calendar') ?><?= e(t('periods.title')) ?></a>
            <a href="/finance/reports"><?= icon('bar-chart') ?><?= e(t('reports.title')) ?></a>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($enabledModules['knowledge']): ?>
    <section class="card settings-tile">
        <h2><span class="kpi__icon"><?= icon('book-open') ?></span><?= e(t('nav.knowledge_base')) ?></h2>
        <div class="settings-tile__links">
            <a href="/kb/categories"><?= icon('tag') ?><?= e(t('kb.categories_title')) ?></a>
            <a href="/files/uploaders"><?= icon('key') ?><?= e(t('files.manage_upload_access')) ?></a>
        </div>
    </section>
    <?php endif; ?>
</div>

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

        <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('profile.save_changes')) ?></button>
    </form>
</section>
