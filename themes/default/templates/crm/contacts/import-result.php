<?php
/** @var array $result */
/** @var array|null $latestBatch */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
?>
<section class="card">
    <h1><?= e(t('import.complete')) ?></h1>

    <p>
        <strong><?= e($result['created']) ?></strong> <?= e(t('import.created')) ?>,
        <strong><?= e($result['updated']) ?></strong> <?= e(t('import.updated')) ?>,
        <strong><?= e($result['skipped']) ?></strong> <?= e(t('import.skipped')) ?>.
    </p>

    <?php if ($result['errors'] !== []): ?>
        <div class="alert alert--error">
            <?php foreach ($result['errors'] as $err): ?>
                <div>
                    <?= e(t('import.row')) ?> <?= e($err['row']) ?>:
                    <?= e(implode(' ', array_map(fn ($code) => t('import.row_error.' . $code), $err['codes']))) ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p class="actions">
        <a href="/contacts" class="btn btn--primary"><?= icon('users') ?><?= e(t('import.go_to_contacts')) ?></a>
        <a href="/contacts/import" class="btn"><?= icon('upload') ?><?= e(t('import.import_another')) ?></a>
    </p>
</section>

<?php if ($latestBatch !== null): ?>
    <?php include __DIR__ . '/_import-batch.php'; ?>
<?php endif; ?>
