<?php
/** @var string|null $message */
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;

$code = '500';
$heading = t('errors.something_went_wrong');
$body = $message ?? t('errors.try_again');
include __DIR__ . '/_page.php';
