<?php
/**
 * Standalone error document (ErrorHandler renders error views without
 * the layout, so this page carries its own <html>/stylesheet). Kept
 * dependency-free on purpose: no database, no session, no current
 * user — an error page must render even when those are what failed.
 *
 * Expects: $theme, $code (string), $heading (string), $body (string).
 */
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Localization\Locale;

$translator = Locale::get();
$lang = $translator !== null ? $translator->locale() : 'en';
$dir = $translator !== null ? $translator->direction() : 'ltr';
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" dir="<?= e($dir) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($code . ' · ' . t('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e($theme->assetUrl('css/app.css')) ?>">
</head>
<body class="dir-<?= e($dir) ?>">
<?php include dirname(__DIR__) . '/partials/icons.php'; ?>
<main class="error-page">
    <section class="card card--error">
        <span class="error-page__code"><?= e($code) ?></span>
        <h1><?= e($heading) ?></h1>
        <p class="muted"><?= e($body) ?></p>
        <p><a href="/" class="btn btn--primary"><?= icon('arrow-left') ?><?= e(t('nav.dashboard')) ?></a></p>
    </section>
</main>
</body>
</html>
