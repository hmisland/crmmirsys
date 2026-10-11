<?php
/** @var array $announcement */
/** @var array $recipients */
/** @var \App\Infrastructure\Localization\LocaleFormatter $fmt */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;

$when = fn (?string $at): string => $at === null ? '—' : $fmt->date($at) . ' ' . $fmt->digits(substr($at, 11, 5));
$seen = count(array_filter($recipients, fn ($r) => $r['seen_at'] !== null));
?>
<section class="card">
    <p><a href="/admin/announcements"><?= icon('arrow-right') ?><?= e(t('announcements.back')) ?></a></p>
    <h1><?= e($announcement['title']) ?></h1>
    <p class="muted"><?= e($when($announcement['created_at'])) ?> · <?= e(t('announcements.seen_summary', ['seen' => $fmt->digits((string) $seen), 'total' => $fmt->digits((string) count($recipients))])) ?></p>
    <div class="announcement-body"><?= nl2br(e($announcement['body'])) ?></div>
</section>

<section class="card">
    <h2><?= e(t('announcements.report')) ?></h2>
    <div class="table-responsive">
        <table class="table">
            <thead>
            <tr>
                <th><?= e(t('announcements.organization')) ?></th>
                <th><?= e(t('announcements.recipient')) ?></th>
                <th><?= e(t('announcements.status')) ?></th>
                <th><?= e(t('announcements.seen_at')) ?></th>
                <th><?= e(t('announcements.removed_at')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($recipients === []): ?>
                <tr><td colspan="5" class="muted"><?= e(t('announcements.no_recipients')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($recipients as $r): ?>
                <tr>
                    <td><?= e($r['organization_name'] ?? '—') ?></td>
                    <td><?= e(trim($r['first_name'] . ' ' . ($r['last_name'] ?? ''))) ?><div class="muted text-sm ltr"><?= e($r['email']) ?></div></td>
                    <td><?= $r['seen_at'] !== null ? badge(t('announcements.status_seen'), 'completed') : badge(t('announcements.status_unseen'), 'pending') ?></td>
                    <td class="nowrap"><?= e($when($r['seen_at'])) ?></td>
                    <td class="nowrap"><?= e($when($r['deleted_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
