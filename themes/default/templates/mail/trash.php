<?php
/** @var array $folders */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(t('mail.trash')) ?></h1>
    <p>
        <a href="/mail" class="btn"><?= icon('inbox') ?><?= e(t('mail.inbox')) ?></a>
        <a href="/mail/sent" class="btn"><?= icon('send') ?><?= e(t('mail.sent')) ?></a>
        <a href="/mail/drafts" class="btn"><?= icon('file-text') ?><?= e(t('mail.drafts')) ?></a>
    </p>

    <h2><?= e(t('mail.inbox')) ?></h2>
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('mail.from')) ?></th><th><?= e(t('mail.subject')) ?></th><th><?= e(t('wallet.date')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($folders['inbox'] as $item): ?>
            <?php $rootId = $item['thread_id'] ?? $item['message_id']; ?>
            <tr>
                <td><?= e($item['sender_name'] ?? '') ?></td>
                <td><a href="/mail/thread/<?= e($rootId) ?>"><?= e($item['subject']) ?></a></td>
                <td class="muted"><?= e($item['message_created_at']) ?></td>
                <td>
                    <form method="post" action="/mail/inbox/<?= e($item['message_id']) ?>/restore" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button"><?= icon('undo') ?><?= e(t('mail.restore')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($folders['inbox'] === []): ?>
            <tr><td colspan="4" class="muted"><?= e(t('mail.trash_empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <h2 style="margin-top:1.5rem"><?= e(t('mail.sent')) ?></h2>
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('mail.subject')) ?></th><th><?= e(t('wallet.date')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($folders['sent'] as $item): ?>
            <?php $rootId = $item['thread_id'] ?? $item['id']; ?>
            <tr>
                <td><a href="/mail/thread/<?= e($rootId) ?>"><?= e($item['subject']) ?></a></td>
                <td class="muted"><?= e($item['created_at']) ?></td>
                <td>
                    <form method="post" action="/mail/sent/<?= e($item['id']) ?>/restore" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button"><?= icon('undo') ?><?= e(t('mail.restore')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($folders['sent'] === []): ?>
            <tr><td colspan="3" class="muted"><?= e(t('mail.trash_empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
