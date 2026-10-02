<?php
/** @var array $proformas */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
?>
<section class="card">
    <h1><?= e(t('proformas.title')) ?></h1>
    <p><a href="/finance/proformas/create" class="btn btn--primary"><?= e(t('proformas.new')) ?></a></p>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('proformas.number')) ?></th><th><?= e(t('common.contact')) ?></th><th><?= e(t('wallet.date')) ?></th><th><?= e(t('deals.value')) ?></th><th><?= e(t('deals.status')) ?></th><th><?= e(t('proformas.owner')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($proformas as $p): ?>
            <tr>
                <td><a href="/finance/proformas/<?= e($p['id']) ?>">#<?= e($p['id']) ?></a></td>
                <td><?= e($p['contact_name'] !== null ? trim($p['contact_name']) : '—') ?></td>
                <td class="muted"><?= e($p['issue_date']) ?></td>
                <td><?= e(number_format((float) $p['grand_total'], 0)) ?> <?= e($p['currency_code']) ?></td>
                <td><?= e(t('proformas.status.' . $p['status'])) ?></td>
                <td class="muted"><?= e(trim((string) $p['owner_name'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($proformas === []): ?>
            <tr><td colspan="6" class="muted"><?= e(t('proformas.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
