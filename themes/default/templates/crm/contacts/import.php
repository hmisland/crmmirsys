<?php
/** @var string|null $error */
/** @var array|null $latestBatch */
/** @var array|null $rollbackReport */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;
use App\Infrastructure\Security\Csrf;

$rollbackReport = $rollbackReport ?? null;
?>
<?php if ($rollbackReport !== null): ?>
<section class="card">
    <h2><?= e(t('import.rollback_done')) ?></h2>
    <div class="alert alert--success">
        <?= e(t('import.rollback_summary', [
            'deleted' => $rollbackReport['deleted'],
            'restored' => $rollbackReport['restored'],
            'companies' => $rollbackReport['companies_deleted'],
        ])) ?>
    </div>
    <?php if ($rollbackReport['kept'] !== []): ?>
        <div class="alert alert--warning">
            <p><?= e(t('import.rollback_kept_intro', ['count' => count($rollbackReport['kept'])])) ?></p>
            <ul>
                <?php foreach ($rollbackReport['kept'] as $kept): ?>
                    <li><a href="/contacts/<?= e($kept['id']) ?>"><?= e($kept['name']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="card card--form">
    <h1><?= e(t('import.title')) ?></h1>

    <?php if ($error !== null): ?>
        <div class="alert alert--error"><?= e($error) ?></div>
    <?php endif; ?>

    <p class="muted">
        <?= e(t('import.hint_mapping')) ?>
        <?= e(t('import.hint_dedupe')) ?>
    </p>

    <form method="post" action="/contacts/import/preview" enctype="multipart/form-data">
        <?= Csrf::field() ?>

        <label for="file"><?= e(t('import.csv_file')) ?></label>
        <input type="file" id="file" name="file" accept=".csv,text/csv" required>

        <button type="submit" class="btn btn--primary"><?= icon('upload') ?><?= e(t('import.upload_and_preview')) ?></button>
    </form>
</section>

<?php if ($latestBatch !== null): ?>
    <?php include __DIR__ . '/_import-batch.php'; ?>
<?php endif; ?>
