<?php
/**
 * Full unified timeline: the contact's and record's Core timeline
 * events plus invoices/payments from Finance (read-side merge, see
 * ClientTimelineService). Variables come from show.php's scope.
 *
 * @var array $timeline
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$events = $timeline;
?>
<section class="card">
    <h2><?= icon('history') ?> <?= e(t('client_records.tab.timeline')) ?></h2>
    <p class="field-hint"><?= e(t('client_records.timeline_hint')) ?></p>
    <?php include $theme->templatesPath() . '/client-records/_events.php'; ?>
</section>
