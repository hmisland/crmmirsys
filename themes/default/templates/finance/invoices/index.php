<?php
/** @var array $invoices */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
?>
<section class="card">
    <h1><?= e(t('invoices.title')) ?></h1>
    <p><a href="/finance/invoices/create" class="btn btn--primary"><?= e(t('invoices.new')) ?></a></p>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('invoices.number')) ?></th><th><?= e(t('common.contact')) ?></th><th><?= e(t('wallet.date')) ?></th><th><?= e(t('deals.value')) ?></th><th><?= e(t('deals.status')) ?></th><th><?= e(t('proformas.owner')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($invoices as $inv): ?>
            <?php $label = $inv['invoice_number'] ?? ('#' . $inv['id'] . ' (' . t('invoices.not_issued_yet') . ')'); ?>
            <tr>
                <td><a href="/finance/invoices/<?= e($inv['id']) ?>"><?= e($label) ?></a></td>
                <td><?= e($inv['contact_name'] !== null ? trim($inv['contact_name']) : '—') ?></td>
                <td class="muted"><?= e($inv['issue_date']) ?></td>
                <td><?= e(number_format((float) $inv['grand_total'], 0)) ?> <?= e($inv['currency_code']) ?></td>
                <td><?= e(t('invoices.status.' . $inv['status'])) ?></td>
                <td class="muted"><?= e(trim((string) $inv['owner_name'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($invoices === []): ?>
            <tr><td colspan="6" class="muted"><?= e(t('invoices.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
