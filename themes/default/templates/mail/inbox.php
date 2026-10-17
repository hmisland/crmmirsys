<?php
/** @var array $items */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
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

    <form method="get" action="/mail" class="inline-form-row filter-bar">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('filters.search_mail')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
        <label class="check"><input type="checkbox" name="unread" value="1" <?= $unread ? 'checked' : '' ?>> <?= e(t('filters.unread_only')) ?></label>
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/mail" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../partials/pagination.php'; ?>

    <?php $bulkAction = '/mail/inbox/bulk-delete'; $bulkConfirm = t('mail.bulk_confirm'); include __DIR__ . '/../partials/bulk-bar.php'; ?>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th class="cell-check"><input type="checkbox" data-bulk-all aria-label="<?= e(t('bulk.select_page')) ?>" title="<?= e(t('bulk.select_page')) ?>"></th><th></th><th><?= e(t('mail.from')) ?></th><th><?= e(t('mail.subject')) ?></th><th><?= e(t('wallet.date')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <?php $rootId = $item['thread_id'] ?? $item['message_id']; ?>
            <tr<?= !$item['is_read'] ? ' class="is-unread"' : '' ?>>
                <td class="cell-check"><input type="checkbox" name="ids[]" value="<?= e($item['message_id']) ?>" form="bulk-form" data-bulk-item aria-label="<?= e(t('bulk.select_row')) ?>"></td>
                <td><?= $item['is_read'] ? '' : '●' ?></td>
                <td><?= e($item['sender_name'] ?? '') ?></td>
                <td><a href="/mail/thread/<?= e($rootId) ?>"><?= e($item['subject']) ?></a></td>
                <td class="muted"><?= e(local_time($item['message_created_at'])) ?></td>
                <td>
                    <form method="post" action="/mail/inbox/<?= e($item['message_id']) ?>/delete" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($items === []): ?>
            <tr><td colspan="6" class="muted"><?= e(t('mail.nothing_here_yet')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
