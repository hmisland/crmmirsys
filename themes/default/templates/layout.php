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
use function App\Infrastructure\Theme\icon;
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
// Set by ErrorHandler when a page/action was denied (403) — shown as a popup.
$forbiddenFlash = Session::getFlash('forbidden') !== null;

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

$contractsEnabled = $authUser !== null && (new ModuleAccessResolver(
    new ModuleRepository(),
    new OrganizationModuleRepository(),
    new UserModuleOverrideRepository()
))->resolvedForUser('contracts', $authUser);

$projectsEnabled = $authUser !== null && (new ModuleAccessResolver(
    new ModuleRepository(),
    new OrganizationModuleRepository(),
    new UserModuleOverrideRepository()
))->resolvedForUser('projects', $authUser);

$clientRecordsEnabled = $authUser !== null && (new ModuleAccessResolver(
    new ModuleRepository(),
    new OrganizationModuleRepository(),
    new UserModuleOverrideRepository()
))->resolvedForUser('client_records', $authUser);

$appointmentsEnabled = $authUser !== null && (new ModuleAccessResolver(
    new ModuleRepository(),
    new OrganizationModuleRepository(),
    new UserModuleOverrideRepository()
))->resolvedForUser('appointments', $authUser);

// ---------------------------------------------------------------------
// Navigation model (presentation only). Each group is one module area;
// each item lists the URL prefixes that count as "inside" it, so the
// sidebar highlight and breadcrumb follow the user into sub-pages.
// Visibility uses exactly the same flags as before this redesign.
// ---------------------------------------------------------------------
$isSuperAdmin = $authUser !== null && !empty($authUser['is_super_admin']);
$hasOrg = $authUser !== null && $authUser['organization_id'] !== null;

