<?php
/** @var int $rootId */
/** @var array $messages */
/** @var array $recipientRows */
/** @var array $attachmentsByMessage */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$labelFor = fn (string $type) => match ($type) {
    'cc' => t('mail.cc'),
    'bcc' => t('mail.bcc'),
    default => t('mail.to'),
};
?>
<section class="card">
    <h1><?= e($messages[0]['subject'] ?? '') ?></h1>
    <?php if ($recipientRows !== []): ?>
        <p class="muted">
            <?php foreach (['to', 'cc', 'bcc'] as $type): ?>
                <?php $names = array_filter($recipientRows, fn ($r) => $r['recipient_type'] === $type); ?>
                <?php if ($names !== []): ?>
                    <?= e($labelFor($type)) ?>: <?= e(implode(', ', array_map(fn ($r) => trim((string) ($r['recipient_name'] ?? ('#' . $r['recipient_user_id']))), $names))) ?>
                    &nbsp;
                <?php endif; ?>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>
    <p><a href="/mail" class="btn"><?= icon('arrow-left') ?><?= e(t('mail.back_to_inbox')) ?></a></p>
</section>

<?php foreach ($messages as $msg): ?>
<section class="card">
    <p class="muted">#<?= e($msg['id']) ?> · <?= e($msg['sender_name'] ?? '') ?> · <?= e($msg['created_at']) ?></p>
    <p><?= nl2br(e($msg['body'])) ?></p>
    <?php $atts = $attachmentsByMessage[$msg['id']] ?? []; ?>
    <?php if ($atts !== []): ?>
        <ul class="note-list">
            <?php foreach ($atts as $a): ?>
                <li><a href="/mail/attachments/<?= e($a['id']) ?>"><?= e($a['original_filename']) ?></a> <span class="muted">(<?= e(number_format($a['size'] / 1024, 0)) ?> KB)</span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php endforeach; ?>

<section class="card card--form">
    <h2><?= e(t('mail.reply')) ?></h2>
    <form method="post" action="/mail/thread/<?= e($rootId) ?>/reply" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <textarea name="body" rows="4" required></textarea>
        <label for="reply_attachments"><?= e(t('mail.attachments')) ?></label>
        <input type="file" id="reply_attachments" name="attachments[]" multiple>
        <label class="check check--spaced">
            <input type="checkbox" name="notify" value="1"> <?= e(t('mail.notify_checkbox_short')) ?>
        </label>
        <br>
        <button type="submit" class="btn btn--primary" style="margin-top:0.5rem"><?= icon('reply') ?><?= e(t('mail.send_reply')) ?></button>
    </form>
</section>
