<?php
/**
 * Shared across crm/{contacts,companies,leads}/show.php.
 * Expects in scope: $type (string), $id (int), $notes, $tags,
 * $allTags, $timeline (arrays), and optionally $attachments (array,
 * only passed by pages that support uploads).
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$base = "/records/{$type}/{$id}";
?>
<section class="card">
    <h2><?= e(t('common.tags')) ?></h2>
    <div class="tag-list">
        <?php foreach ($tags as $tag): ?>
            <span class="tag-pill">
                <?= e($tag['name']) ?>
                <form method="post" action="<?= e($base) ?>/tags/<?= e($tag['id']) ?>/delete" class="inline-form">
                    <?= Csrf::field() ?>
                    <button type="submit" class="tag-pill__remove" title="<?= e(t('common.remove_tag')) ?>">&times;</button>
                </form>
            </span>
        <?php endforeach; ?>
        <?php if ($tags === []): ?><span class="muted"><?= e(t('common.no_tags_yet')) ?></span><?php endif; ?>
    </div>
    <form method="post" action="<?= e($base) ?>/tags" class="inline-form-row">
        <?= Csrf::field() ?>
        <input type="text" name="name" list="tag-suggestions" placeholder="<?= e(t('common.add_a_tag')) ?>" required>
        <datalist id="tag-suggestions">
            <?php foreach ($allTags as $tagOption): ?><option value="<?= e($tagOption['name']) ?>"><?php endforeach; ?>
        </datalist>
        <button type="submit" class="btn"><?= icon('tag') ?><?= e(t('common.add_tag')) ?></button>
    </form>
</section>

<?php if (isset($attachments)): ?>
<section class="card">
    <h2><?= e(t('common.attachments')) ?></h2>
    <ul class="attachment-list">
        <?php foreach ($attachments as $a): ?>
            <li>
                <a href="<?= e($base) ?>/attachments/<?= e($a['id']) ?>/download"><?= e($a['filename']) ?></a>
                <span class="muted">(<?= e(round($a['size'] / 1024)) ?> KB)</span>
                <form method="post" action="<?= e($base) ?>/attachments/<?= e($a['id']) ?>/delete" class="inline-form">
                    <?= Csrf::field() ?>
                    <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                </form>
            </li>
        <?php endforeach; ?>
        <?php if ($attachments === []): ?><li class="muted"><?= e(t('common.no_attachments_yet')) ?></li><?php endif; ?>
    </ul>
    <form method="post" action="<?= e($base) ?>/attachments" enctype="multipart/form-data" class="inline-form-row">
        <?= Csrf::field() ?>
        <input aria-label="<?= e(t('common.attachments')) ?>" type="file" name="file" required>
        <button type="submit" class="btn"><?= icon('upload') ?><?= e(t('common.upload')) ?></button>
    </form>
</section>
<?php endif; ?>

<section class="card">
    <h2><?= e(t('common.notes')) ?></h2>
    <form method="post" action="<?= e($base) ?>/notes" class="card--form" style="max-width:100%">
        <?= Csrf::field() ?>
        <textarea name="body" rows="3" placeholder="<?= e(t('common.write_a_note')) ?>" required></textarea>
        <button type="submit" class="btn btn--primary"><?= icon('plus') ?><?= e(t('common.add_note')) ?></button>
    </form>
    <ul class="note-list">
        <?php foreach ($notes as $note): ?>
            <li>
                <p><?= nl2br(e($note['body'])) ?></p>
                <span class="muted"><?= e($note['created_at']) ?></span>
                <form method="post" action="<?= e($base) ?>/notes/<?= e($note['id']) ?>/delete" class="inline-form">
                    <?= Csrf::field() ?>
                    <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                </form>
            </li>
        <?php endforeach; ?>
        <?php if ($notes === []): ?><li class="muted"><?= e(t('common.no_notes_yet')) ?></li><?php endif; ?>
    </ul>
</section>

<section class="card">
    <h2><?= e(t('common.timeline')) ?></h2>
    <ul class="timeline-list">
        <?php foreach ($timeline as $event): ?>
            <li><span class="muted"><?= e($event['created_at']) ?></span> — <?= e($event['description']) ?></li>
        <?php endforeach; ?>
        <?php if ($timeline === []): ?><li class="muted"><?= e(t('common.nothing_yet')) ?></li><?php endif; ?>
    </ul>
</section>
