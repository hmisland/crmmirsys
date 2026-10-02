<?php
/** @var array $proforma */
/** @var array $items */
/** @var array|null $contact */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(t('proformas.title_single')) ?> #<?= e($proforma['id']) ?></h1>
    <p class="muted">
        <?= e(t('wallet.date')) ?>: <?= e($proforma['issue_date']) ?>
        <?= $proforma['expiry_date'] ? ' · ' . e(t('proformas.expires')) . ' ' . e($proforma['expiry_date']) : '' ?>
        · <?= e(t('deals.status')) ?>: <?= badge(t('proformas.status.' . $proforma['status']), $proforma['status']) ?>
    </p>
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
    <h2><?= e(t('proformas.line_items')) ?></h2>
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('contacts.description')) ?></th><th><?= e(t('proformas.qty')) ?></th><th><?= e(t('proformas.unit_price')) ?></th><th><?= e(t('proformas.line_total')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><?= e($item['description']) ?></td>
                <td><?= e(rtrim(rtrim((string) $item['quantity'], '0'), '.')) ?></td>
                <td><?= e(number_format((float) $item['unit_price'], 0)) ?></td>
                <td><?= e(number_format((float) $item['line_total'], 0)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <table class="table table--compact" style="max-width:320px;margin-inline-start:auto;margin-top:1rem">
        <tr><td><?= e(t('proformas.subtotal')) ?></td><td><?= e(number_format((float) $proforma['subtotal'], 0)) ?></td></tr>
        <tr><td><?= e(t('proformas.invoice_discount')) ?></td><td><?= e(number_format((float) $proforma['invoice_discount'], 0)) ?></td></tr>
        <tr><td><?= e(t('proformas.tax_total')) ?></td><td><?= e(number_format((float) $proforma['tax_total'], 0)) ?></td></tr>
        <tr><td><strong><?= e(t('proformas.grand_total')) ?></strong></td><td><strong><?= e(number_format((float) $proforma['grand_total'], 0)) ?></strong></td></tr>
    </table>

    <?php if (!empty($proforma['terms']) || !empty($proforma['notes'])): ?>
        <div style="margin-top:1rem">
            <?php if (!empty($proforma['terms'])): ?><p><strong><?= e(t('proformas.terms')) ?>:</strong> <?= nl2br(e($proforma['terms'])) ?></p><?php endif; ?>
            <?php if (!empty($proforma['notes'])): ?><p><strong><?= e(t('common.notes')) ?>:</strong> <?= nl2br(e($proforma['notes'])) ?></p><?php endif; ?>
        </div>
    <?php endif; ?>
</section>
