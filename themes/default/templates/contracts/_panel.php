<?php
/**
 * Body of the "Contracts" panel on a contact or company page
 * (Modules\Contracts\ContractPanels). The heading and card come from
 * partials/related-panels.php.
 *
 * @var array $contracts up to ContractPanels::LIMIT, newest first, already in the user's scope
 * @var int $total
 * @var bool $canCreate
 * @var string $createUrl
 * @var string $listUrl '' = no "view all" link
 * @var string $emptyKey translation key for the empty row
 * @var \App\Infrastructure\Localization\LocaleFormatter $fmt
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;

include __DIR__ . '/_helpers.php';
?>
<?php if ($canCreate || ($total > count($contracts) && $listUrl !== '')): ?>
<div class="actions">
    <?php if ($canCreate): ?>
        <a href="<?= e($createUrl) ?>" class="btn btn--sm"><?= icon('plus') ?><?= e(t('contracts.new')) ?></a>
    <?php endif; ?>
    <?php if ($total > count($contracts) && $listUrl !== ''): ?>
        <a href="<?= e($listUrl) ?>" class="btn btn--sm btn--ghost"><?= icon('list') ?><?= e(t('contracts.view_all', ['count' => $fmt->number((string) $total)])) ?></a>
    <?php endif; ?>
</div>
<?php endif; ?>
<div class="table-responsive">
<table class="table table--compact">
    <thead><tr>
        <th><?= e(t('contracts.title_field')) ?></th>
        <th><?= e(t('contracts.status')) ?></th>
        <th><?= e(t('contracts.period')) ?></th>
        <th class="num"><?= e(t('contracts.value')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($contracts as $c): ?>
        <tr>
            <td>
                <a href="/contracts/<?= e($c['id']) ?>"><?= e($c['title']) ?></a>
                <?php if (!empty($c['contract_number'])): ?><div class="muted text-sm"><span class="ltr"><?= e($c['contract_number']) ?></span></div><?php endif; ?>
            </td>
            <td><?= badge(t('contracts.status.' . $c['effective_status']), $contractTone($c['effective_status'])) ?></td>
            <td class="nowrap"><?= e($fmt->date($c['start_date'])) ?> – <?= e($fmt->date($c['end_date'])) ?></td>
            <td class="num"><?= e($contractValue($fmt, $c['value'] ?? null, $c['currency_code'] ?? null)) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($contracts === []): ?>
        <tr><td colspan="4" class="muted"><?= e(t($emptyKey ?? 'contracts.panel_empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
