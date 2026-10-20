<?php
/**
 * Organization → one ticket to the platform team: thread, reply, close.
 *
 * @var array $ticket
 * @var list<array> $messages
 * @var array<int, list<array>> $attachments message id => files
 */

use App\Application\Services\PlatformTicketService;
use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;

$viewerSide = 'organization';
$attachmentBase = '/org/tickets/' . (int) $ticket['id'] . '/attachments/';
$closed = $ticket['status'] === 'closed';
?>
<div class="page-head">
    <div>
        <h1><span class="ltr">#<?= e($ticket['id']) ?></span> <?= e($ticket['subject']) ?></h1>
    </div>
    <div class="actions">
        <a href="/org/tickets" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('platform_tickets.back')) ?></a>
        <?php if (!$closed): ?>
            <form method="post" action="/org/tickets/<?= e($ticket['id']) ?>/close" class="inline-form">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--danger" data-confirm="<?= e(t('platform_tickets.close_confirm')) ?>"><?= icon('x-circle') ?><?= e(t('platform_tickets.close')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<section class="card">
    <?php include __DIR__ . '/../../partials/platform-ticket-thread.php'; ?>
</section>

<section class="card card--form" id="reply">
    <h2><?= icon('reply') ?><?= e(t('platform_tickets.reply')) ?></h2>
    <?php if ($closed): ?><p class="field-hint"><?= e(t('platform_tickets.reply_reopens')) ?></p><?php endif; ?>
    <form method="post" action="/org/tickets/<?= e($ticket['id']) ?>/reply" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <label for="reply-body"><?= e(t('platform_tickets.field.body')) ?></label>
        <textarea id="reply-body" name="body" rows="5" maxlength="<?= e(PlatformTicketService::BODY_MAX) ?>" required></textarea>
        <label for="reply-attachments"><?= e(t('platform_tickets.field.attachments')) ?></label>
        <input type="file" id="reply-attachments" name="attachments[]" multiple accept=".txt,.csv,.jpg,.jpeg,.png,.webp,text/plain,text/csv,image/jpeg,image/png,image/webp">
        <p class="field-hint"><?= e(t('platform_tickets.attachments_hint')) ?></p>
        <div class="actions">
            <button type="submit" class="btn btn--primary"><?= icon('send') ?><?= e(t('platform_tickets.send_reply')) ?></button>
        </div>
    </form>
</section>
