<?php
/** @var array{auto_create_on_deal_won: int} $settings */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<div class="page-head">
    <div>
        <h1><?= e(t('contracts.settings_title')) ?></h1>
        <p><?= e(t('contracts.settings_subtitle')) ?></p>
    </div>
    <div class="quick-actions">
        <a href="/contracts/categories" class="btn"><?= icon('tag') ?><?= e(t('contracts.categories_title')) ?></a>
        <a href="/contracts" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('contracts.back_to_list')) ?></a>
    </div>
</div>

<section class="card card--form">
    <form method="post" action="/contracts/settings">
        <?= Csrf::field() ?>

        <label class="check">
            <input type="checkbox" name="auto_create_on_deal_won" value="1" <?= !empty($settings['auto_create_on_deal_won']) ? 'checked' : '' ?>>
            <?= e(t('contracts.setting.auto_create_on_deal_won')) ?>
        </label>
        <p class="field-hint"><?= e(t('contracts.setting.auto_create_on_deal_won_hint')) ?></p>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button>
        </div>
    </form>
</section>
