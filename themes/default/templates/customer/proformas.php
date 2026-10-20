<?php
/**
 * Customer Portal → my proformas (sent onwards; drafts are internal).
 *
 * @var list<array> $proformas
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
    <h1><?= e(t('customer_portal.nav.proformas')) ?></h1>
    <?php include __DIR__ . '/../partials/pagination.php'; ?>
    <div class="table-responsive">
    <table class="table">
        <thead><tr>
            <th><?= e(t('customer_portal.doc.number')) ?></th>
            <th><?= e(t('customer_portal.doc.date')) ?></th>
            <th><?= e(t('customer_portal.doc.status')) ?></th>
            <th class="num"><?= e(t('customer_portal.doc.total')) ?></th>
        </tr></thead>
        <tbody>
        <?php if ($proformas === []): ?>
            <tr><td colspan="4" class="muted"><?= e(t('customer_portal.proformas.empty')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($proformas as $p): ?>
            <?php $number = ($p['custom_number'] ?? '') !== '' ? (string) $p['custom_number'] : 'PF-' . str_pad((string) $p['id'], 6, '0', STR_PAD_LEFT); ?>
            <tr>
                <td><a href="/customer/proformas/<?= e($p['id']) ?>"><span class="ltr"><?= e($number) ?></span></a></td>
                <td class="nowrap"><?= e($fmt->date((string) $p['issue_date'])) ?></td>
                <td><?= badge(t('customer_portal.proforma_status.' . $p['status']), (string) $p['status']) ?></td>
                <td class="num"><?= e(money((string) $p['grand_total'], (string) $p['currency_code'], $fmt)) ?> <span class="muted"><?= e((string) $p['currency_code']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
