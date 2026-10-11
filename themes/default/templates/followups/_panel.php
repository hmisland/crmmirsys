<?php
/**
 * Body of the "Follow-ups" panel on a contract or project page
 * (Modules\Followups\FollowUpPanels). The heading and card come from
 * partials/related-panels.php.
 *
 * @var array $followUps follow-ups related to the record, already filtered to what the user may view
 * @var bool $canCreate
 * @var string $createUrl
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\icon;
?>
<?php if ($canCreate): ?>
<div class="actions">
    <a href="<?= e($createUrl) ?>" class="btn btn--sm"><?= icon('plus') ?><?= e(t('followups.new')) ?></a>
</div>
<?php endif; ?>
<div class="table-responsive">
<table class="table table--compact">
    <thead><tr>
        <th><?= e(t('common.name')) ?></th>
        <th><?= e(t('followups.due_at')) ?></th>
        <th><?= e(t('deals.status')) ?></th>
        <th><?= e(t('followups.assignee')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($followUps as $f): ?>
        <tr>
            <td><a href="/followups/<?= e($f['id']) ?>"><?= e($f['title']) ?></a></td>
            <td class="nowrap"><?= e($f['due_at'] ?? t('followups.no_due_date')) ?></td>
            <td><?= badge(t('followups.status.' . $f['status']), $f['status']) ?></td>
            <td><?= e(trim((string) ($f['assignee_name'] ?? '')) ?: '—') ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($followUps === []): ?>
        <tr><td colspan="4" class="muted"><?= e(t('followups.panel_empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
