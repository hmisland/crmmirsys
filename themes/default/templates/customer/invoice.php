<?php
/** @var array $invoice */

use App\Infrastructure\Localization\Locale;

$doc = $invoice;
$kind = 'invoice';
$number = ($invoice['custom_number'] ?? '') !== '' ? (string) $invoice['custom_number'] : (string) ($invoice['invoice_number'] ?? ('#' . $invoice['id']));
$lang = Locale::get()?->locale() ?? 'en';
include __DIR__ . '/_document.php';
