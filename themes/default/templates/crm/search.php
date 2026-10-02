<?php
/** @var string $q */
/** @var array $results */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
?>
<section class="card">
    <h1><?= e(t('nav.search')) ?></h1>
    <form method="get" action="/search" class="inline-form-row">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('search.placeholder')) ?>" autofocus>
        <button type="submit" class="btn btn--primary"><?= e(t('common.search')) ?></button>
    </form>
</section>

<?php if ($q !== ''): ?>
<section class="card">
    <h2><?= e(t('nav.contacts')) ?></h2>
    <ul>
        <?php foreach ($results['contacts'] as $c): ?>
            <li><a href="/contacts/<?= e($c['id']) ?>"><?= e(trim($c['first_name'] . ' ' . ($c['last_name'] ?? ''))) ?></a> <span class="muted"><?= e($c['email'] ?? '') ?></span></li>
        <?php endforeach; ?>
        <?php if ($results['contacts'] === []): ?><li class="muted"><?= e(t('search.no_matches')) ?></li><?php endif; ?>
    </ul>
</section>

<section class="card">
    <h2><?= e(t('nav.companies')) ?></h2>
    <ul>
        <?php foreach ($results['companies'] as $c): ?>
            <li><a href="/companies/<?= e($c['id']) ?>"><?= e($c['name']) ?></a></li>
        <?php endforeach; ?>
        <?php if ($results['companies'] === []): ?><li class="muted"><?= e(t('search.no_matches')) ?></li><?php endif; ?>
    </ul>
</section>

<section class="card">
    <h2><?= e(t('nav.leads')) ?></h2>
    <ul>
        <?php foreach ($results['leads'] as $l): ?>
            <li><a href="/leads/<?= e($l['id']) ?>"><?= e(trim($l['first_name'] . ' ' . ($l['last_name'] ?? ''))) ?></a></li>
        <?php endforeach; ?>
        <?php if ($results['leads'] === []): ?><li class="muted"><?= e(t('search.no_matches')) ?></li><?php endif; ?>
    </ul>
</section>
<?php endif; ?>
