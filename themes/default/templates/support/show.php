<?php
/** @var array $ticket */
/** @var array|null $contact */
/** @var array $replies */
/** @var array $attachments */
/** @var array $statuses */
/** @var array $users */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e($ticket['subject']) ?></h1>
    <p class="muted">
        <?= e(t('tickets.priority')) ?>: <?= e(t('tickets.priority.' . $ticket['priority'])) ?>
        <?= $contact !== null ? ' · ' . e(t('common.contact')) . ': ' : '' ?>
        <?php if ($contact !== null): ?><a href="/contacts/<?= e($contact['id']) ?>"><?= e(trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''))) ?></a><?php endif; ?>
    </p>
    <?php if (!empty($ticket['description'])): ?>
        <p><?= nl2br(e($ticket['description'])) ?></p>
    <?php endif; ?>

    <form method="post" action="/support/tickets/<?= e($ticket['id']) ?>/status" class="inline-form-row">
        <?= Csrf::field() ?>
        <input type="hidden" name="return_to" value="/support/tickets/<?= e($ticket['id']) ?>">
        <label style="margin:0"><?= e(t('deals.status')) ?>:</label>
        <select aria-label="<?= e(t('deals.status')) ?>" name="status" onchange="this.form.submit()">
            <?php foreach ($statuses as $s): ?>
                <option value="<?= e($s) ?>" <?= $s === $ticket['status'] ? 'selected' : '' ?>><?= e(t('tickets.status.' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <form method="post" action="/support/tickets/<?= e($ticket['id']) ?>/assign" class="inline-form-row">
        <?= Csrf::field() ?>
        <label style="margin:0"><?= e(t('tickets.assigned_to')) ?>:</label>
        <select aria-label="<?= e(t('tickets.assignee')) ?>" name="assigned_to_user_id" onchange="this.form.submit()">
            <option value=""><?= e(t('tickets.unassigned')) ?></option>
            <?php foreach ($users as $u): ?>
                <option value="<?= e($u['id']) ?>" <?= (int) $u['id'] === (int) ($ticket['assigned_to_user_id'] ?? 0) ? 'selected' : '' ?>>
                    <?= e(trim($u['first_name'] . ' ' . ($u['last_name'] ?? ''))) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <div class="actions">
        <a href="/support/tickets/<?= e($ticket['id']) ?>/edit" class="btn"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
        <form method="post" action="/support/tickets/<?= e($ticket['id']) ?>/delete" class="inline-form">
            <?= Csrf::field() ?>
            <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
        </form>
    </div>
</section>

<section class="card">
    <h2><?= e(t('common.attachments')) ?></h2>
    <ul class="attachment-list">
        <?php foreach ($attachments as $a): ?>
            <li>
                <a href="/support/tickets/<?= e($ticket['id']) ?>/attachments/<?= e($a['id']) ?>/download"><?= e($a['filename']) ?></a>
                <span class="muted">(<?= e(round($a['size'] / 1024)) ?> KB)</span>
                <form method="post" action="/support/tickets/<?= e($ticket['id']) ?>/attachments/<?= e($a['id']) ?>/delete" class="inline-form">
                    <?= Csrf::field() ?>
                    <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                </form>
            </li>
        <?php endforeach; ?>
        <?php if ($attachments === []): ?><li class="muted"><?= e(t('common.no_attachments_yet')) ?></li><?php endif; ?>
    </ul>
    <form method="post" action="/support/tickets/<?= e($ticket['id']) ?>/attachments" enctype="multipart/form-data" class="inline-form-row">
        <?= Csrf::field() ?>
        <input aria-label="<?= e(t('common.attachments')) ?>" type="file" name="file" required>
        <button type="submit" class="btn"><?= icon('upload') ?><?= e(t('common.upload')) ?></button>
    </form>
</section>

<section class="card">
    <h2><?= e(t('tickets.replies')) ?></h2>
    <ul class="note-list">
        <?php foreach ($replies as $reply): ?>
            <li<?= $reply['is_internal'] ? ' class="note--internal"' : '' ?>>
                <p><?= nl2br(e($reply['body'])) ?></p>
                <span class="muted">
                    <?= e($reply['author_name'] ?? '') ?> · <?= e($reply['created_at']) ?>
                    <?= $reply['is_internal'] ? ' · ' . e(t('tickets.internal_note')) : '' ?>
                </span>
            </li>
        <?php endforeach; ?>
        <?php if ($replies === []): ?><li class="muted"><?= e(t('tickets.no_replies_yet')) ?></li><?php endif; ?>
    </ul>

    <form method="post" action="/support/tickets/<?= e($ticket['id']) ?>/replies">
        <?= Csrf::field() ?>
        <textarea name="body" rows="3" required placeholder="<?= e(t('tickets.write_a_reply')) ?>"></textarea>
        <label class="check check--spaced">
            <input type="checkbox" name="is_internal" value="1"> <?= e(t('tickets.internal_note_hint')) ?>
        </label>
        <br>
        <button type="submit" class="btn btn--primary" style="margin-top:0.5rem"><?= icon('reply') ?><?= e(t('tickets.add_reply')) ?></button>
    </form>
</section>
