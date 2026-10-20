<?php
/**
 * Customer Portal → my invoices (issued and cancelled; drafts are internal).
 *
 * @var list<array> $invoices
 * @var \App\Infrastructure\Pagination\Pagination $pagination
 */

use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Localization\LocaleFormatter;

use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\money;
use function App\Infrastructure\Theme\t;

$fmt = new LocaleFormatter(Locale::get()?->locale() ?? 'en');
?>
<section class="card">
    <h1><?= e(t('customer_portal.nav.invoices')) ?></h1>
    <?php include __DIR__ . '/../partials/pagination.php'; ?>
    <div class="table-responsive">
    <table class="table">
        <thead><tr>
            <th><?= e(t('customer_portal.doc.number')) ?></th>
            <th><?= e(t('customer_portal.doc.date')) ?></th>
            <th><?= e(t('customer_portal.doc.status')) ?></th>
            <th class="num"><?= e(t('customer_portal.doc.total')) ?></th>
            <th class="num"><?= e(t('customer_portal.doc.outstanding')) ?></th>
        </tr></thead>
        <tbody>
        <?php if ($invoices === []): ?>
            <tr><td colspan="5" class="muted"><?= e(t('customer_portal.invoices.empty')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($invoices as $i): ?>
            <?php
            $number = ($i['custom_number'] ?? '') !== '' ? (string) $i['custom_number'] : (string) ($i['invoice_number'] ?? ('#' . $i['id']));
            $outstanding = $i['status'] === 'issued' ? bcsub(bcsub((string) $i['grand_total'], (string) $i['credited_total'], 18), (string) $i['paid_total'], 18) : '0';
            ?>
            <tr>
                <td><a href="/customer/invoices/<?= e($i['id']) ?>"><span class="ltr"><?= e($number) ?></span></a></td>
                <td class="nowrap"><?= e($fmt->date((string) $i['issue_date'])) ?></td>
                <td><?= badge(t('customer_portal.invoice_status.' . $i['status']), (string) $i['status']) ?></td>
                <td class="num"><?= e(money((string) $i['grand_total'], (string) $i['currency_code'], $fmt)) ?> <span class="muted"><?= e((string) $i['currency_code']) ?></span></td>
                <td class="num"><?= e(money(bccomp($outstanding, '0', 18) > 0 ? $outstanding : '0', (string) $i['currency_code'], $fmt)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
