<?php
/**
 * Styles for the printable invoice/proforma (finance/print/document).
 * Used by the browser print view AND by the PDF engine (mPDF), so:
 *  - tables only, no CSS grid/flex (mPDF doesn't support them);
 *  - literal colors instead of app.css tokens — mPDF has no CSS custom
 *    properties. This is the one deliberate exception to the "tokens
 *    only" rule; the values mirror the design-system tokens
 *    (gray-900/500/300/50, brand-700/50, warning-50/200/700).
 * @var bool $forPdf
 */
?>
<style>
    .doc { color: #101828; font-size: 9.5pt; line-height: 1.45; }
    .doc table { border-collapse: collapse; width: 100%; }
    .doc td, .doc th { vertical-align: top; }
    .doc-head td { vertical-align: middle; padding-bottom: 7pt; border-bottom: 1.5pt solid #101828; }
    .doc-logo { width: 46pt; height: 46pt; }
    .doc-logo-box { width: 46pt; height: 46pt; background: #eef3ff; color: #2449b0; text-align: center; font-weight: bold; font-size: 9pt; }
    .doc-title { text-align: center; width: 40%; }
    .doc-t1 { font-size: 15pt; font-weight: bold; }
    .doc-t2 { font-size: 8.5pt; color: #667085; }
    .doc-meta td { padding: 1pt 4pt; border: 0; font-size: 9pt; white-space: nowrap; }
    .doc-meta .k { color: #667085; }
    .doc-meta .v { font-weight: bold; }
    .doc-status { color: #93370d; background: #fffaeb; border: 0.6pt solid #fedf89; padding: 0 5pt; font-size: 8.5pt; }
    .doc-party { margin-top: 8pt; border: 0.6pt solid #d0d5dd; }
    .doc-party .hd { background: #f6f7f9; font-weight: bold; padding: 4pt 8pt; border-bottom: 0.6pt solid #d0d5dd; }
    .doc-party td { padding: 3pt 8pt; width: 33.33%; }
    .doc-party .k { color: #667085; }
    .doc-items { margin-top: 10pt; }
    .doc-items th, .doc-items td { border: 0.6pt solid #d0d5dd; padding: 4pt 5pt; text-align: center; }
    .doc-items th { background: #f6f7f9; font-size: 8.5pt; }
    .doc-items td.desc { text-align: start; }
    .doc-items td.desc .desc-note { color: #667085; font-size: 0.9em; margin-top: 1pt; }
    .doc-items td.code { white-space: nowrap; direction: ltr; }
    .doc-items td.num { white-space: nowrap; }
    .doc-bottom { margin-top: 8pt; }
    .w30 { width: 30%; }
    .w40 { width: 40%; }
    .col-main { width: 58%; padding: 0 6pt; }
    .col-totals { width: 42%; }
    .doc-words td { border: 0.6pt dashed #d0d5dd; padding: 6pt 8pt; }
    .doc-words-k { font-weight: bold; }
    .doc-notes { margin-top: 6pt; font-size: 9pt; }
    .doc-notes .k { color: #667085; }
    .doc-totals td { border: 0.6pt solid #d0d5dd; padding: 4pt 6pt; }
    .doc-totals td.v { text-align: end; font-weight: bold; white-space: nowrap; }
    .doc-totals tr.grand td { background: #101828; color: #ffffff; font-size: 10.5pt; border-color: #101828; }
    .doc-sign { margin-top: 34pt; }
    .doc-sign td { width: 50%; text-align: center; color: #667085; padding: 5pt 10pt 44pt; border-top: 0.8pt solid #101828; }
    .doc-sign td.gap { width: 6%; border: 0; padding: 0; }
    .doc-foot { margin-top: 8pt; text-align: center; color: #667085; font-size: 8pt; }
    .ltr { direction: ltr; unicode-bidi: embed; }
<?php if (!$forPdf): ?>
    @font-face{font-family:"Vazirmatn";src:url("/assets/themes/default/fonts/vazirmatn/Vazirmatn-Variable.woff2") format("woff2");font-weight:100 900;font-style:normal;font-display:swap}
    :root { color-scheme: light; }
    body { margin: 0; background: #e9ecf1; font-family: "Vazirmatn", "Segoe UI", Tahoma, "Noto Sans Arabic", sans-serif; }
    .doc { font-size: 12.5px; }
    .doc-toolbar { position: sticky; top: 0; z-index: 2; display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; padding: 10px 16px; background: #ffffff; border-bottom: 1px solid #d0d5dd; }
    .doc-toolbar a, .doc-toolbar button { display: inline-flex; align-items: center; gap: 6px; font: inherit; font-size: 14px; padding: 7px 14px; border-radius: 8px; border: 1px solid #d0d5dd; background: #ffffff; color: #101828; text-decoration: none; cursor: pointer; }
    .doc-toolbar .primary { background: #2f5fd6; border-color: #2f5fd6; color: #ffffff; }
    .doc-toolbar svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .doc-sheet { width: 210mm; max-width: calc(100% - 32px); min-height: 297mm; margin: 16px auto; background: #ffffff; padding: 12mm; box-sizing: border-box; box-shadow: 0 4px 18px rgba(16, 24, 40, 0.12); }
    .doc-status { border-radius: 99px; }
    .doc-party { border-radius: 8px; }
    .doc-words td { border-radius: 8px; }
    .doc-items td.desc { unicode-bidi: plaintext; }
    @page { size: A4; margin: 0; }
    @media print {
        body { background: #ffffff; }
        .doc-toolbar { display: none; }
        .doc-sheet { margin: 0; width: auto; max-width: none; min-height: 0; box-shadow: none; }
    }
<?php endif; ?>
</style>
