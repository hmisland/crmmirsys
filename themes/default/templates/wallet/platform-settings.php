<?php
/** @var array $settings */
/** @var array $currencies */
/** @var array $banks */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;
?>
<section class="card card--form">
    <h1><?= e(t('wallet.platform_settings_title')) ?></h1>
    <p class="muted"><?= e(t('wallet.platform_settings_hint')) ?></p>

    <form method="post" action="/admin/wallet/settings">
        <?= Csrf::field() ?>

        <label for="currency_id"><?= e(t('wallet.currency')) ?></label>
        <select id="currency_id" name="currency_id">
            <?php foreach ($currencies as $c): ?>
                <option value="<?= e($c['id']) ?>" <?= (int) $c['id'] === (int) $settings['currency_id'] ? 'selected' : '' ?>>
                    <?= e($c['name']) ?> (<?= e($c['code']) ?>)
                </option>
            <?php endforeach; ?>
        </select>

        <label for="min_topup"><?= e(t('wallet.min_topup')) ?></label>
        <input type="number" id="min_topup" name="min_topup" value="<?= e($settings['min_topup']) ?>" required>

        <label for="max_topup"><?= e(t('wallet.max_topup')) ?></label>
        <input type="number" id="max_topup" name="max_topup" value="<?= e($settings['max_topup']) ?>" required>

        <button type="submit" class="btn btn--primary"><?= e(t('common.save')) ?></button>
    </form>
</section>

<section class="card">
    <h2><?= e(t('wallet.banks')) ?></h2>
    <p class="muted"><?= e(t('wallet.banks_hint')) ?></p>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('common.name')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($banks as $bank): ?>
            <tr>
                <td><?= e($bank['name']) ?></td>
                <td>
                    <form method="post" action="/admin/wallet/banks/<?= e($bank['id']) ?>/deactivate" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button"><?= e(t('common.remove')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($banks === []): ?>
            <tr><td colspan="2" class="muted"><?= e(t('wallet.no_banks_yet')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="card card--form">
    <h2><?= e(t('wallet.add_a_bank')) ?></h2>
    <form method="post" action="/admin/wallet/banks">
        <?= Csrf::field() ?>
        <label for="name"><?= e(t('common.name')) ?></label>
        <input type="text" id="name" name="name" required>
        <button type="submit" class="btn btn--primary"><?= e(t('wallet.add')) ?></button>
    </form>
</section>
