<?php
/**
 * "Last import" card with the rollback button. Included by the import
 * page and the import result page.
 *
 * @var array $latestBatch from ContactImportExportService::latestBatch()
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h2><?= e(t('import.last_import')) ?></h2>
    <dl class="fact-row">
        <div class="fact"><dt><?= icon('clock') ?><?= e(t('import.batch_date')) ?></dt><dd><span class="ltr"><?= e(local_time($latestBatch['created_at'])) ?></span></dd></div>
        <div class="fact"><dt><?= icon('user') ?><?= e(t('import.batch_user')) ?></dt><dd><?= e(trim((string) ($latestBatch['user_name'] ?? '')) ?: '—') ?></dd></div>
        <div class="fact"><dt><?= icon('file-text') ?><?= e(t('import.batch_file')) ?></dt><dd><?= e($latestBatch['file_name'] ?: '—') ?></dd></div>
        <div class="fact"><dt><?= icon('user-plus') ?><?= e(t('import.created')) ?></dt><dd><?= e($latestBatch['created_count']) ?></dd></div>
        <div class="fact"><dt><?= icon('pencil') ?><?= e(t('import.updated')) ?></dt><dd><?= e($latestBatch['updated_count']) ?></dd></div>
        <div class="fact"><dt><?= icon('info') ?><?= e(t('deals.status')) ?></dt><dd><?= badge(t('import.batch_status.' . $latestBatch['status']), $latestBatch['status'] === 'active' ? 'active' : 'cancelled') ?></dd></div>
    </dl>

    <?php if ($latestBatch['can_rollback']): ?>
        <p class="muted"><?= e(t('import.rollback_hint')) ?></p>
        <form method="post" action="/contacts/import/rollback" class="inline-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="batch_id" value="<?= e($latestBatch['id']) ?>">
            <button type="submit" class="btn btn--danger" data-confirm="<?= e(t('import.rollback_confirm')) ?>"><?= icon('undo') ?><?= e(t('import.rollback')) ?></button>
        </form>
    <?php endif; ?>
</section>
