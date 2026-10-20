<?php
/**
 * Super Admin → one organization ticket: thread, reply (optionally closing),
 * close / reopen, priority.
 *
 * @var array $ticket
 * @var list<array> $messages
 * @var array<int, list<array>> $attachments message id => files
 * @var string[] $priorities
 */

use App\Application\Services\PlatformTicketService;
use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;

$viewerSide = 'platform';
$attachmentBase = '/admin/tickets/' . (int) $ticket['id'] . '/attachments/';
$closed = $ticket['status'] === 'closed';
?>
<div class="page-head">
    <div>
        <h1><span class="ltr">#<?= e($ticket['id']) ?></span> <?= e($ticket['subject']) ?></h1>
        <p>
            <a href="/admin/organizations/<?= e($ticket['organization_id']) ?>/modules"><?= e($ticket['organization_name']) ?></a>
            · <?= e($ticket['creator_name'] !== '' ? $ticket['creator_name'] : '—') ?>
            <?php if (!empty($ticket['creator_email'])): ?><span class="muted ltr"><?= e($ticket['creator_email']) ?></span><?php endif; ?>
        </p>
    </div>
    <div class="actions">
        <a href="/admin/tickets" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('platform_tickets.back')) ?></a>
        <form method="post" action="/admin/tickets/<?= e($ticket['id']) ?>/status" class="inline-form">
            <?= Csrf::field() ?>
            <?php if ($closed): ?>
                <input type="hidden" name="status" value="open">
                <button type="submit" class="btn"><?= icon('undo') ?><?= e(t('platform_tickets.admin.reopen')) ?></button>
            <?php else: ?>
                <input type="hidden" name="status" value="closed">
                <button type="submit" class="btn btn--danger" data-confirm="<?= e(t('platform_tickets.close_confirm')) ?>"><?= icon('x-circle') ?><?= e(t('platform_tickets.close')) ?></button>
            <?php endif; ?>
        </form>
    </div>
</div>

<section class="card">
    <?php include __DIR__ . '/../../partials/platform-ticket-thread.php'; ?>

    <form method="post" action="/admin/tickets/<?= e($ticket['id']) ?>/priority" class="inline-form-row">
        <?= Csrf::field() ?>
        <label for="ticket-priority"><?= e(t('platform_tickets.field.priority')) ?></label>
        <select id="ticket-priority" name="priority">
            <?php foreach ($priorities as $p): ?>
                <option value="<?= e($p) ?>"<?= $ticket['priority'] === $p ? ' selected' : '' ?>><?= e(t('platform_tickets.priority.' . $p)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= icon('save') ?><?= e(t('platform_tickets.admin.save_priority')) ?></button>
    </form>
</section>

<section class="card card--form" id="reply">
    <h2><?= icon('reply') ?><?= e(t('platform_tickets.reply')) ?></h2>
    <form method="post" action="/admin/tickets/<?= e($ticket['id']) ?>/reply" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <label for="reply-body"><?= e(t('platform_tickets.field.body')) ?></label>
        <textarea id="reply-body" name="body" rows="6" maxlength="<?= e(PlatformTicketService::BODY_MAX) ?>" required></textarea>
        <label for="reply-attachments"><?= e(t('platform_tickets.field.attachments')) ?></label>
        <input type="file" id="reply-attachments" name="attachments[]" multiple accept=".txt,.csv,.jpg,.jpeg,.png,.webp,text/plain,text/csv,image/jpeg,image/png,image/webp">
        <p class="field-hint"><?= e(t('platform_tickets.attachments_hint')) ?></p>
        <label class="check check--spaced"><input type="checkbox" name="close" value="1"> <?= e(t('platform_tickets.admin.reply_and_close')) ?></label>
        <p class="field-hint"><?= e(t('platform_tickets.admin.reply_hint')) ?></p>
        <div class="actions">
            <button type="submit" class="btn btn--primary"><?= icon('send') ?><?= e(t('platform_tickets.send_reply')) ?></button>
        </div>
    </form>
</section>
