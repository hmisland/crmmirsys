<?php
/** @var array $pipelines */
/** @var array $pipeline */
/** @var array $stages */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e($pipeline['name']) ?> — <?= e(t('deals.stages')) ?></h1>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('deals.order')) ?></th><th><?= e(t('common.name')) ?></th><th><?= e(t('deals.probability')) ?></th><th><?= e(t('deals.status.won')) ?>?</th><th><?= e(t('deals.status.lost')) ?>?</th></tr></thead>
        <tbody>
        <?php foreach ($stages as $stage): ?>
            <tr>
                <td><?= e($stage['position']) ?></td>
                <td><?= e($stage['name']) ?></td>
                <td><?= e($stage['probability']) ?>%</td>
                <td><?= $stage['is_won'] ? e(t('common.yes')) : '' ?></td>
                <td><?= $stage['is_lost'] ? e(t('common.yes')) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="card card--form">
    <h2><?= e(t('deals.add_stage')) ?></h2>
    <form method="post" action="/sales/pipeline/stages">
        <?= Csrf::field() ?>
        <input type="hidden" name="pipeline_id" value="<?= e($pipeline['id']) ?>">

        <label for="name"><?= e(t('deals.stage_name')) ?></label>
        <input type="text" id="name" name="name" required>

        <label for="probability"><?= e(t('deals.win_probability')) ?></label>
        <input type="number" id="probability" name="probability" min="0" max="100" value="0">

        <label><input type="checkbox" name="is_won" value="1"> <?= e(t('deals.stage_means_won')) ?></label>
        <label><input type="checkbox" name="is_lost" value="1"> <?= e(t('deals.stage_means_lost')) ?></label>

        <button type="submit" class="btn btn--primary"><?= e(t('deals.add_stage')) ?></button>
    </form>
</section>
