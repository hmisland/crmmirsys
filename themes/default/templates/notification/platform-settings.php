<?php
/** @var array $settings */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;
?>
<section class="card card--form">
    <h1><?= e(t('notif.pricing_title')) ?></h1>
    <p class="muted"><?= e(t('notif.pricing_hint')) ?></p>

    <form method="post" action="/admin/notifications/settings">
        <?= Csrf::field() ?>

        <label for="bale_cost"><?= e(t('notif.bale_cost')) ?></label>
        <input type="number" id="bale_cost" name="bale_cost" step="0.01" value="<?= e($settings['bale_cost']) ?>" required>
        <div class="field-hint"><?= e(t('notif.bale_cost_hint')) ?></div>

        <label for="sms_cost"><?= e(t('notif.sms_cost')) ?></label>
        <input type="number" id="sms_cost" name="sms_cost" step="0.01" value="<?= e($settings['sms_cost']) ?>" required>
        <div class="field-hint"><?= e(t('notif.sms_cost_hint')) ?></div>

        <button type="submit" class="btn btn--primary"><?= e(t('common.save')) ?></button>
    </form>
</section>
