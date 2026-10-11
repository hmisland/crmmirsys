<?php
/**
 * Unified client timeline items (see ClientTimelineService::build()).
 * Expects $events in scope. Known event types are translated through
 * client_records.event.*; anything else falls back to the description
 * stored by whichever module recorded it.
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

?>
<ul class="event-list">
    <?php foreach ($events as $event): ?>
        <?php
        $key = 'client_records.event.' . $event['type'];
        $label = t($key);
        $title = $label !== $key ? $label : $event['description'];
        ?>
        <li>
            <span class="event__icon event__icon--<?= e($event['tone']) ?>"><?= icon($event['icon']) ?></span>
            <div class="event__body">
                <span class="event__title">
                    <?php if ($event['href'] !== null): ?>
                        <a href="<?= e($event['href']) ?>"><?= e($title) ?></a>
                    <?php else: ?>
                        <?= e($title) ?>
                    <?php endif; ?>
                </span>
                <span class="event__meta">
                    <span class="nowrap ltr"><?= e(substr((string) $event['at'], 0, 16)) ?></span>
                    <?php if ($event['detail'] !== null && $event['detail'] !== ''): ?>
                        <span><?= e($event['detail']) ?></span>
                    <?php endif; ?>
                    <?php if ($event['amount'] !== null): ?>
                        <span class="nowrap"><?= e(\App\Infrastructure\Theme\money($event['amount'], $event['currency'])) ?> <?= e($event['currency']) ?></span>
                    <?php endif; ?>
                </span>
            </div>
        </li>
    <?php endforeach; ?>
    <?php if ($events === []): ?>
        <li class="muted"><?= e(t('common.nothing_yet')) ?></li>
    <?php endif; ?>
</ul>
