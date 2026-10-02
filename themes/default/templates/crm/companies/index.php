<?php
/** @var array $companies */
/** @var string $q */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
?>
<section class="card">
    <h1><?= e(t('companies.title')) ?></h1>

    <form method="get" action="/companies" class="inline-form-row">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('companies.search_placeholder')) ?>">
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
        <a href="/companies/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('companies.new')) ?></a>
    </form>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('common.name')) ?></th><th><?= e(t('companies.website')) ?></th><th><?= e(t('companies.industry')) ?></th><th><?= e(t('companies.city')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($companies as $company): ?>
            <tr>
                <td><a href="/companies/<?= e($company['id']) ?>"><?= e($company['name']) ?></a></td>
                <td><?= e($company['website'] ?? '—') ?></td>
                <td><?= e($company['industry'] ?? '—') ?></td>
                <td><?= e($company['city'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($companies === []): ?>
            <tr><td colspan="4" class="muted"><?= e(t('companies.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
