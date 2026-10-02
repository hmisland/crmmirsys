<?php
/** @var array $documents */
/** @var string $tagSearch */
/** @var bool $canManage */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e(t('nav.files')) ?></h1>
    <p class="muted"><?= e(t('files.hint')) ?></p>

    <form method="get" action="/files" class="inline-form-row">
        <input type="text" name="tag" value="<?= e($tagSearch) ?>" placeholder="<?= e(t('files.search_by_tag')) ?>">
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
        <?php if ($tagSearch !== ''): ?><a href="/files" class="btn"><?= icon('x') ?><?= e(t('files.clear')) ?></a><?php endif; ?>
        <?php if ($canManage): ?><a href="/files/uploaders" class="btn"><?= icon('key') ?><?= e(t('files.manage_upload_access')) ?></a><?php endif; ?>
    </form>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th><?= e(t('files.file')) ?></th><th><?= e(t('common.tags')) ?></th><th><?= e(t('files.size')) ?></th><th><?= e(t('files.uploaded_by')) ?></th><th><?= e(t('wallet.date')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($documents as $doc): ?>
            <tr>
                <td>
                    <a href="/files/<?= e($doc['id']) ?>/download"><?= e($doc['original_filename']) ?></a>
                    <?php if (!empty($doc['description'])): ?><div class="muted"><?= e($doc['description']) ?></div><?php endif; ?>
                </td>
                <td>
                    <?php foreach ($doc['tags'] as $tag): ?>
                        <span class="tag-pill"><span><?= e($tag['name']) ?></span></span>
                    <?php endforeach; ?>
                </td>
                <td class="muted"><?= e(round($doc['size'] / 1024)) ?> KB</td>
                <td><?= e($doc['uploader_name'] ?? '—') ?></td>
                <td class="muted"><?= e($doc['created_at']) ?></td>
                <td>
                    <?php if ($canManage): ?>
                        <form method="post" action="/files/<?= e($doc['id']) ?>/delete" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($documents === []): ?>
            <tr><td colspan="6" class="muted"><?= e(t('files.empty')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>

<?php if ($canManage): ?>
<section class="card card--form">
    <h2><?= e(t('files.upload_a_file')) ?></h2>
    <form method="post" action="/files/upload" enctype="multipart/form-data">
        <?= Csrf::field() ?>

        <label for="file"><?= e(t('files.file')) ?></label>
        <input type="file" id="file" name="file" required>

        <label for="description"><?= e(t('files.description_optional')) ?></label>
        <input type="text" id="description" name="description">

        <label for="tags-input"><?= e(t('common.tags')) ?></label>
        <input type="text" id="tags-input" class="crm-tag-input" data-name="tags[]" placeholder="<?= e(t('files.add_a_tag_hint')) ?>">

        <button type="submit" class="btn btn--primary"><?= icon('upload') ?><?= e(t('common.upload')) ?></button>
    </form>
</section>
<?php endif; ?>
