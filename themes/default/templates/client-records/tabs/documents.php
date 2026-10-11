<?php
/**
 * Documents tab (consent forms, ID, client-provided, treatment docs).
 * Requires client_records.view_sensitive to list or open anything;
 * uploading/deleting requires manage_documents. Files are served only
 * through /client-records/{id}/files/{fileId}. Variables come from
 * show.php's scope.
 *
 * @var array $files
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$kind = 'document';
$categories = ['consent', 'identity', 'client_provided', 'treatment', 'other'];
?>
<div class="record-layout">
    <div>
        <section class="card">
            <h2><?= icon('file-text') ?> <?= e(t('client_records.tab.documents')) ?></h2>
            <?php if (!$can['sensitive']): ?>
                <p class="locked"><?= icon('lock') ?><?= e(t('client_records.files_locked')) ?></p>
            <?php else: ?>
                <ul class="attachment-list">
                    <?php foreach ($files as $file): ?>
                        <li>
                            <a href="/client-records/<?= e($record['id']) ?>/files/<?= e($file['id']) ?>?download=1"><?= e($file['filename']) ?></a>
                            <span class="badge"><?= e(t('client_records.doc_category.' . $file['category'])) ?></span>
                            <span class="muted"><span class="ltr"><?= e($file['taken_at'] ?? substr((string) $file['created_at'], 0, 10)) ?></span> · <?= e(max(1, (int) round($file['size'] / 1024))) ?> KB</span>
                            <?php if ($file['session_id'] !== null): ?><span class="muted">· <?= icon('activity', 'icon--sm') ?> <?= e(t('client_records.linked_session')) ?></span><?php endif; ?>
                            <?php if (!empty($file['notes'])): ?><span class="muted">· <?= e($file['notes']) ?></span><?php endif; ?>
                            <?php if ($can['documents']): ?>
                                <form method="post" action="/client-records/<?= e($record['id']) ?>/files/<?= e($file['id']) ?>/delete" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="kind" value="document">
                                    <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    <?php if ($files === []): ?><li class="muted"><?= e(t('client_records.no_documents')) ?></li><?php endif; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
    <div>
        <?php if ($can['documents']): ?>
            <?php include $theme->templatesPath() . '/client-records/_upload.php'; ?>
        <?php endif; ?>
    </div>
</div>