$navGroups = [];
if ($isSuperAdmin) {
    $navGroups[] = ['label' => t('nav.group_platform'), 'items' => [
        ['href' => '/admin/organizations', 'icon' => 'building', 'label' => t('admin.all_organizations'), 'match' => ['/admin/organizations']],
        ['href' => '/admin/modules', 'icon' => 'layers', 'label' => t('admin.modules_platform'), 'match' => ['/admin/modules']],
        ['href' => '/admin/wallet/settings', 'icon' => 'wallet', 'label' => t('admin.wallet_settings_platform'), 'match' => ['/admin/wallet/settings', '/admin/wallet/banks']],
        ['href' => '/admin/wallet/gift-codes', 'icon' => 'zap', 'label' => t('wallet_gift.title'), 'match' => ['/admin/wallet/gift-codes']],
        ['href' => '/admin/notifications/settings', 'icon' => 'bell', 'label' => t('admin.notification_pricing_platform'), 'match' => ['/admin/notifications']],
        ['href' => '/admin/exchange-rates', 'icon' => 'coins', 'label' => t('exchange_rates.title'), 'match' => ['/admin/exchange-rates']],
        ['href' => '/admin/sms', 'icon' => 'message', 'label' => t('sms.title'), 'match' => ['/admin/sms']],
        ['href' => '/admin/announcements', 'icon' => 'megaphone', 'label' => t('announcements.title'), 'match' => ['/admin/announcements']],
    ]];
}
if ($hasOrg) {
    $navGroups[] = ['label' => null, 'items' => [
        ['href' => '/', 'icon' => 'dashboard', 'label' => t('nav.dashboard'), 'match' => ['/'], 'exact' => true],
    ]];
    $crmItems = [
        ['href' => '/contacts', 'icon' => 'users', 'label' => t('nav.contacts'), 'match' => ['/contacts']],
        ['href' => '/companies', 'icon' => 'building', 'label' => t('nav.companies'), 'match' => ['/companies']],
        ['href' => '/leads', 'icon' => 'target', 'label' => t('nav.leads'), 'match' => ['/leads']],
    ];
    if ($clientRecordsEnabled) {
        $crmItems[] = ['href' => '/client-records', 'icon' => 'clipboard', 'label' => t('client_records.title'), 'match' => ['/client-records']];
    }
    if ($appointmentsEnabled) {
        $crmItems[] = ['href' => '/appointments', 'icon' => 'calendar', 'label' => t('appointments.title'), 'match' => ['/appointments']];
    }
    $navGroups[] = ['label' => t('nav.group_crm'), 'items' => $crmItems];
    $salesItems = [];
    if ($salesEnabled) {
        $salesItems[] = ['href' => '/deals/board', 'icon' => 'briefcase', 'label' => t('nav.deals'), 'match' => ['/deals']];
        $salesItems[] = ['href' => '/tasks', 'icon' => 'check-square', 'label' => t('nav.tasks'), 'match' => ['/tasks']];
    }
    if ($followupsEnabled) {
        $salesItems[] = ['href' => '/followups', 'icon' => 'calendar-check', 'label' => t('followups.title'), 'match' => ['/followups']];
    }
    if ($salesItems !== []) {
        $navGroups[] = ['label' => t('nav.group_sales'), 'items' => $salesItems];
    }
    if ($contractsEnabled) {
        $navGroups[] = ['label' => t('nav.group_contracts'), 'items' => [
            ['href' => '/contracts', 'icon' => 'file-text', 'label' => t('contracts.title'), 'match' => ['/contracts']],
        ]];
    }
    if ($projectsEnabled) {
        $navGroups[] = ['label' => t('nav.group_projects'), 'items' => [
            ['href' => '/projects', 'icon' => 'kanban', 'label' => t('projects.title'), 'match' => ['/projects']],
        ]];
    }
    $financeItems = [];
    if ($financeEnabled) {
        $financeItems[] = ['href' => '/finance/products', 'icon' => 'package', 'label' => t('products.title'), 'match' => ['/finance/products']];
        $financeItems[] = ['href' => '/finance/proformas', 'icon' => 'file-text', 'label' => t('proformas.title'), 'match' => ['/finance/proformas']];
        $financeItems[] = ['href' => '/finance/invoices', 'icon' => 'receipt', 'label' => t('invoices.title'), 'match' => ['/finance/invoices']];
        $financeItems[] = ['href' => '/finance/expenses', 'icon' => 'coins', 'label' => t('finance.expense.title'), 'match' => ['/finance/expenses']];
    }
    if ($walletEnabled) {
        $financeItems[] = ['href' => '/wallet', 'icon' => 'wallet', 'label' => t('nav.my_wallet'), 'match' => ['/wallet']];
    }
    if ($financeItems !== []) {
        $navGroups[] = ['label' => t('nav.group_finance'), 'items' => $financeItems];
    }
    $commItems = [];
    if ($supportEnabled) {
        $commItems[] = ['href' => '/support/tickets/board', 'icon' => 'life-buoy', 'label' => t('nav.tickets'), 'match' => ['/support/tickets', '/support']];
    }
    if ($mailEnabled) {
        $commItems[] = ['href' => '/mail', 'icon' => 'mail', 'label' => t('nav.mail'), 'match' => ['/mail']];
    }
    if ($commItems !== []) {
        $navGroups[] = ['label' => t('nav.group_communication'), 'items' => $commItems];
    }
    if ($knowledgeEnabled) {
        $navGroups[] = ['label' => t('nav.group_knowledge'), 'items' => [
            ['href' => '/kb/articles', 'icon' => 'book-open', 'label' => t('nav.knowledge_base'), 'match' => ['/kb']],
            ['href' => '/files', 'icon' => 'folder', 'label' => t('nav.files'), 'match' => ['/files']],
        ]];
    }
    $navGroups[] = ['label' => t('nav.group_organization'), 'items' => [
        ['href' => '/search', 'icon' => 'search', 'label' => t('nav.search'), 'match' => ['/search']],
        ['href' => '/org/settings', 'icon' => 'settings', 'label' => t('nav.org_settings'),
            'match' => ['/org', '/finance/settings', '/finance/periods', '/finance/reports', '/notifications/admin', '/sales/pipeline', '/support/categories', '/kb/categories', '/files/uploaders']],
    ]];
}
// Help center: readable by every signed-in user; management (/admin/help)
// is Super Admin only and is checked in HelpController.
if ($authUser !== null) {
    $navGroups[] = ['label' => t('nav.group_help'), 'items' => [
        ['href' => '/help', 'icon' => 'help-circle', 'label' => t('help.title'), 'match' => ['/help', '/admin/help']],
    ]];
}

