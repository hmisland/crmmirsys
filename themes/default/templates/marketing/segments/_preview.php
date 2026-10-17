<?php
/**
 * Matching contacts: total + one page of rows (rows only with contacts.view).
 *
 * @var array{total: int, rows: array, rows_visible: bool} $preview
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\badge;
?>
<p><strong><?= e(t('marketing.segment.matching', ['count' => number_format($preview['total'])])) ?></strong></p>
<p class="field-hint"><?= e(t('marketing.segment.matching_hint')) ?></p>
<?php if (!$preview['rows_visible']): ?>
    <p class="locked"><?= e(t('marketing.segment.rows_hidden')) ?></p>
<?php else: ?>
<div class="table-responsive">
<table class="table table--compact">
    <thead><tr>
        <th><?= e(t('common.name')) ?></th>
        <th><?= e(t('marketing.segment.field.phone')) ?></th>
        <th><?= e(t('marketing.segment.field.email')) ?></th>
        <th><?= e(t('marketing.segment.field.city')) ?></th>
        <th><?= e(t('marketing.segment.field.status')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($preview['rows'] as $c): ?>
        <tr>
            <td><a href="/contacts/<?= e($c['id']) ?>"><?= e(trim($c['first_name'] . ' ' . ($c['last_name'] ?? ''))) ?></a></td>
            <td><span class="ltr"><?= e($c['phone'] ?? '') ?></span></td>
            <td><span class="ltr"><?= e($c['email'] ?? '') ?></span></td>
            <td><?= e($c['city'] ?? '') ?></td>
            <td><?= badge(t('marketing.segment.value.' . $c['status']), $c['status']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($preview['rows'] === []): ?>
        <tr><td colspan="5" class="muted"><?= e(t('marketing.segment.no_matches')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
