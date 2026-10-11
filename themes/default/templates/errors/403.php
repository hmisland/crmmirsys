<?php
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;

// Only reached when the denied page can't send the user back somewhere
// (no session, or the dashboard itself was denied) — see
// ErrorHandler::forbiddenRedirect(). Normally a 403 shows as a popup.
$code = '403';
$heading = t('errors.forbidden_title');
$body = t('errors.forbidden_text');
include __DIR__ . '/_page.php';
