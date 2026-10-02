<?php
/** @var array $result */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
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
                <div><?= e($err) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p>
        <a href="/contacts" class="btn btn--primary"><?= e(t('import.go_to_contacts')) ?></a>
        <a href="/contacts/import" class="btn"><?= e(t('import.import_another')) ?></a>
    </p>
</section>
