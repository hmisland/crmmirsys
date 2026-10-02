<?php
/** @var array $pipeline */
/** @var array $stages */
/** @var array $dealsByStage */
/** @var array $totals */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e($pipeline['name']) ?></h1>
    <p>
        <a href="/deals/create" class="btn btn--primary"><?= icon('plus') ?><?= e(t('deals.new')) ?></a>
        <a href="/deals" class="btn"><?= icon('list') ?><?= e(t('deals.list_view')) ?></a>
        <a href="/sales/pipeline" class="btn"><?= icon('settings') ?><?= e(t('deals.manage_pipeline')) ?></a>
    </p>
</section>

<div class="kanban">
    <?php foreach ($stages as $stage): ?>
        <?php $stageDeals = $dealsByStage[$stage['id']] ?? []; ?>
        <div class="kanban__column">
            <h3>
                <?= e($stage['name']) ?>
                <span class="muted">
                    (<?= e($totals[$stage['id']]['count'] ?? 0) ?> ·
                    $<?= e(number_format($totals[$stage['id']]['total'] ?? 0, 0)) ?>)
                </span>
            </h3>
            <?php foreach ($stageDeals as $deal): ?>
                <div class="kanban__card">
                    <a href="/deals/<?= e($deal['id']) ?>"><?= e($deal['name']) ?></a>
                    <div class="muted"><?= e($deal['currency_code']) ?> <?= e(number_format((float) $deal['value'], 0)) ?></div>
                    <form method="post" action="/deals/<?= e($deal['id']) ?>/stage" class="kanban__move">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="return_to" value="/deals/board">
                        <select aria-label="<?= e(t('deals.stage')) ?>" name="stage_id" onchange="this.form.submit()">
                            <?php foreach ($stages as $s): ?>
                                <option value="<?= e($s['id']) ?>" <?= (int) $s['id'] === (int) $stage['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if ($stageDeals === []): ?><p class="muted"><?= e(t('deals.no_deals')) ?></p><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
