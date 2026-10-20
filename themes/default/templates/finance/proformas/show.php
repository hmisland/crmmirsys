<?php
/** @var array $proforma */
/** @var array $items */
/** @var array|null $contact */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\help_link;
use App\Infrastructure\Security\Csrf;

// Stage 5.2 (L2/L3): amounts at the proforma currency's display precision.
$proformaCurrency = \App\Infrastructure\Finance\CurrencyPrecision::currency((int) $proforma['currency_id'])['code'] ?? null;
?>
<section class="card">
    <h1><?= e(t('proformas.title_single')) ?> <?= !empty($proforma['custom_number']) ? e($proforma['custom_number']) : '#' . e($proforma['id']) ?></h1>
    <p class="muted">
        <?= e(t('wallet.date')) ?>: <?= e($proforma['issue_date']) ?>
        <?= $proforma['expiry_date'] ? ' · ' . e(t('proformas.expires')) . ' ' . e($proforma['expiry_date']) : '' ?>
        · <?= e(t('deals.status')) ?>: <?= badge(t('proformas.status.' . $proforma['status']), $proforma['status']) ?>
    </p>
    <?php if (($contractLink ?? null) !== null): ?>
        <p><?= e(t('finance.contract')) ?>: <?php if ($contractLink['url'] !== null): ?><a href="<?= e($contractLink['url']) ?>"><?= e($contractLink['label']) ?></a><?php else: ?><?= e($contractLink['label']) ?><?php endif; ?></p>
    <?php endif; ?>
    <?php if ($contact !== null): ?>
        <p><?= e(t('common.contact')) ?>: <a href="/contacts/<?= e($contact['id']) ?>"><?= e(trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''))) ?></a></p>
    <?php endif; ?>

    <?php if ($proforma['converted_invoice_id'] !== null): ?>
        <div class="alert alert--success">
            <?= e(t('proformas.already_converted')) ?>
            <a href="/finance/invoices/<?= e($proforma['converted_invoice_id']) ?>"><?= e(t('proformas.view_invoice')) ?></a>
        </div>
    <?php endif; ?>

    <div class="actions">
        <a href="/finance/proformas/<?= e($proforma['id']) ?>/print" class="btn" target="_blank" rel="noopener"><?= icon('file-text') ?><?= e(t('print.print_view')) ?></a>
        <a href="/finance/proformas/<?= e($proforma['id']) ?>/pdf" class="btn"><?= icon('download') ?><?= e(t('print.download_pdf')) ?></a>
        <?php if ($proforma['converted_invoice_id'] === null): ?>
            <form method="post" action="/finance/proformas/<?= e($proforma['id']) ?>/convert" class="inline-form">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--primary"><?= icon('arrow-left-right') ?><?= e(t('proformas.convert_to_invoice')) ?></button>
            </form>
            <a href="/finance/proformas/<?= e($proforma['id']) ?>/edit" class="btn"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
            <form method="post" action="/finance/proformas/<?= e($proforma['id']) ?>/delete" class="inline-form">
                <?= Csrf::field() ?>
                <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <h2><?= e(t('proformas.line_items')) ?><?= help_link('finance-amounts-discounts-tax') ?></h2>
    <?php $rowsCurrency = $proformaCurrency; include __DIR__ . '/../_document-rows.php'; ?>

    <table class="table table--compact" style="max-width:320px;margin-inline-start:auto;margin-top:1rem">
        <tr><td><?= e(t('proformas.subtotal')) ?></td><td><?= e(\App\Infrastructure\Theme\money($proforma['subtotal'], $proformaCurrency)) ?></td></tr>
        <tr><td><?= e(t('proformas.invoice_discount')) ?></td><td><?= e(\App\Infrastructure\Theme\money($proforma['invoice_discount'], $proformaCurrency)) ?></td></tr>
        <tr><td><?= e(t('proformas.tax_total')) ?></td><td><?= e(\App\Infrastructure\Theme\money($proforma['tax_total'], $proformaCurrency)) ?></td></tr>
        <tr><td><strong><?= e(t('proformas.grand_total')) ?></strong></td><td><strong><?= e(\App\Infrastructure\Theme\money($proforma['grand_total'], $proformaCurrency)) ?></strong></td></tr>
    </table>

    <?php if (!empty($proforma['terms']) || !empty($proforma['notes'])): ?>
        <div style="margin-top:1rem">
            <?php if (!empty($proforma['terms'])): ?><p><strong><?= e(t('proformas.terms')) ?>:</strong> <?= nl2br(e($proforma['terms'])) ?></p><?php endif; ?>
            <?php if (!empty($proforma['notes'])): ?><p><strong><?= e(t('common.notes')) ?>:</strong> <?= nl2br(e($proforma['notes'])) ?></p><?php endif; ?>
        </div>
    <?php endif; ?>
</section>