// Active item = the longest matching prefix across every visible item.
$currentPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$activeHref = null;
$activeGroup = null;
$activeLabel = null;
$bestLength = -1;
foreach ($navGroups as $group) {
    foreach ($group['items'] as $item) {
        foreach ($item['match'] as $prefix) {
            $hit = !empty($item['exact'])
                ? $currentPath === $prefix
                : ($currentPath === $prefix || str_starts_with($currentPath, rtrim($prefix, '/') . '/'));
            if ($hit && strlen($prefix) > $bestLength) {
                $bestLength = strlen($prefix);
                $activeHref = $item['href'];
                $activeGroup = $group['label'];
                $activeLabel = $item['label'];
            }
        }
    }
}
if ($activeLabel === null && $authUser !== null && str_starts_with($currentPath, '/profile')) {
    $activeLabel = t('nav.profile');
}

$pageTitle = $title ?? ($activeLabel !== null ? $activeLabel . ' · ' . t('app.name') : t('app.name'));

$displayName = '';
$initial = '';
if ($authUser !== null) {
    $displayName = trim(($authUser['first_name'] ?? '') . ' ' . ($authUser['last_name'] ?? ''));
    if ($displayName === '') {
        $displayName = (string) ($authUser['email'] ?? '');
    }
    $initial = function_exists('mb_substr') ? mb_substr($displayName, 0, 1) : substr($displayName, 0, 1);
}

// Language names are shown in their own script, never translated —
// a user who can't read the current UI language must still find theirs.
$languageNames = ['en' => 'English', 'fa' => 'فارسی', 'ar' => 'العربية', 'tr' => 'Türkçe', 'fr' => 'Français'];

$asset = function (string $path) use ($theme): string {
    $file = $theme->assetsPath() . '/' . $path;

    return $theme->assetUrl($path) . (is_file($file) ? '?v=' . filemtime($file) : '');
};
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" dir="<?= e($dir) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e($asset('css/app.css')) ?>">
    <script>
        // Restore the desktop sidebar's collapsed state before first
        // paint (no flash of the expanded sidebar).
        try { if (localStorage.getItem('crm_sidebar_collapsed') === '1') { document.documentElement.classList.add('sidebar-collapsed'); } } catch (e) {}
    </script>
</head>
<body class="dir-<?= e($dir) ?>">
<?php include __DIR__ . '/partials/icons.php'; ?>
<a class="skip-link" href="#main"><?= e(t('nav.skip_to_content')) ?></a>

<?php
$flashes = '';
if ($successFlash !== null) {
    $flashes .= '<div class="alert alert--success" role="status"><div class="alert__body">' . e($successFlash) . '</div>'
        . '<button type="button" class="btn btn--ghost btn--icon btn--sm alert__close" data-dismiss="alert" aria-label="' . e(t('common.close')) . '">' . icon('x', 'icon--sm') . '</button></div>';
}
if ($errorFlash !== null) {
    $flashes .= '<div class="alert alert--error" role="alert"><div class="alert__body">' . e($errorFlash) . '</div>'
        . '<button type="button" class="btn btn--ghost btn--icon btn--sm alert__close" data-dismiss="alert" aria-label="' . e(t('common.close')) . '">' . icon('x', 'icon--sm') . '</button></div>';
}

$languageSwitcher = function () use ($lang, $languageNames): string {
    ob_start(); ?>
    <form method="get" action="/lang/switch" class="lang-switch">
        <input type="hidden" name="return_to" value="<?= e($_SERVER['REQUEST_URI'] ?? '/') ?>">
        <?= icon('globe', 'icon--sm') ?>
        <label for="lang-select" class="visually-hidden"><?= e(t('nav.change_language')) ?></label>
        <select id="lang-select" name="lang" onchange="this.form.submit()">
            <?php foreach (\App\Infrastructure\Localization\Translator::SUPPORTED_LOCALES as $locale): ?>
                <option value="<?= e($locale) ?>" lang="<?= e($locale) ?>" <?= $locale === $lang ? 'selected' : '' ?>><?= e($languageNames[$locale] ?? strtoupper($locale)) ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn--sm"><?= e(t('nav.language')) ?></button></noscript>
    </form>
    <?php return (string) ob_get_clean();
};
?>

