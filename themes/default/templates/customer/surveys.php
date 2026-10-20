<?php
/**
 * Customer Portal → my surveys (only this customer's invitations).
 *
 * @var list<array> $surveys
 */

use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;

$state = fn (string $s): string => match ($s) { 'responded' => 'answered', 'expired' => 'expired', default => 'open' };
?>
<section class="card">
    <h1><?= e(t('customer_portal.nav.surveys')) ?></h1>
    <div class="table-responsive">
    <table class="table">
        <thead><tr>
            <th><?= e(t('customer_portal.surveys.survey')) ?></th>
            <th><?= e(t('customer_portal.surveys.sent')) ?></th>
            <th><?= e(t('customer_portal.surveys.status')) ?></th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php if ($surveys === []): ?>
            <tr><td colspan="4" class="muted"><?= e(t('customer_portal.surveys.empty')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($surveys as $s): ?>
            <?php $st = $state((string) $s['status']); ?>
            <tr>
                <td><?= e($s['survey_name']) ?></td>
                <td class="nowrap"><span class="ltr"><?= e(substr((string) local_time($s['sent_at'] ?? $s['created_at']), 0, 16)) ?></span></td>
                <td><?= badge(t('customer_portal.surveys.state.' . $st), ['answered' => 'completed', 'open' => 'pending', 'expired' => 'expired'][$st]) ?></td>
                <td><?php if ($st === 'open'): ?><a href="/customer/surveys/<?= e($s['id']) ?>" class="btn btn--sm btn--primary"><?= icon('pencil') ?><?= e(t('customer_portal.surveys.answer')) ?></a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
