<?php
/** @var array $rates */
/** @var array $currencies */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(t('exchange_rates.title')) ?></h1>
    <p class="muted"><?= e(t('exchange_rates.hint')) ?></p>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('exchange_rates.pair')) ?></th><th><?= e(t('exchange_rates.rate')) ?></th><th><?= e(t('exchange_rates.source')) ?></th><th><?= e(t('exchange_rates.set_by')) ?></th><th><?= e(t('exchange_rates.effective_at')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($rates as $r): ?>
            <tr>
                <td>1 <?= e($r['source_code']) ?> = <?= e($r['destination_code']) ?></td>
                <td><?= e(number_format((float) $r['rate'], 0)) ?></td>
                <td><?= e($r['rate_source']) ?></td>
                <td><?= e($r['created_by_name'] ?? '—') ?></td>
                <td class="muted"><?= e($r['effective_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rates === []): ?>
            <tr><td colspan="5" class="muted"><?= e(t('exchange_rates.none_yet')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="card card--form">
    <h2><?= e(t('exchange_rates.set_a_rate')) ?></h2>
    <form method="post" action="/admin/exchange-rates">
        <?= Csrf::field() ?>

        <label for="source_currency_id"><?= e(t('exchange_rates.one_unit_of')) ?></label>
        <select id="source_currency_id" name="source_currency_id" required>
            <?php foreach ($currencies as $c): ?>
                <option value="<?= e($c['id']) ?>"><?= e($c['name']) ?> (<?= e($c['code']) ?>)</option>
            <?php endforeach; ?>
        </select>

        <label for="destination_currency_id"><?= e(t('exchange_rates.equals')) ?></label>
        <select id="destination_currency_id" name="destination_currency_id" required>
            <?php foreach ($currencies as $c): ?>
                <option value="<?= e($c['id']) ?>"><?= e($c['name']) ?> (<?= e($c['code']) ?>)</option>
            <?php endforeach; ?>
        </select>

        <label for="rate"><?= e(t('exchange_rates.rate')) ?></label>
        <input type="number" id="rate" name="rate" step="0.000001" min="0.000001" required>

        <button type="submit" class="btn btn--primary"><?= e(t('exchange_rates.save_rate')) ?></button>
    </form>
</section>
