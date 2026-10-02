<?php
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;

$code = '404';
$heading = t('errors.not_found');
$body = '';
include __DIR__ . '/_page.php';
