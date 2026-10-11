<?php
/** @var array|null $draft */
/** @var array $colleagues */
/** @var array $errors */
/** @var array $old */
/** @var string|null $linkableType */
/** @var int|null $linkableId */
/** @var array $attachments */
/** @var array|null $forward forward source: message, sender_name, attachments */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$fieldError = fn (string $field) => isset($errors[$field]) ? t((string) $errors[$field][0]) : null;
// $old['to']/'cc'/'bcc' is a comma-string when loaded from a saved
// draft, but an array when re-displaying a failed submission — this
// normalizes either shape to a comma-string for data-selected below.
$selectedIds = fn (string $key) => is_array($old[$key] ?? null)
    ? implode(',', array_map('intval', $old[$key]))
    : (string) ($old[$key] ?? '');
$isDraft = $draft !== null;
$forward = $forward ?? null;
$isForward = $forward !== null;
$action = $isDraft ? '/mail/drafts/' . $draft['id'] : ($isForward ? '/mail/forward/' . $forward['message']['id'] : '/mail/compose');
$forwardChecked = array_map('intval', (array) ($old['forward_attachments'] ?? []));
$peopleJson = json_encode(array_map(
    fn ($c) => ['id' => (int) $c['id'], 'name' => trim($c['first_name'] . ' ' . ($c['last_name'] ?? ''))],
    array_values($colleagues)
), JSON_UNESCAPED_UNICODE);
?>
<script type="application/json" id="mail-colleagues-data"><?= $peopleJson ?></script>

<section class="card card--form card--narrow">
    <h1><?= $isDraft ? e(t('mail.edit_draft')) : ($isForward ? e(t('mail.forward_title')) : e(t('mail.compose_message'))) ?></h1>
    <?php if ($isForward): ?>
        <p class="muted"><?= e(t('mail.forward_hint')) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <?php if ($linkableType !== null && $linkableId !== null): ?>
            <input type="hidden" name="linkable_type" value="<?= e($linkableType) ?>">
            <input type="hidden" name="linkable_id" value="<?= e($linkableId) ?>">
        <?php endif; ?>

        <label><?= e(t('mail.to')) ?></label>
        <input type="text" class="crm-recipient-picker" data-name="to[]" data-source="mail-colleagues-data" data-selected="<?= e($selectedIds('to')) ?>" placeholder="<?= e(t('mail.recipient_placeholder')) ?>">
        <?php if ($fieldError('recipients')): ?><div class="field-error"><?= e($fieldError('recipients')) ?></div><?php endif; ?>

        <label><?= e(t('mail.cc')) ?></label>
        <input type="text" class="crm-recipient-picker" data-name="cc[]" data-source="mail-colleagues-data" data-selected="<?= e($selectedIds('cc')) ?>" placeholder="<?= e(t('mail.recipient_placeholder')) ?>">

        <label><?= e(t('mail.bcc')) ?></label>
        <input type="text" class="crm-recipient-picker" data-name="bcc[]" data-source="mail-colleagues-data" data-selected="<?= e($selectedIds('bcc')) ?>" placeholder="<?= e(t('mail.recipient_placeholder')) ?>">
        <div class="field-hint"><?= e(t('mail.bcc_hint')) ?></div>

        <label for="subject"><?= e(t('mail.subject')) ?></label>
        <input type="text" id="subject" name="subject" value="<?= e($old['subject'] ?? '') ?>" required>
        <?php if ($fieldError('subject')): ?><div class="field-error"><?= e($fieldError('subject')) ?></div><?php endif; ?>

        <label for="body"><?= e(t('mail.message')) ?></label>
        <textarea id="body" name="body" rows="6" required><?= e($old['body'] ?? '') ?></textarea>
        <?php if ($fieldError('body')): ?><div class="field-error"><?= e($fieldError('body')) ?></div><?php endif; ?>

        <?php if ($isForward && $forward['attachments'] !== []): ?>
            <fieldset class="forward-attachments">
                <legend><?= e(t('mail.forward_attachments')) ?></legend>
                <?php foreach ($forward['attachments'] as $a): ?>
                    <label class="check check--block">
                        <input type="checkbox" name="forward_attachments[]" value="<?= e($a['id']) ?>"<?= in_array((int) $a['id'], $forwardChecked, true) ? ' checked' : '' ?>>
                        <span><?= icon('paperclip', 'icon--sm') ?><?= e($a['original_filename']) ?> <span class="muted">(<?= e(number_format($a['size'] / 1024, 0)) ?> KB)</span></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>

        <label for="attachment_files"><?= e(t('mail.attachments')) ?></label>
        <input type="file" id="attachment_files" name="attachments[]" multiple>
        <?php if ($fieldError('attachments')): ?><div class="field-error"><?= e($fieldError('attachments')) ?></div><?php endif; ?>
        <?php if ($attachments !== []): ?>
            <div class="field-hint"><?= e(t('mail.already_attached_hint')) ?></div>
        <?php endif; ?>

        <label class="check check--spaced">
            <input type="checkbox" name="notify" value="1"> <?= e(t('mail.notify_checkbox')) ?>
        </label>

        <div class="form-actions">
            <button type="submit" name="intent" value="send" class="btn btn--primary"><?= icon('send') ?><?= e(t('mail.send')) ?></button>
            <?php if (!$isForward): ?>
                <button type="submit" name="intent" value="save_draft" class="btn"><?= icon('save') ?><?= e(t('mail.save_draft')) ?></button>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($isDraft && $attachments !== []): ?>
        <div class="form-actions">
            <?php foreach ($attachments as $a): ?>
                <form method="post" action="/mail/attachments/<?= e($a['id']) ?>/delete" class="inline-form">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="return_to" value="/mail/drafts/<?= e($draft['id']) ?>/edit">
                    <button type="submit" class="link-button"><?= icon('x') ?><?= e(t('mail.remove_attachment')) ?> — <?= e($a['original_filename']) ?></button>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($isDraft): ?>
        <form method="post" action="/mail/drafts/<?= e($draft['id']) ?>/delete" class="form-actions">
            <?= Csrf::field() ?>
            <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('mail.delete_draft')) ?></button>
        </form>
    <?php endif; ?>
</section>
