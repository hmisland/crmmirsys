<?php
/**
 * CRM GROUP by MIRSYS — crmgroup.ir
 * Copyright (c) 2026 MIRSYS. All rights reserved.
 */
/** @var \App\Infrastructure\Theme\ThemeLoader $theme */
/** @var string $content */
/** @var string $title */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Modules\ModuleAccessResolver;
use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\OrganizationModuleRepository;
use App\Infrastructure\Repositories\UserModuleOverrideRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Csrf;
use App\Infrastructure\Security\Session;

$translator = Locale::get();
$lang = $translator !== null ? $translator->locale() : 'en';
$dir = $translator !== null ? $translator->direction() : 'ltr';

$authUser = CurrentUser::user();
$successFlash = Session::getFlash('success');
$errorFlash = Session::getFlash('error');

// Per Phase 0 §73: "hiding a menu item is not a security mechanism" —
// this check exists purely so a logged-in user doesn't see a Deals
// link that 404s the moment they click it; ModuleMiddleware is what
// actually enforces access, this is only ever a convenience.
$salesEnabled = $authUser !== null && (new ModuleAccessResolver(
    new ModuleRepository(),
    new OrganizationModuleRepository(),
    new UserModuleOverrideRepository()
))->resolvedForUser('sales', $authUser);

$walletEnabled = $authUser !== null && (new ModuleAccessResolver(
    new ModuleRepository(),
    new OrganizationModuleRepository(),
    new UserModuleOverrideRepository()
))->resolvedForUser('wallet', $authUser);

$supportEnabled = $authUser !== null && (new ModuleAccessResolver(
    new ModuleRepository(),
    new OrganizationModuleRepository(),
    new UserModuleOverrideRepository()
))->resolvedForUser('support', $authUser);

$financeEnabled = $authUser !== null && (new ModuleAccessResolver(
    new ModuleRepository(),
    new OrganizationModuleRepository(),
    new UserModuleOverrideRepository()
))->resolvedForUser('finance', $authUser);

$followupsEnabled = $authUser !== null && (new ModuleAccessResolver(
    new ModuleRepository(),
    new OrganizationModuleRepository(),
    new UserModuleOverrideRepository()
))->resolvedForUser('followups', $authUser);

$mailEnabled = $authUser !== null && (new ModuleAccessResolver(
    new ModuleRepository(),
    new OrganizationModuleRepository(),
    new UserModuleOverrideRepository()
))->resolvedForUser('internal_mail', $authUser);

$knowledgeEnabled = $authUser !== null && (new ModuleAccessResolver(
    new ModuleRepository(),
    new OrganizationModuleRepository(),
    new UserModuleOverrideRepository()
))->resolvedForUser('knowledge', $authUser);

