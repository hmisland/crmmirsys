<?php
/** @var array $items */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(t('mail.inbox')) ?></h1>
    <p>
        <a href="/mail/compose" class="btn btn--primary"><?= icon('pencil') ?><?= e(t('mail.compose')) ?></a>
        <a href="/mail/sent" class="btn"><?= icon('send') ?><?= e(t('mail.sent')) ?></a>
        <a href="/mail/drafts" class="btn"><?= icon('file-text') ?><?= e(t('mail.drafts')) ?></a>
        <a href="/mail/trash" class="btn"><?= icon('trash') ?><?= e(t('mail.trash')) ?></a>
        <a href="/mail/broadcast" class="btn"><?= icon('megaphone') ?><?= e(t('mail.broadcast')) ?></a>
    </p>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th></th><th><?= e(t('mail.from')) ?></th><th><?= e(t('mail.subject')) ?></th><th><?= e(t('wallet.date')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <?php $rootId = $item['thread_id'] ?? $item['message_id']; ?>
            <tr<?= !$item['is_read'] ? ' class="is-unread"' : '' ?>>
                <td><?= $item['is_read'] ? '' : '●' ?></td>
                <td><?= e($item['sender_name'] ?? '') ?></td>
                <td><a href="/mail/thread/<?= e($rootId) ?>"><?= e($item['subject']) ?></a></td>
                <td class="muted"><?= e($item['message_created_at']) ?></td>
                <td>
                    <form method="post" action="/mail/inbox/<?= e($item['message_id']) ?>/delete" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($items === []): ?>
            <tr><td colspan="5" class="muted"><?= e(t('mail.nothing_here_yet')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
