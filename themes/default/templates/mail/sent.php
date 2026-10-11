<?php
/** @var array $items */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(t('mail.sent')) ?></h1>
    <p>
        <a href="/mail" class="btn"><?= icon('inbox') ?><?= e(t('mail.inbox')) ?></a>
        <a href="/mail/compose" class="btn btn--primary"><?= icon('pencil') ?><?= e(t('mail.compose')) ?></a>
        <a href="/mail/drafts" class="btn"><?= icon('file-text') ?><?= e(t('mail.drafts')) ?></a>
        <a href="/mail/trash" class="btn"><?= icon('trash') ?><?= e(t('mail.trash')) ?></a>
    </p>

    <form method="get" action="/mail/sent" class="inline-form-row filter-bar">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('filters.search_mail')) ?>" aria-label="<?= e(t('common.search')) ?>">
        <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
        <?php if ($pagination->filterQuery() !== []): ?><a href="/mail/sent" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
    </form>

    <?php include __DIR__ . '/../partials/pagination.php'; ?>

    <?php $bulkAction = '/mail/sent/bulk-delete'; $bulkConfirm = t('mail.bulk_confirm'); include __DIR__ . '/../partials/bulk-bar.php'; ?>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th class="cell-check"><input type="checkbox" data-bulk-all aria-label="<?= e(t('bulk.select_page')) ?>" title="<?= e(t('bulk.select_page')) ?>"></th><th><?= e(t('mail.subject')) ?></th><th><?= e(t('wallet.date')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <?php $rootId = $item['thread_id'] ?? $item['id']; ?>
            <tr>
                <td class="cell-check"><input type="checkbox" name="ids[]" value="<?= e($item['id']) ?>" form="bulk-form" data-bulk-item aria-label="<?= e(t('bulk.select_row')) ?>"></td>
                <td><a href="/mail/thread/<?= e($rootId) ?>"><?= e($item['subject']) ?></a></td>
                <td class="muted"><?= e($item['created_at']) ?></td>
                <td>
                    <form method="post" action="/mail/sent/<?= e($item['id']) ?>/delete" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($items === []): ?>
            <tr><td colspan="4" class="muted"><?= e(t('mail.nothing_sent_yet')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