?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" dir="<?= e($dir) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? t('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e($theme->assetUrl('css/app.css')) ?>">
</head>
<body class="dir-<?= e($dir) ?>">
    <header class="app-header">
        <strong><?= e(t('app.name')) ?></strong>
        <button type="button" class="app-header__hamburger" id="nav-toggle" aria-label="<?= e(t('nav.menu')) ?>" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
        <nav class="app-header__nav" id="app-nav">
            <?php if ($authUser !== null): ?>
                <?php if (!empty($authUser['is_super_admin'])): ?>
                    <a href="/admin/organizations"><?= e(t('admin.all_organizations')) ?></a>
                    <a href="/admin/modules"><?= e(t('admin.modules_platform')) ?></a>
                    <a href="/admin/wallet/settings"><?= e(t('admin.wallet_settings_platform')) ?></a>
                    <a href="/admin/notifications/settings"><?= e(t('admin.notification_pricing_platform')) ?></a>
                    <a href="/admin/exchange-rates"><?= e(t('exchange_rates.title')) ?></a>
                <?php endif; ?>
                <?php if ($authUser['organization_id'] !== null): ?>
                    <a href="/"><?= e(t('nav.dashboard')) ?></a>
                    <a href="/contacts"><?= e(t('nav.contacts')) ?></a>
                    <a href="/companies"><?= e(t('nav.companies')) ?></a>
                    <a href="/leads"><?= e(t('nav.leads')) ?></a>
                    <?php if ($salesEnabled): ?>
                        <a href="/deals/board"><?= e(t('nav.deals')) ?></a>
                        <a href="/tasks"><?= e(t('nav.tasks')) ?></a>
                    <?php endif; ?>
                    <?php if ($followupsEnabled): ?>
                        <a href="/followups"><?= e(t('followups.title')) ?></a>
                    <?php endif; ?>
                    <?php if ($financeEnabled): ?>
                        <a href="/finance/products"><?= e(t('products.title')) ?></a>
                        <a href="/finance/proformas"><?= e(t('proformas.title')) ?></a>
                        <a href="/finance/invoices"><?= e(t('invoices.title')) ?></a>
                    <?php endif; ?>
                    <?php if ($walletEnabled): ?>
                        <a href="/wallet"><?= e(t('nav.my_wallet')) ?></a>
                    <?php endif; ?>
                    <?php if ($supportEnabled): ?>
                        <a href="/support/tickets/board"><?= e(t('nav.tickets')) ?></a>
                    <?php endif; ?>
                    <?php if ($mailEnabled): ?>
                        <a href="/mail"><?= e(t('nav.mail')) ?></a>
                    <?php endif; ?>
                    <?php if ($knowledgeEnabled): ?>
                        <a href="/kb/articles"><?= e(t('nav.knowledge_base')) ?></a>
                        <a href="/files"><?= e(t('nav.files')) ?></a>
                    <?php endif; ?>
                    <a href="/search"><?= e(t('nav.search')) ?></a>
                    <a href="/org/settings"><?= e(t('nav.org_settings')) ?></a>
                <?php endif; ?>
                <a href="/profile"><?= e($authUser['first_name'] ?? $authUser['email']) ?></a>
                <form method="post" action="/logout" class="inline-form">
                    <?= Csrf::field() ?>
                    <button type="submit" class="link-button"><?= e(t('nav.logout')) ?></button>
                </form>
            <?php else: ?>
                <a href="/login"><?= e(t('nav.login')) ?></a>
                <a href="/register"><?= e(t('nav.register')) ?></a>
            <?php endif; ?>
            <form method="get" action="/lang/switch" class="inline-form" style="display:inline">
                <input type="hidden" name="return_to" value="<?= e($_SERVER['REQUEST_URI'] ?? '/') ?>">
                <select name="lang" onchange="this.form.submit()" aria-label="<?= e(t('nav.language')) ?>">
                    <?php foreach (\App\Infrastructure\Localization\Translator::SUPPORTED_LOCALES as $locale): ?>
                        <option value="<?= e($locale) ?>" <?= $locale === $lang ? 'selected' : '' ?>><?= e(strtoupper($locale)) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </nav>
    </header>

    <main class="app-main">
        <?php if ($successFlash !== null): ?>
            <div class="alert alert--success"><?= e($successFlash) ?></div>
        <?php endif; ?>
        <?php if ($errorFlash !== null): ?>
            <div class="alert alert--error"><?= e($errorFlash) ?></div>
        <?php endif; ?>

        <?= $content ?>
    </main>

    <footer class="app-footer">
        <span>&copy; <?= e(date('Y')) ?> CRM GROUP by MIRSYS</span>
    </footer>

    <script src="<?= e($theme->assetUrl('js/app.js')) ?>"></script>
    <script src="<?= e($theme->assetUrl('js/datepicker.js')) ?>"></script>
    <script src="<?= e($theme->assetUrl('js/tag-input.js')) ?>"></script>
    <script src="<?= e($theme->assetUrl('js/recipient-picker.js')) ?>"></script>
    <script>
        // System-wide date field convention (docs/conventions.md): every
        // <input class="crm-datepicker"> on the page gets the picker,
        // defaulting to the calendar matching the org's language (fa ->
        // Jalali/Shamsi; extend this mapping once more languages exist)
        // unless the user already picked a calendar system before.
        (function () {
            var defaultCalendar = <?= json_encode($lang === 'fa' ? 'jalali' : 'gregorian') ?>;
            document.querySelectorAll('.crm-datepicker').forEach(function (el) {
                CRMDatePicker.attach(el, { defaultCalendar: defaultCalendar });
            });
        })();

        // Mobile hamburger menu — toggles the nav open/closed as a
        // floating overlay (see the .is-open CSS); no effect above
        // the breakpoint where the nav is always shown inline.
        (function () {
            var toggle = document.getElementById('nav-toggle');
            var nav = document.getElementById('app-nav');
            if (!toggle || !nav) {
                return;
            }
            toggle.addEventListener('click', function (e) {
                e.stopPropagation();
                var isOpen = nav.classList.toggle('is-open');
                toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
            // Tapping anywhere outside the open overlay closes it —
            // the expected behavior for a dropdown, not a
            // push-the-page-down panel.
            document.addEventListener('click', function (e) {
                if (nav.classList.contains('is-open') && !nav.contains(e.target)) {
                    nav.classList.remove('is-open');
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });
        })();
    </script>
</body>
</html>
