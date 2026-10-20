<?php
/**
 * CSV for the tax system: what may not match the tax system. The file is
 * still built when the user confirms ("build anyway").
 *
 * @var list<array{invoice: string, message: string}> $problems
 * @var int[] $ids
 * @var bool $canBuild
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;

$query = http_build_query(['ids' => $ids, 'confirm' => '1']);
?>
<section class="card">
    <h1><?= icon('alert-triangle') ?><?= e(t('tax_export.check_title')) ?></h1>
    <p><?= e(t($canBuild ? 'tax_export.check_intro' : 'tax_export.nothing_to_build')) ?></p>
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('invoices.number')) ?></th><th><?= e(t('tax_export.problem')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($problems as $p): ?>
            <tr><td><span class="ltr"><?= e($p['invoice']) ?></span></td><td><?= e($p['message']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="actions">
        <?php if ($canBuild): ?>
            <a href="/finance/invoices/tax-export/download?<?= e($query) ?>" class="btn btn--primary"><?= icon('download') ?><?= e(t('tax_export.build_anyway')) ?></a>
        <?php endif; ?>
        <a href="/finance/invoices/tax-export" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('tax_export.back_to_list')) ?></a>
    </div>
</section>
