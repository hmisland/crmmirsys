<?php
/**
 * Layout for the Customer Portal (/customer/...): no staff navigation,
 * no CRM links. Same mobile-first shell and design tokens as the public
 * survey pages; once signed in, a short menu of the customer's sections
 * and "Sign out". Language / direction are the organization's.
 *
 * @var \App\Infrastructure\Theme\ThemeLoader $theme
 * @var string $content
 * @var string $organizationName
 * @var bool $customerSignedIn
 * @var array<string, bool> $sections which sections the organization offers (signed in only)
 */

use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Security\Csrf;
use App\Infrastructure\Security\Session;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;

$translator = Locale::get();
$lang = $translator !== null ? $translator->locale() : 'en';
$dir = $translator !== null ? $translator->direction() : 'ltr';
$brand = ($organizationName ?? '') !== '' ? (string) $organizationName : t('customer_portal.title');
$cssFile = $theme->assetsPath() . '/css/app.css';
$css = $theme->assetUrl('css/app.css') . (is_file($cssFile) ? '?v=' . filemtime($cssFile) : '');
$successFlash = Session::getFlash('success');
$errorFlash = Session::getFlash('error');
$sections ??= [];
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" dir="<?= e($dir) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title><?= e($brand) ?> · <?= e(t('customer_portal.title')) ?></title>
    <link rel="stylesheet" href="<?= e($css) ?>">
</head>
<body class="dir-<?= e($dir) ?>">
<?php include __DIR__ . '/partials/icons.php'; ?>
<div class="auth-shell public-shell customer-shell">
    <header class="auth-shell__top customer-shell__top">
        <span class="auth-shell__brand"><?= e($brand) ?></span>
        <?php if (!empty($customerSignedIn)): ?>
            <nav class="customer-nav" aria-label="<?= e(t('customer_portal.title')) ?>">
                <a href="/customer"><?= icon('dashboard') ?><?= e(t('customer_portal.nav.home')) ?></a>
                <a href="/customer/profile"><?= icon('user') ?><?= e(t('customer_portal.nav.profile')) ?></a>
                <?php if (!empty($sections['surveys'])): ?><a href="/customer/surveys"><?= icon('smile') ?><?= e(t('customer_portal.nav.surveys')) ?></a><?php endif; ?>
                <?php if (!empty($sections['invoices'])): ?><a href="/customer/invoices"><?= icon('receipt') ?><?= e(t('customer_portal.nav.invoices')) ?></a><?php endif; ?>
                <?php if (!empty($sections['proformas'])): ?><a href="/customer/proformas"><?= icon('file-text') ?><?= e(t('customer_portal.nav.proformas')) ?></a><?php endif; ?>
                <form method="post" action="/customer/logout" class="inline-form">
                    <?= Csrf::field() ?>
                    <button type="submit" class="link-button"><?= icon('log-out') ?><?= e(t('customer_portal.logout')) ?></button>
                </form>
            </nav>
        <?php endif; ?>
    </header>
    <main class="app-main" id="main" tabindex="-1">
        <?php if ($successFlash !== null): ?><div class="alert alert--success"><?= e($successFlash) ?></div><?php endif; ?>
        <?php if ($errorFlash !== null): ?><div class="alert alert--error"><?= e($errorFlash) ?></div><?php endif; ?>
        <?= $content ?>
    </main>
</div>
</body>
</html>
