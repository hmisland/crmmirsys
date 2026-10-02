<?php
/** @var array $items */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(t('mail.drafts')) ?></h1>
    <p>
        <a href="/mail" class="btn"><?= e(t('mail.inbox')) ?></a>
        <a href="/mail/sent" class="btn"><?= e(t('mail.sent')) ?></a>
        <a href="/mail/compose" class="btn btn--primary"><?= e(t('mail.compose')) ?></a>
        <a href="/mail/trash" class="btn"><?= e(t('mail.trash')) ?></a>
    </p>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('mail.subject')) ?></th><th><?= e(t('wallet.date')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><a href="/mail/drafts/<?= e($item['id']) ?>/edit"><?= e($item['subject'] !== '' ? $item['subject'] : t('mail.no_subject')) ?></a></td>
                <td class="muted"><?= e($item['created_at']) ?></td>
                <td>
                    <form method="post" action="/mail/drafts/<?= e($item['id']) ?>/delete" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="link-button"><?= e(t('common.delete')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($items === []): ?>
            <tr><td colspan="3" class="muted"><?= e(t('mail.no_drafts')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
