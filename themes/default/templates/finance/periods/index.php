<?php
/** @var array $periods */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(t('periods.title')) ?></h1>
    <p class="muted"><?= e(t('periods.hint')) ?></p>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('periods.starts_on')) ?></th><th><?= e(t('periods.ends_on')) ?></th><th><?= e(t('deals.status')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($periods as $p): ?>
            <tr>
                <td><?= e($p['starts_on']) ?></td>
                <td><?= e($p['ends_on']) ?></td>
                <td><?= badge(t('periods.status.' . $p['status']), $p['status']) ?></td>
                <td>
                    <?php if ($p['status'] === 'open'): ?>
                        <form method="post" action="/finance/periods/<?= e($p['id']) ?>/close" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button"><?= icon('lock') ?><?= e(t('periods.close_action')) ?></button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="/finance/periods/<?= e($p['id']) ?>/reopen" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button"><?= icon('unlock') ?><?= e(t('periods.reopen_action')) ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($periods === []): ?>
            <tr><td colspan="4" class="muted"><?= e(t('periods.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="card card--form">
    <h2><?= e(t('periods.add_period')) ?></h2>
    <form method="post" action="/finance/periods">
        <?= Csrf::field() ?>

        <label for="starts_on"><?= e(t('periods.starts_on')) ?></label>
        <input type="text" class="crm-datepicker" id="starts_on" name="starts_on" required>

        <label for="ends_on"><?= e(t('periods.ends_on')) ?></label>
        <input type="text" class="crm-datepicker" id="ends_on" name="ends_on" required>

        <button type="submit" class="btn btn--primary"><?= icon('plus') ?><?= e(t('periods.add_period')) ?></button>
    </form>
</section>
