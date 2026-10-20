<?php
/**
 * Organization → new ticket to the platform team.
 *
 * @var array $errors
 * @var array $old
 * @var string[] $categories
 * @var string[] $priorities
 */

use App\Application\Services\PlatformTicketService;
use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\t;

$error = fn (string $field): ?string => isset($errors[$field]) ? t((string) $errors[$field][0]) : null;
?>
<div class="page-head">
    <div>
        <h1><?= e(t('platform_tickets.new')) ?></h1>
        <p><?= e(t('platform_tickets.form_subtitle')) ?></p>
    </div>
    <div class="actions">
        <a href="/org/tickets" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('platform_tickets.back')) ?></a>
    </div>
</div>

<?php if ($error('ticket') !== null): ?><div class="alert alert--error"><?= e($error('ticket')) ?></div><?php endif; ?>

<section class="card card--form">
    <form method="post" action="/org/tickets" enctype="multipart/form-data">
        <?= Csrf::field() ?>

        <label for="ticket-subject"><?= e(t('platform_tickets.field.subject')) ?></label>
        <input type="text" id="ticket-subject" name="subject" maxlength="<?= e(PlatformTicketService::SUBJECT_MAX) ?>" required value="<?= e((string) ($old['subject'] ?? '')) ?>">
        <?php if ($error('subject') !== null): ?><div class="field-error"><?= e($error('subject')) ?></div><?php endif; ?>

        <label for="ticket-category"><?= e(t('platform_tickets.field.category')) ?></label>
        <select id="ticket-category" name="category" required>
            <?php foreach ($categories as $c): ?>
                <option value="<?= e($c) ?>"<?= ($old['category'] ?? 'general') === $c ? ' selected' : '' ?>><?= e(t('platform_tickets.category.' . $c)) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($error('category') !== null): ?><div class="field-error"><?= e($error('category')) ?></div><?php endif; ?>

        <label for="ticket-priority"><?= e(t('platform_tickets.field.priority')) ?></label>
        <select id="ticket-priority" name="priority">
            <?php foreach ($priorities as $p): ?>
                <option value="<?= e($p) ?>"<?= ($old['priority'] ?? 'normal') === $p ? ' selected' : '' ?>><?= e(t('platform_tickets.priority.' . $p)) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($error('priority') !== null): ?><div class="field-error"><?= e($error('priority')) ?></div><?php endif; ?>

        <label for="ticket-body"><?= e(t('platform_tickets.field.body')) ?></label>
        <textarea id="ticket-body" name="body" rows="8" maxlength="<?= e(PlatformTicketService::BODY_MAX) ?>" required><?= e((string) ($old['body'] ?? '')) ?></textarea>
        <?php if ($error('body') !== null): ?><div class="field-error"><?= e($error('body')) ?></div><?php endif; ?>
        <p class="field-hint"><?= e(t('platform_tickets.body_hint')) ?></p>

        <label for="ticket-attachments"><?= e(t('platform_tickets.field.attachments')) ?></label>
        <input type="file" id="ticket-attachments" name="attachments[]" multiple accept=".txt,.csv,.jpg,.jpeg,.png,.webp,text/plain,text/csv,image/jpeg,image/png,image/webp">
        <p class="field-hint"><?= e(t('platform_tickets.attachments_hint')) ?></p>
        <?php if ($error('attachments') !== null): ?><div class="field-error"><?= e($error('attachments')) ?></div><?php endif; ?>

        <div class="actions">
            <button type="submit" class="btn btn--primary"><?= icon('send') ?><?= e(t('platform_tickets.send')) ?></button>
        </div>
    </form>
</section>
