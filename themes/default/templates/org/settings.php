<?php
/** @var array $organization */
/** @var array $errors */
/** @var array<string, bool> $enabledModules */
/** @var array $old */
/** @var bool $canEdit */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
use App\Infrastructure\Time\OrganizationTimezone;

$fieldError = fn (string $field) => isset($errors[$field]) ? t($errors[$field][0]) : null;
$profileValue = fn (string $field): string => (string) ($old[$field] ?? $organization[$field] ?? '');
$currentTimezone = (string) ($old['default_timezone'] ?? $organization['default_timezone'] ?? '');
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
            <?php if ($canEdit): ?><a href="/org/modules/catalog"><?= icon('grid') ?><?= e(t('catalog.title')) ?></a><?php endif; ?>
            <a href="/org/custom-fields"><?= icon('sliders') ?><?= e(t('org.custom_fields')) ?></a>
        </div>
    </section>

    <?php if (!empty($enabledModules['customer_portal'])): ?>
    <section class="card settings-tile">
        <h2><span class="kpi__icon"><?= icon('users') ?></span><?= e(t('customer_portal.settings.title')) ?></h2>
        <p class="field-hint"><?= e(t('customer_portal.settings.hint')) ?></p>
        <?php $portalUrl = rtrim((string) \App\Infrastructure\Config\Config::get('app.url', ''), '/') . '/customer/login/' . rawurlencode((string) $organization['slug']); ?>
        <p><a href="<?= e($portalUrl) ?>" class="ltr" target="_blank" rel="noopener"><?= e($portalUrl) ?></a></p>
    </section>
    <?php endif; ?>

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

    <?php if ($smsAvailable ?? false): ?>
    <section class="card settings-tile">
        <h2><span class="kpi__icon kpi__icon--info"><?= icon('message') ?></span><?= e(t('org_sms.title')) ?></h2>
        <div class="settings-tile__links">
            <a href="/org/sms"><?= icon('settings') ?><?= e(t('org_sms.settings_link')) ?></a>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <section class="card settings-tile">
        <h2><span class="kpi__icon kpi__icon--info"><?= icon('send') ?></span><?= e(t('messaging.title')) ?></h2>
        <div class="settings-tile__links">
            <a href="/org/messaging"><?= icon('settings') ?><?= e(t('messaging.settings_link')) ?></a>
        </div>
    </section>

    <section class="card settings-tile">
        <h2><span class="kpi__icon kpi__icon--info"><?= icon('plug') ?></span><?= e(t('integrations.title')) ?></h2>
        <div class="settings-tile__links">
            <a href="/org/integrations"><?= icon('settings') ?><?= e(t('integrations.settings_link')) ?></a>
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

    <?php if (!$canEdit): ?>
        <p class="locked"><?= icon('lock') ?><?= e(t('org.settings_read_only')) ?></p>
    <?php endif; ?>
    <form method="post" action="/org/settings" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <fieldset class="form-stack fieldset--plain"<?= $canEdit ? '' : ' disabled' ?>>

        <label for="name"><?= e(t('common.name')) ?></label>
        <input type="text" id="name" name="name" value="<?= e($organization['name']) ?>" required>
        <?php if ($fieldError('name')): ?><div class="field-error"><?= e($fieldError('name')) ?></div><?php endif; ?>

        <label for="default_language"><?= e(t('org.default_language')) ?></label>
        <select id="default_language" name="default_language">
            <option value="en" <?= $organization['default_language'] === 'en' ? 'selected' : '' ?>>English</option>
            <option value="fa" <?= $organization['default_language'] === 'fa' ? 'selected' : '' ?>>فارسی (Persian)</option>
            <option value="ar" <?= $organization['default_language'] === 'ar' ? 'selected' : '' ?>>العربية (Arabic)</option>
            <option value="tr" <?= $organization['default_language'] === 'tr' ? 'selected' : '' ?>>Türkçe (Turkish)</option>
            <option value="fr" <?= $organization['default_language'] === 'fr' ? 'selected' : '' ?>>Français (French)</option>
        </select>

        <label for="default_timezone"><?= e(t('org.timezone')) ?></label>
        <select id="default_timezone" name="default_timezone">
            <?php if (!OrganizationTimezone::isValid($currentTimezone)): ?><option value="" selected><?= e(t('org.timezone_choose')) ?></option><?php endif; ?>
            <?php foreach (OrganizationTimezone::choices() as $region => $zones): ?>
                <optgroup label="<?= e($region) ?>">
                    <?php foreach ($zones as $id => $label): ?>
                        <option value="<?= e($id) ?>" <?= $id === $currentTimezone ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
        </select>
        <?php if ($fieldError('default_timezone')): ?><div class="field-error"><?= e($fieldError('default_timezone')) ?></div><?php endif; ?>
        <p class="field-hint"><?= e(t('org.timezone_hint')) ?></p>

        <h3 class="form-section-title"><?= e(t('org.seller_profile')) ?></h3>
        <p class="field-hint"><?= e(t('org.seller_profile_hint')) ?></p>

        <div class="form-grid">
            <div>
                <label for="national_id"><?= e(t('org.national_id')) ?></label>
                <input type="text" id="national_id" name="national_id" value="<?= e($profileValue('national_id')) ?>" maxlength="30" dir="ltr">
            </div>
            <div>
                <label for="economic_code"><?= e(t('org.economic_code')) ?></label>
                <input type="text" id="economic_code" name="economic_code" value="<?= e($profileValue('economic_code')) ?>" maxlength="30" dir="ltr">
            </div>
            <div>
                <label for="registration_number"><?= e(t('org.registration_number')) ?></label>
                <input type="text" id="registration_number" name="registration_number" value="<?= e($profileValue('registration_number')) ?>" maxlength="50" dir="ltr">
            </div>
            <div>
                <label for="phone"><?= e(t('common.phone')) ?></label>
                <input type="tel" id="phone" name="phone" value="<?= e($profileValue('phone')) ?>" maxlength="50">
            </div>
            <div>
                <label for="postal_code"><?= e(t('org.postal_code')) ?></label>
                <input type="text" id="postal_code" name="postal_code" value="<?= e($profileValue('postal_code')) ?>" maxlength="20" dir="ltr">
            </div>
        </div>

        <label for="address"><?= e(t('org.address')) ?></label>
        <textarea id="address" name="address" rows="2" maxlength="500"><?= e($profileValue('address')) ?></textarea>

        <label for="logo"><?= e(t('org.logo')) ?></label>
        <?php if (!empty($organization['logo_path'])): ?>
            <div class="logo-preview">
                <img src="/org/logo?v=<?= e(md5((string) $organization['logo_path'])) ?>" alt="<?= e(t('org.logo')) ?>">
                <label class="check"><input type="checkbox" name="remove_logo" value="1"> <?= e(t('org.remove_logo')) ?></label>
            </div>
        <?php endif; ?>
        <input type="file" id="logo" name="logo" accept="image/png,image/jpeg">
        <?php if ($fieldError('logo')): ?><div class="field-error"><?= e($fieldError('logo')) ?></div><?php endif; ?>
        <p class="field-hint"><?= e(t('org.logo_hint')) ?></p>

        <?php if ($canEdit): ?>
            <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('profile.save_changes')) ?></button>
        <?php endif; ?>
        </fieldset>
    </form>
</section>
