<?php
/** @var array $proforma */

use App\Infrastructure\Localization\Locale;

$doc = $proforma;
$kind = 'proforma';
$number = ($proforma['custom_number'] ?? '') !== '' ? (string) $proforma['custom_number'] : 'PF-' . str_pad((string) $proforma['id'], 6, '0', STR_PAD_LEFT);
$lang = Locale::get()?->locale() ?? 'en';
include __DIR__ . '/_document.php';
