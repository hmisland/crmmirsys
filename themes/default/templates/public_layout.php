<?php
/**
 * Layout for public customer-facing pages (/p/{token}): no login, no
 * navigation, no CRM links. Mobile-first single column, language and
 * direction from the link (see PublicLinkController), same design
 * tokens as the app (auth-shell styles).
 *
 * @var \App\Infrastructure\Theme\ThemeLoader $theme
 * @var string $content
 * @var string|null $publicTitle optional page title
 * @var string|null $organizationName optional, shown as the page brand
 */

use App\Infrastructure\Localization\Locale;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;

$translator = Locale::get();
$lang = $translator !== null ? $translator->locale() : 'en';
$dir = $translator !== null ? $translator->direction() : 'ltr';
$brand = isset($organizationName) && $organizationName !== '' ? (string) $organizationName : t('public.brand_fallback');
$cssFile = $theme->assetsPath() . '/css/app.css';
$css = $theme->assetUrl('css/app.css') . (is_file($cssFile) ? '?v=' . filemtime($cssFile) : '');
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" dir="<?= e($dir) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title><?= e(($publicTitle ?? null) ?: $brand) ?></title>
    <link rel="stylesheet" href="<?= e($css) ?>">
    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
<link rel="icon" type="image/svg+xml" href="/favicon.svg" />
<link rel="shortcut icon" href="/favicon.ico" />
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
<link rel="manifest" href="/site.webmanifest" />
</head>
<body class="dir-<?= e($dir) ?>">
<?php include __DIR__ . '/partials/icons.php'; ?>
<div class="auth-shell public-shell">
    <header class="auth-shell__top">
        <span class="auth-shell__brand"><?= e($brand) ?></span>
    </header>
    <main class="app-main" id="main" tabindex="-1">
        <?= $content ?>
    </main>
</div>
</body>
</html>
