<?php
/**
 * Platform ticket: header facts + the message thread (oldest first), shared
 * by the organization page and the Super Admin page.
 *
 * @var array $ticket
 * @var list<array> $messages
 * @var string $viewerSide 'organization' | 'platform'
 * @var array<int, list<array>> $attachments message id => files
 * @var string $attachmentBase download URL prefix of this side
 */

use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
?>
<dl class="fact-row">
    <dt><?= e(t('platform_tickets.field.status')) ?></dt>
    <dd><?= badge(t('platform_tickets.status.' . $ticket['status']), ['open' => 'open', 'answered' => 'pending'][$ticket['status']] ?? null) ?></dd>
    <dt><?= e(t('platform_tickets.field.category')) ?></dt>
    <dd><?= e(t('platform_tickets.category.' . $ticket['category'])) ?></dd>
    <dt><?= e(t('platform_tickets.field.priority')) ?></dt>
    <dd><?= badge(t('platform_tickets.priority.' . $ticket['priority']), $ticket['priority'] === 'normal' ? null : ($ticket['priority'] === 'urgent' ? 'failed' : 'pending')) ?></dd>
    <dt><?= e(t('platform_tickets.field.created')) ?></dt>
    <dd><span class="ltr"><?= e(local_time($ticket['created_at'])) ?></span></dd>
</dl>

<ul class="note-list">
    <?php foreach ($messages as $m): ?>
        <?php $fromPlatform = $m['author_side'] === 'platform'; ?>
        <li<?= $fromPlatform ? ' class="note--platform"' : '' ?>>
            <p><?= nl2br(e($m['body'])) ?></p>
            <?php if (!empty($attachments[(int) $m['id']])): ?>
                <ul class="attachment-list">
                    <?php foreach ($attachments[(int) $m['id']] as $file): ?>
                        <li>
                            <a href="<?= e($attachmentBase . (int) $file['id']) ?>"><?= icon('paperclip') ?><?= e($file['filename']) ?></a>
                            <span class="muted ltr">(<?= e((string) max(1, (int) round(((int) $file['size']) / 1024))) ?> KB)</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <span class="muted">
                <?= e($fromPlatform && $viewerSide === 'organization' ? t('platform_tickets.platform_team') : (($m['author_name'] ?? '') !== '' ? $m['author_name'] : t('platform_tickets.unknown_author'))) ?>
                · <span class="ltr"><?= e(local_time($m['created_at'])) ?></span>
            </span>
        </li>
    <?php endforeach; ?>
</ul>
