<?php
/**
 * Browser print view: an A4 sheet with a toolbar (print, PDF download,
 * back). Standalone document (no app layout), so nothing but the
 * invoice ends up on paper. The sheet content is the same HTML the PDF
 * is made from.
 *
 * @var array $doc
 * @var string $documentHtml
 * @var string $stylesHtml
 * @var string $backUrl
 * @var string $pdfUrl
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Localization\Locale;

$translator = Locale::get();
$lang = $translator !== null ? $translator->locale() : 'en';
$dir = $translator !== null ? $translator->direction() : 'ltr';
$isInvoice = $doc['type'] === 'invoice';
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" dir="<?= e($dir) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e(t($isInvoice ? 'print.invoice_title' : 'print.proforma_title') . ' ' . $doc['number']) ?></title>
    <?= $stylesHtml ?>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/partials/icons.php'; ?>
<div class="doc-toolbar">
    <button type="button" class="primary" data-print><?= icon('file-text') ?><?= e(t('print.print')) ?></button>
    <a href="<?= e($pdfUrl) ?>"><?= icon('download') ?><?= e(t('print.download_pdf')) ?></a>
    <a href="<?= e($backUrl) ?>"><?= icon('arrow-left') ?><?= e(t($isInvoice ? 'print.back_invoice' : 'print.back_proforma')) ?></a>
</div>
<main class="doc-sheet">
    <?= $documentHtml ?>
</main>
<script>
    document.querySelector('[data-print]').addEventListener('click', function () { window.print(); });
</script>
</body>
</html>
