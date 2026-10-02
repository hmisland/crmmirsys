<?php
/** @var string|null $error */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<section class="card card--form" style="max-width:600px">
    <h1><?= e(t('import.title')) ?></h1>

    <?php if ($error !== null): ?>
        <div class="alert alert--error"><?= e($error) ?></div>
    <?php endif; ?>

    <p class="muted">
        <?= e(t('import.hint_intro')) ?>
        <a href="/org/custom-fields"><?= e(t('org.custom_fields')) ?></a><?= e(t('import.hint_middle')) ?>
        <?= e(t('import.hint_dedupe')) ?>
        <?= e(t('import.hint_review')) ?>
    </p>

    <form method="post" action="/contacts/import/preview" enctype="multipart/form-data">
        <?= Csrf::field() ?>

        <label for="file"><?= e(t('import.csv_file')) ?></label>
        <input type="file" id="file" name="file" accept=".csv,text/csv" required>

        <button type="submit" class="btn btn--primary"><?= icon('upload') ?><?= e(t('import.upload_and_preview')) ?></button>
    </form>
</section>