<?php if ($authUser === null): ?>
<div class="auth-shell">
    <header class="auth-shell__top">
        <a href="/" class="auth-shell__brand">
            <span class="brand-mark"><?= icon('zap') ?></span>
            <span><?= e(t('app.name')) ?></span>
        </a>
        <div class="auth-shell__links">
            <a href="/login" class="btn btn--ghost btn--sm"><?= icon('log-in', 'icon--sm') ?><?= e(t('nav.login')) ?></a>
            <a href="/register" class="btn btn--sm"><?= icon('user-plus', 'icon--sm') ?><?= e(t('nav.register')) ?></a>
            <?= $languageSwitcher() ?>
        </div>
    </header>

    <main class="app-main" id="main" tabindex="-1">
        <?= $flashes ?>
        <?= $content ?>
    </main>

    <footer class="app-footer">
        <span>&copy; <?= e(date('Y')) ?> CRM GROUP by MIRSYS</span>
    </footer>
</div>
<?php else: ?>
<div class="app-shell" id="app-shell">
    <aside class="sidebar" id="app-sidebar" aria-label="<?= e(t('nav.main_navigation')) ?>">
        <div class="sidebar__head">
            <a href="<?= $hasOrg ? '/' : '/admin/organizations' ?>" class="sidebar__brand">
                <span class="brand-mark"><?= icon('zap') ?></span>
                <span class="sidebar__brand-name"><?= e(t('app.name')) ?></span>
            </a>
            <button type="button" class="btn btn--ghost btn--icon btn--sm sidebar__close" data-nav-close aria-label="<?= e(t('nav.close_menu')) ?>"><?= icon('x') ?></button>
        </div>

        <nav class="sidebar__nav" aria-label="<?= e(t('nav.main_navigation')) ?>">
            <?php foreach ($navGroups as $group): ?>
                <div class="nav-group">
                    <?php if ($group['label'] !== null): ?>
                        <p class="nav-group__label"><?= e($group['label']) ?></p>
                    <?php endif; ?>
                    <?php foreach ($group['items'] as $item): ?>
                        <a href="<?= e($item['href']) ?>" class="nav-item" title="<?= e($item['label']) ?>"<?= $item['href'] === $activeHref ? ' aria-current="page"' : '' ?>>
                            <?= icon($item['icon']) ?>
                            <span class="nav-item__label"><?= e($item['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>
    </aside>
    <div class="sidebar-backdrop" data-nav-close></div>

    <div class="app-body">
        <header class="topbar">
            <button type="button" class="btn btn--ghost btn--icon topbar__menu" id="nav-toggle" aria-controls="app-sidebar" aria-expanded="false" aria-label="<?= e(t('nav.menu')) ?>"><?= icon('menu') ?></button>
            <button type="button" class="btn btn--ghost btn--icon topbar__collapse" id="sidebar-collapse" aria-controls="app-sidebar" aria-label="<?= e(t('nav.toggle_sidebar')) ?>" title="<?= e(t('nav.toggle_sidebar')) ?>"><?= icon('panel') ?></button>

            <?php if ($activeLabel !== null): ?>
                <nav class="topbar__crumbs" aria-label="<?= e(t('nav.breadcrumb')) ?>">
                    <ol class="breadcrumb">
                        <?php if ($activeGroup !== null): ?><li><?= e($activeGroup) ?></li><?php endif; ?>
                        <li><span aria-current="page"><?= e($activeLabel) ?></span></li>
                    </ol>
                </nav>
            <?php endif; ?>

            <div class="topbar__spacer"></div>

            <?php if ($hasOrg): ?>
                <form method="get" action="/search" class="topbar-search" role="search">
                    <?= icon('search', 'icon--sm') ?>
                    <label for="topbar-q" class="visually-hidden"><?= e(t('nav.search')) ?></label>
                    <input type="search" id="topbar-q" name="q" placeholder="<?= e(t('dashboard.search_placeholder')) ?>" autocomplete="off">
                </form>
                <a href="/search" class="btn btn--ghost btn--icon topbar__search-link" aria-label="<?= e(t('nav.search')) ?>"><?= icon('search') ?></a>
            <?php endif; ?>

            <?php include __DIR__ . '/partials/announcement-bell.php'; ?>

            <?= $languageSwitcher() ?>

            <details class="dropdown user-menu">
                <summary aria-label="<?= e(t('nav.account_menu')) ?>">
                    <span class="avatar" aria-hidden="true"><?= e($initial) ?></span>
                    <span class="user-menu__name"><?= e($authUser['first_name'] ?? $authUser['email']) ?></span>
                    <?= icon('chevron-down', 'icon--sm') ?>
                </summary>
                <div class="dropdown__panel">
                    <div class="dropdown__header">
                        <strong><?= e($displayName) ?></strong>
                        <span class="ltr"><?= e($authUser['email'] ?? '') ?></span>
                    </div>
                    <a href="/profile" class="dropdown__item"><?= icon('user') ?><?= e(t('nav.profile')) ?></a>
                    <?php if ($hasOrg): ?>
                        <a href="/org/settings" class="dropdown__item"><?= icon('settings') ?><?= e(t('nav.org_settings')) ?></a>
                    <?php endif; ?>
                    <form method="post" action="/logout" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="dropdown__item dropdown__item--danger"><?= icon('log-out') ?><?= e(t('nav.logout')) ?></button>
                    </form>
                </div>
            </details>
        </header>

        <main class="app-main" id="main" tabindex="-1">
            <?= $flashes ?>
            <?= $content ?>
        </main>

        <footer class="app-footer">
            <span>&copy; <?= e(date('Y')) ?> CRM GROUP by MIRSYS</span>
        </footer>
    </div>
</div>

<?php // Shared popup for help_link() — filled on demand from /help/{slug}?fragment=1 ?>
<dialog class="modal modal--wide" id="help-dialog" aria-labelledby="help-dialog-title" data-error-text="<?= e(t('help.load_error')) ?>">
    <div class="modal__head">
        <h2 class="modal__title" id="help-dialog-title" data-related-title></h2>
        <form method="dialog">
            <button type="submit" class="btn btn--ghost btn--icon" aria-label="<?= e(t('common.close')) ?>"><?= icon('x') ?></button>
        </form>
    </div>
    <div class="modal__content" data-related-body></div>
</dialog>
<?php endif; ?>

<dialog class="modal" id="confirm-dialog" aria-labelledby="confirm-dialog-title" aria-describedby="confirm-dialog-text">
    <form method="dialog">
        <div class="modal__body">
            <span class="modal__icon"><?= icon('alert-triangle') ?></span>
            <div>
                <h2 class="modal__title" id="confirm-dialog-title"><?= e(t('common.confirm_title')) ?></h2>
                <p class="modal__text" id="confirm-dialog-text"><?= e(t('common.confirm_delete')) ?></p>
            </div>
        </div>
        <div class="modal__footer">
            <button type="submit" value="cancel" class="btn" autofocus><?= e(t('common.cancel')) ?></button>
            <button type="submit" value="confirm" class="btn btn--danger-solid"><?= e(t('common.confirm')) ?></button>
        </div>
    </form>
</dialog>

<?php if ($forbiddenFlash): ?>
<dialog class="modal" id="forbidden-dialog" data-autoopen open aria-labelledby="forbidden-dialog-title" aria-describedby="forbidden-dialog-text">
    <form method="dialog">
        <div class="modal__body">
            <span class="modal__icon"><?= icon('lock') ?></span>
            <div>
                <h2 class="modal__title" id="forbidden-dialog-title"><?= e(t('errors.forbidden_title')) ?></h2>
                <p class="modal__text" id="forbidden-dialog-text"><?= e(t('errors.forbidden_text')) ?></p>
            </div>
        </div>
        <div class="modal__footer">
            <button type="submit" value="ok" class="btn btn--primary" autofocus><?= icon('check') ?><?= e(t('errors.forbidden_ok')) ?></button>
        </div>
    </form>
</dialog>
<?php endif; ?>

    <script src="<?= e($asset('js/app.js')) ?>"></script>
    <script src="<?= e($asset('js/datepicker.js')) ?>"></script>
    <script src="<?= e($asset('js/tag-input.js')) ?>"></script>
    <script src="<?= e($asset('js/recipient-picker.js')) ?>"></script>
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
    </script>
</body>
</html>
