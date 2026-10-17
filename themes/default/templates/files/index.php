<?php
/**
 * File library: folder browser (list or grid), search across folders,
 * thumbnails for images, server-inbox pick-up notice and upload card.
 * Thumbnails/inline views only ever come from the module's own checked
 * routes (/files/{id}/thumb, /files/{id}/view).
 *
 * @var ?array $folder
 * @var array $breadcrumb
 * @var array $folders
 * @var array $documents
 * @var array $folderOptions
 * @var string $search
 * @var string $view
 * @var bool $canManage
 * @var int $inboxCount
 * @var int $organizationId
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$folderId = $folder !== null ? (int) $folder['id'] : null;
$isGrid = $view === 'grid';
$searching = $search !== '';
$isEmpty = $folders === [] && $pagination->total === 0;

$filesUrl = function (array $params) use ($isGrid): string {
    if ($isGrid) {
        $params['view'] = 'grid';
    }
    $params = array_filter($params, fn ($v) => $v !== null && $v !== '');

    return '/files' . ($params !== [] ? '?' . http_build_query($params) : '');
};
$typeIcon = function (array $doc): string {
    $mime = (string) $doc['mime_type'];
    $ext = strtolower(pathinfo((string) $doc['original_filename'], PATHINFO_EXTENSION));

    return match (true) {
        str_starts_with($mime, 'image/') => 'image',
        in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz'], true) => 'archive',
        $mime === 'application/pdf', str_starts_with($mime, 'text/'),
        in_array($ext, ['doc', 'docx', 'odt', 'rtf', 'xls', 'xlsx', 'csv', 'ods', 'ppt', 'pptx'], true) => 'file-text',
        default => 'file',
    };
};
$fmtSize = function (int $bytes): string {
    return match (true) {
        $bytes >= 1048576 => number_format($bytes / 1048576, 1) . ' MB',
        $bytes >= 1024 => number_format($bytes / 1024) . ' KB',
        default => $bytes . ' B',
    };
};
$openUrl = fn (array $doc) => '/files/' . (int) $doc['id'] . ($doc['is_inline'] ? '/view' : '/download');
$hidden = function () use ($folderId, $isGrid): string {
    return Csrf::field()
        . ($folderId !== null ? '<input type="hidden" name="return_folder" value="' . e($folderId) . '">' : '')
        . ($isGrid ? '<input type="hidden" name="view" value="grid">' : '');
};
?>
<section class="card">
    <div class="page-head">
        <div>
            <h1><?= e(t('nav.files')) ?></h1>
            <p><?= e(t('files.hint')) ?></p>
        </div>
        <div class="actions">
            <nav class="segmented" aria-label="<?= e(t('files.view_switch')) ?>">
                <?php $viewBase = array_filter(['folder' => $folderId, 'q' => $search]); ?>
                <a class="btn btn--sm" href="/files<?= $viewBase !== [] ? '?' . e(http_build_query($viewBase)) : '' ?>"<?= $isGrid ? '' : ' aria-current="page"' ?>><?= icon('list') ?><?= e(t('files.view_list')) ?></a>
                <a class="btn btn--sm" href="/files?<?= e(http_build_query($viewBase + ['view' => 'grid'])) ?>"<?= $isGrid ? ' aria-current="page"' : '' ?>><?= icon('grid') ?><?= e(t('files.view_grid')) ?></a>
            </nav>
            <?php if ($canManage): ?><a href="/files/uploaders" class="btn"><?= icon('key') ?><?= e(t('files.manage_upload_access')) ?></a><?php endif; ?>
        </div>
    </div>

    <?php if ($canManage && $inboxCount > 0): ?>
        <div class="alert alert--info file-inbox" role="status">
            <div class="alert__body">
                <span><?= icon('inbox') ?><?= e(t('files.inbox_notice', ['count' => $inboxCount])) ?></span>
                <form method="post" action="/files/inbox/import" class="inline-form">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn--primary btn--sm"><?= icon('download') ?><?= e(t('files.inbox_import')) ?></button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <form method="get" action="/files" class="inline-form-row filter-bar">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="<?= e(t('files.search_placeholder')) ?>">
        <?php if ($isGrid): ?><input type="hidden" name="view" value="grid"><?php endif; ?>
        <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
        <?php if ($searching): ?><a href="<?= e($filesUrl(['folder' => $folderId])) ?>" class="btn btn--ghost"><?= icon('x') ?><?= e(t('files.clear')) ?></a><?php endif; ?>
    </form>

    <nav class="file-crumbs" aria-label="<?= e(t('files.folder_path')) ?>">
        <ol class="breadcrumb">
            <?php if ($searching): ?>
                <li><a href="<?= e($filesUrl([])) ?>"><?= icon('folder') ?><?= e(t('nav.files')) ?></a></li>
                <li aria-current="page"><?= e(t('files.search_results', ['q' => $search])) ?></li>
            <?php elseif ($breadcrumb === []): ?>
                <li aria-current="page"><?= icon('folder') ?><?= e(t('nav.files')) ?></li>
            <?php else: ?>
                <li><a href="<?= e($filesUrl([])) ?>"><?= icon('folder') ?><?= e(t('nav.files')) ?></a></li>
                <?php foreach ($breadcrumb as $i => $crumb): ?>
                    <?php if ($i === count($breadcrumb) - 1): ?>
                        <li aria-current="page"><?= e($crumb['name']) ?></li>
                    <?php else: ?>
                        <li><a href="<?= e($filesUrl(['folder' => (int) $crumb['id']])) ?>"><?= e($crumb['name']) ?></a></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </ol>
    </nav>

    <?php if ($canManage && !$searching): ?>
        <div class="inline-form-row file-folder-tools">
            <form method="post" action="/files/folders" class="inline-form-row">
                <?= Csrf::field() ?>
                <?php if ($folderId !== null): ?><input type="hidden" name="parent_id" value="<?= e($folderId) ?>"><?php endif; ?>
                <?php if ($isGrid): ?><input type="hidden" name="view" value="grid"><?php endif; ?>
                <input type="text" name="name" required maxlength="190" aria-label="<?= e(t('files.folder_name')) ?>" placeholder="<?= e(t('files.folder_name')) ?>">
                <button type="submit" class="btn"><?= icon('folder-plus') ?><?= e(t('files.new_folder')) ?></button>
            </form>
            <?php if ($folder !== null): ?>
                <details class="dropdown">
                    <summary class="btn"><?= icon('settings') ?><?= e(t('files.folder_actions')) ?><?= icon('chevron-down', 'icon--sm') ?></summary>
                    <div class="dropdown__panel file-menu">
                        <form method="post" action="/files/folders/<?= e($folderId) ?>/rename" class="file-menu__form">
                            <?= Csrf::field() ?>
                            <?php if ($isGrid): ?><input type="hidden" name="view" value="grid"><?php endif; ?>
                            <label for="folder-rename"><?= e(t('files.rename_folder')) ?></label>
                            <input type="text" id="folder-rename" name="name" value="<?= e($folder['name']) ?>" required maxlength="190">
                            <button type="submit" class="btn btn--sm"><?= icon('pencil') ?><?= e(t('files.rename')) ?></button>
                        </form>
                        <?php if ($isEmpty): ?>
                            <form method="post" action="/files/folders/<?= e($folderId) ?>/delete" class="file-menu__form">
                                <?= Csrf::field() ?>
                                <?php if ($isGrid): ?><input type="hidden" name="view" value="grid"><?php endif; ?>
                                <button type="submit" class="btn btn--danger btn--sm" data-confirm><?= icon('trash') ?><?= e(t('files.delete_folder')) ?></button>
                            </form>
                        <?php else: ?>
                            <p class="muted"><?= e(t('files.delete_folder_hint')) ?></p>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php include __DIR__ . '/../partials/pagination.php'; ?>

    <?php
    /** Row/card actions for one file: open/download + (managers) move and delete. */
    $fileActions = function (array $doc) use ($canManage, $folderOptions, $hidden, $openUrl): void { ?>
        <div class="actions file-actions">
            <a class="link-button" href="/files/<?= e($doc['id']) ?>/download"><?= icon('download') ?><?= e(t('files.download')) ?></a>
            <?php if ($canManage): ?>
                <details class="dropdown">
                    <summary class="link-button"><?= icon('settings') ?><?= e(t('files.more_actions')) ?></summary>
                    <div class="dropdown__panel file-menu">
                        <form method="post" action="/files/<?= e($doc['id']) ?>/move" class="file-menu__form">
                            <?= $hidden() ?>
                            <label for="move-<?= e($doc['id']) ?>"><?= e(t('files.move_to')) ?></label>
                            <select id="move-<?= e($doc['id']) ?>" name="folder_id">
                                <option value=""><?= e(t('files.top_level')) ?></option>
                                <?php foreach ($folderOptions as $opt): ?>
                                    <option value="<?= e($opt['id']) ?>"<?= (int) ($doc['folder_id'] ?? 0) === (int) $opt['id'] ? ' selected' : '' ?>><?= e($opt['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn--sm"><?= icon('folder') ?><?= e(t('files.move')) ?></button>
                        </form>
                        <form method="post" action="/files/<?= e($doc['id']) ?>/delete" class="file-menu__form">
                            <?= $hidden() ?>
                            <button type="submit" class="btn btn--danger btn--sm" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                        </form>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    <?php };
    ?>

    <?php if ($isGrid): ?>
        <?php if ($folders === [] && $documents === []): ?>
            <div class="empty-state">
                <span class="empty-state__icon"><?= icon('folder') ?></span>
                <p><?= e(t($searching ? 'files.no_results' : ($folder !== null ? 'files.folder_empty' : 'files.empty'))) ?></p>
            </div>
        <?php else: ?>
            <div class="media-grid file-grid">
                <?php foreach ($folders as $sub): ?>
                    <figure class="media-card file-card">
                        <a class="media-card__thumb file-thumb file-thumb--folder" href="<?= e($filesUrl(['folder' => (int) $sub['id']])) ?>"><?= icon('folder', 'icon--xl') ?></a>
                        <figcaption>
                            <a class="file-card__name" href="<?= e($filesUrl(['folder' => (int) $sub['id']])) ?>" title="<?= e($sub['path'] ?? $sub['name']) ?>"><?= e($sub['path'] ?? $sub['name']) ?></a>
                            <?php if ($sub['item_count'] !== null): ?><span class="media-card__meta"><?= e(t('files.folder_items', ['count' => (int) $sub['item_count']])) ?></span><?php endif; ?>
                        </figcaption>
                    </figure>
                <?php endforeach; ?>
                <?php foreach ($documents as $doc): ?>
                    <figure class="media-card file-card">
                        <a class="media-card__thumb file-thumb" href="<?= e($openUrl($doc)) ?>"<?= $doc['is_inline'] ? ' target="_blank" rel="noopener"' : '' ?>>
                            <?= icon($typeIcon($doc), 'icon--xl') ?>
                            <?php if ($doc['is_image']): ?><img src="/files/<?= e($doc['id']) ?>/thumb" alt="" loading="lazy"><?php endif; ?>
                        </a>
                        <figcaption>
                            <a class="file-card__name" href="<?= e($openUrl($doc)) ?>" title="<?= e($doc['original_filename']) ?>"<?= $doc['is_inline'] ? ' target="_blank" rel="noopener"' : '' ?>><?= e($doc['display_title']) ?></a>
                            <span class="media-card__meta">
                                <span class="ltr"><?= e($fmtSize((int) $doc['size'])) ?></span>
                                <span class="ltr"><?= e(substr((string) local_time($doc['created_at']), 0, 10)) ?></span>
                            </span>
                            <?php if ($searching && $doc['folder_path'] !== ''): ?><span class="media-card__meta"><?= icon('folder', 'icon--sm') ?><?= e($doc['folder_path']) ?></span><?php endif; ?>
                        </figcaption>
                        <?php $fileActions($doc); ?>
                    </figure>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table file-table">
            <thead><tr><th><span class="visually-hidden"><?= e(t('files.preview')) ?></span></th><th><?= e(t('common.name')) ?></th><th><?= e(t('common.tags')) ?></th><th><?= e(t('files.size')) ?></th><th><?= e(t('files.uploaded_by')) ?></th><th><?= e(t('wallet.date')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($folders as $sub): ?>
                <tr>
                    <td><a class="file-thumb file-thumb--sm file-thumb--folder" href="<?= e($filesUrl(['folder' => (int) $sub['id']])) ?>" tabindex="-1" aria-hidden="true"><?= icon('folder') ?></a></td>
                    <td><a class="file-name" href="<?= e($filesUrl(['folder' => (int) $sub['id']])) ?>"><?= e($sub['path'] ?? $sub['name']) ?></a></td>
                    <td></td>
                    <td class="muted"><?= $sub['item_count'] !== null ? e(t('files.folder_items', ['count' => (int) $sub['item_count']])) : '' ?></td>
                    <td></td><td></td><td></td>
                </tr>
            <?php endforeach; ?>
            <?php foreach ($documents as $doc): ?>
                <tr>
                    <td>
                        <a class="file-thumb file-thumb--sm" href="<?= e($openUrl($doc)) ?>" tabindex="-1" aria-hidden="true"<?= $doc['is_inline'] ? ' target="_blank" rel="noopener"' : '' ?>>
                            <?= icon($typeIcon($doc)) ?>
                            <?php if ($doc['is_image']): ?><img src="/files/<?= e($doc['id']) ?>/thumb" alt="" loading="lazy"><?php endif; ?>
                        </a>
                    </td>
                    <td>
                        <a class="file-name" href="<?= e($openUrl($doc)) ?>"<?= $doc['is_inline'] ? ' target="_blank" rel="noopener"' : '' ?>><?= e($doc['display_title']) ?></a>
                        <div class="muted file-sub"><?= e($doc['original_filename']) ?></div>
                        <?php if (!empty($doc['description'])): ?><div class="muted file-sub"><?= e($doc['description']) ?></div><?php endif; ?>
                        <?php if ($searching && $doc['folder_path'] !== ''): ?><div class="muted file-sub"><?= icon('folder', 'icon--sm') ?><?= e($doc['folder_path']) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <?php foreach ($doc['tags'] as $tag): ?>
                            <span class="tag-pill"><span><?= e($tag['name']) ?></span></span>
                        <?php endforeach; ?>
                    </td>
                    <td class="muted nowrap"><span class="ltr"><?= e($fmtSize((int) $doc['size'])) ?></span></td>
                    <td><?= e($doc['uploader_name'] ?? '—') ?></td>
                    <td class="muted nowrap"><span class="ltr"><?= e(substr((string) local_time($doc['created_at']), 0, 16)) ?></span></td>
                    <td><?php $fileActions($doc); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($folders === [] && $documents === []): ?>
                <tr><td colspan="7" class="muted"><?= e(t($searching ? 'files.no_results' : ($folder !== null ? 'files.folder_empty' : 'files.empty'))) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($canManage && !$searching): ?>
<section class="card card--form">
    <h2><?= e(t('files.upload_a_file')) ?></h2>
    <p class="muted"><?= icon('folder', 'icon--sm') ?><?= e(t('files.upload_to', ['folder' => $breadcrumb === [] ? t('files.top_level') : implode(' / ', array_column($breadcrumb, 'name'))])) ?></p>
    <form method="post" action="/files/upload" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <?php if ($folderId !== null): ?><input type="hidden" name="folder_id" value="<?= e($folderId) ?>"><?php endif; ?>
        <?php if ($isGrid): ?><input type="hidden" name="view" value="grid"><?php endif; ?>

        <label for="file"><?= e(t('files.file')) ?></label>
        <input type="file" id="file" name="file" required>

        <label for="title"><?= e(t('files.title_optional')) ?></label>
        <input type="text" id="title" name="title" maxlength="255">

        <label for="description"><?= e(t('files.description_optional')) ?></label>
        <input type="text" id="description" name="description">

        <label for="tags-input"><?= e(t('common.tags')) ?></label>
        <input type="text" id="tags-input" class="crm-tag-input" data-name="tags[]" placeholder="<?= e(t('files.add_a_tag_hint')) ?>">

        <button type="submit" class="btn btn--primary"><?= icon('upload') ?><?= e(t('common.upload')) ?></button>
    </form>
    <p class="muted file-inbox-hint"><?= icon('inbox', 'icon--sm') ?><?= e(t('files.inbox_hint')) ?> <code class="ltr">storage/file-library/<?= e($organizationId) ?>/inbox/</code></p>
</section>
<?php endif; ?>
