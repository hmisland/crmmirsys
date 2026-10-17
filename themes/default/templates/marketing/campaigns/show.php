<?php
/** @var array $campaign */
/** @var array|null $review */
/** @var array|null $counts */
/** @var array $recipients */
/** @var array $filters */
/** @var \App\Infrastructure\Pagination\Pagination $pagination */
/** @var bool $canEdit */
/** @var bool $canSend */
/** @var bool $canTest */
/** @var array<string, string[]> $errors */
/** @var bool $canSeeContacts */
/** @var bool $requiresConsent */
/** @var bool $canRetryUnknown */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\help_link;

$id = (int) $campaign['id'];
$isDraft = $campaign['status'] === 'draft';
$isEmail = $campaign['channel'] === 'email';
// Only the built-in channels have provider delivery reports (Kavenegar status, Brevo webhooks); Bale/Safir has none.
$hasDeliveryReports = in_array($campaign['channel'], ['sms', 'email'], true);
$retryUnknownBase = !empty($canRetryUnknown) ? '/marketing/campaigns/' . (int) $campaign['id'] . '/messages/' : null;
$active = 'campaigns';
$firstError = null;
foreach ($errors as $list) {
    $firstError ??= $list[0] ?? null;
}
$status = $counts['status'] ?? [];
$delivery = $counts['delivery'] ?? [];
$total = (int) ($counts['total'] ?? 0);
$done = $total - (int) ($status['pending'] ?? 0) - (int) ($status['processing'] ?? 0);
$preparingInBackground = $campaign['status'] === 'preparing' && $campaign['prepare_cursor'] !== null;
$running = in_array($campaign['status'], ['queued', 'sending'], true) || $preparingInBackground;
?>
<div class="page-head">
    <div>
        <h1><?= e($campaign['name']) ?><?= help_link('marketing-campaign-results') ?></h1>
        <p>
            <?= badge(t('marketing.status.' . $campaign['status']), $campaign['status']) ?>
            <?= e(t('marketing.channel.' . $campaign['channel'])) ?><?= $campaign['provider'] ? ' · ' . e(ucfirst((string) $campaign['provider'])) : '' ?>
            · <?= e(t('marketing.class.' . $campaign['message_class'])) ?>
        </p>
    </div>
    <div class="actions">
        <?php if ($canEdit): ?>
            <a href="/marketing/campaigns/<?= $id ?>/edit" class="btn"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
            <form method="post" action="/marketing/campaigns/<?= $id ?>/delete">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
            </form>
        <?php endif; ?>
        <?php if ($canSend && in_array($campaign['status'], ['preparing', 'queued', 'sending'], true)): ?>
            <form method="post" action="/marketing/campaigns/<?= $id ?>/cancel">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--danger" data-confirm="<?= e(t('marketing.cancel_confirm')) ?>"><?= icon('ban') ?><?= e(t('marketing.cancel_campaign')) ?></button>
            </form>
        <?php endif; ?>
        <?php if ($canSend && in_array($campaign['status'], ['sending', 'completed'], true) && (int) ($status['failed'] ?? 0) > 0): ?>
            <form method="post" action="/marketing/campaigns/<?= $id ?>/retry">
                <?= Csrf::field() ?>
                <button type="submit" class="btn" data-confirm="<?= e(t('marketing.retry_confirm')) ?>"><?= icon('refresh') ?><?= e(t('marketing.retry_failed')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../_nav.php'; ?>

<?php if ($firstError !== null): ?>
    <div class="alert alert--error"><?= e(t($firstError)) ?></div>
<?php endif; ?>
<?php if (!empty($campaign['last_error'])): ?>
    <div class="alert alert--warning"><?= e(t((string) $campaign['last_error'])) ?></div>
<?php endif; ?>

<?php if ($isDraft && $review !== null): ?>
    <?php $totals = $review['totals']; ?>
    <section class="card">
        <h2><?= e(t('marketing.review_title')) ?></h2>
        <?php if ($review['channel_error'] !== null): ?>
            <div class="alert alert--error"><?= e(t($review['channel_error'])) ?> <a href="/org/messaging"><?= e(t('messaging.title')) ?></a></div>
        <?php endif; ?>

        <dl class="fact-row">
            <div class="fact"><dt><?= icon('users') ?><?= e(t('marketing.estimate_matching')) ?> — <?= e(t('marketing.audience.' . $campaign['audience_type'])) ?></dt><dd><?= e(number_format($totals['contacts'])) ?></dd></div>
            <?php if (!$review['large']): ?>
            <div class="fact"><dt><?= icon('check-circle') ?><?= e(t('marketing.eligible')) ?></dt><dd><strong><?= e(number_format($totals['eligible'])) ?></strong></dd></div>
            <div class="fact"><dt><?= icon('alert-triangle') ?><?= e(t('marketing.missing_address')) ?></dt><dd><?= e(number_format($totals['missing_address'])) ?></dd></div>
            <div class="fact"><dt><?= icon('x-circle') ?><?= e(t('marketing.invalid_address')) ?></dt><dd><?= e(number_format($totals['invalid_address'])) ?></dd></div>
            <div class="fact"><dt><?= icon('ban') ?><?= e(t('marketing.suppressed')) ?></dt><dd><?= e(number_format($totals['suppressed'])) ?></dd></div>
            <div class="fact"><dt><?= icon('layers') ?><?= e(t('marketing.duplicates')) ?></dt><dd><?= e(number_format($totals['duplicate'])) ?></dd></div>
            <?php endif; ?>
            <?php if (!$review['large'] && (int) ($totals['unsupported'] ?? 0) > 0): ?>
            <div class="fact"><dt><?= icon('ban') ?><?= e(t('marketing.unsupported')) ?></dt><dd><?= e(number_format((int) $totals['unsupported'])) ?></dd></div>
            <?php endif; ?>
            <?php if ($campaign['channel'] === 'sms'): ?>
                <div class="fact"><dt><?= icon('message') ?><?= e(t('marketing.sms_parts')) ?></dt><dd><?= e((string) $review['sms_parts']) ?> × <?= e(number_format($totals['eligible'])) ?></dd></div>
            <?php endif; ?>
        </dl>

        <p class="field-hint"><?= e(t('marketing.estimate_hint')) ?></p>
        <?php foreach ($review['warnings'] as $warning): ?>
            <div class="alert alert--warning"><?= e(t($warning)) ?></div>
        <?php endforeach; ?>

        <h3 class="form-section-title"><?= e(t('marketing.preview')) ?></h3>
        <?php if ($review['masked']): ?>
            <p class="field-hint"><?= e(t('marketing.preview_masked')) ?></p>
        <?php elseif ($totals['sample'] !== null): ?>
            <p class="field-hint"><?= e(t('marketing.preview_for', ['name' => (string) $totals['sample']['recipient_name']])) ?></p>
        <?php endif; ?>
        <?php if ($isEmail): ?>
            <p><strong><?= e(t('marketing.subject')) ?>:</strong> <?= e($review['preview']['subject']) ?></p>
            <iframe class="email-preview" sandbox title="<?= e(t('marketing.preview')) ?>" srcdoc="<?= e($review['preview']['html']) ?>"></iframe>
        <?php else: ?>
            <div class="message-preview"><?= e($review['preview']['text']) ?></div>
        <?php endif; ?>
    </section>

    <?php if ($canTest): ?>
    <section class="card card--form">
        <h2><?= e(t('marketing.test_send')) ?></h2>
        <p class="muted"><?= e(t('marketing.test_send_hint')) ?></p>
        <form method="post" action="/marketing/campaigns/<?= $id ?>/test" class="inline-form-row">
            <?= Csrf::field() ?>
            <label for="test-address" class="visually-hidden"><?= e(t('marketing.test_address')) ?></label>
            <input type="<?= $isEmail ? 'email' : 'tel' ?>" id="test-address" name="address" dir="ltr" required placeholder="<?= e($isEmail ? 'name@example.com' : '09xxxxxxxxx') ?>">
            <button type="submit" class="btn"><?= icon('send') ?><?= e(t('marketing.send_test')) ?></button>
        </form>
    </section>
    <?php endif; ?>

    <?php if ($canSend): ?>
    <section class="card card--form">
        <h2><?= e(t('marketing.approve_title')) ?></h2>
        <form method="post" action="/marketing/campaigns/<?= $id ?>/send">
            <?= Csrf::field() ?>
            <input type="hidden" name="confirmed_count" value="<?= e((string) $review['confirm_count']) ?>">
            <p><?= e(t($review['large'] ? 'marketing.approve_text_large' : 'marketing.approve_text', ['count' => number_format($review['confirm_count'])])) ?></p>
            <?php if ($campaign['audience_type'] === 'all'): ?>
                <label class="check"><input type="checkbox" name="confirm_all" value="1" required> <?= e(t('marketing.confirm_all', ['count' => number_format($review['confirm_count'])])) ?></label>
                <?php if (isset($errors['confirm_all'])): ?><div class="field-error"><?= e(t($errors['confirm_all'][0])) ?></div><?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($requiresConsent)): ?>
                <label class="check"><input type="checkbox" name="confirm_consent" value="1" required> <?= e(t('marketing.confirm_consent', ['channel' => t('marketing.channel.' . $campaign['channel'])])) ?></label>
                <?php if (isset($errors['confirm_consent'])): ?><div class="field-error"><?= e(t($errors['confirm_consent'][0])) ?></div><?php endif; ?>
            <?php endif; ?>
            <button type="submit" class="btn btn--primary" data-confirm="<?= e(t('marketing.send_confirm', ['count' => number_format($review['confirm_count'])])) ?>"<?= $review['confirm_count'] === 0 || $review['channel_error'] !== null ? ' disabled' : '' ?>><?= icon('send') ?><?= e(t('marketing.approve_and_send')) ?></button>
        </form>
    </section>
    <?php else: ?>
        <p class="locked"><?= icon('lock') ?><?= e(t('marketing.needs_approval')) ?></p>
    <?php endif; ?>

<?php else: ?>
    <section class="card marketing-progress"<?= $running ? ' data-marketing-progress="/marketing/campaigns/' . $id . '/process" data-csrf="' . e(\App\Infrastructure\Security\Csrf::token()) . '"' : '' ?>>
        <h2><?= e(t('marketing.progress')) ?></h2>
        <?php if (!$preparingInBackground): ?>
            <p class="field-hint"><?= e(t('marketing.final_recipients', ['count' => number_format((int) $campaign['total_recipients'])])) ?></p>
        <?php endif; ?>
        <progress class="progress<?= $running ? '' : ' progress--done' ?>" max="<?= max(1, $total) ?>" value="<?= $done ?>"></progress>
        <dl class="fact-row">
            <?php foreach (['pending', 'sent', 'failed', 'skipped', 'cancelled'] as $key): ?>
                <div class="fact"><dt><?= e(t('marketing.message_status.' . $key)) ?></dt><dd data-count="<?= e($key) ?>"><?= e(number_format((int) ($status[$key] ?? 0) + ($key === 'pending' ? (int) ($status['processing'] ?? 0) : 0))) ?></dd></div>
            <?php endforeach; ?>
            <div class="fact"><dt><?= e(t('marketing.unknown_outcome')) ?></dt><dd data-count="unknown"><?= e(number_format((int) ($counts['unknown'] ?? 0))) ?></dd></div>
            <?php if ($hasDeliveryReports): ?>
            <div class="fact"><dt><?= e(t('marketing.delivery.delivered')) ?></dt><dd data-count="delivered"><?= e(number_format((int) ($delivery['delivered'] ?? 0))) ?></dd></div>
            <?php else: ?>
            <div class="fact"><dt><?= e(t('marketing.unsupported')) ?></dt><dd data-count="unsupported"><?= e(number_format((int) ($counts['unsupported'] ?? 0))) ?></dd></div>
            <?php endif; ?>
        </dl>
        <?php if ($preparingInBackground): ?>
            <p class="field-hint" data-progress-note><?= e(t('marketing.preparing_hint')) ?></p>
        <?php elseif ($running): ?>
            <p class="field-hint" data-progress-note><?= e(t('marketing.progress_hint')) ?></p>
        <?php endif; ?>
        <p class="field-hint"><?= e(t($hasDeliveryReports ? 'marketing.sent_vs_delivered' : 'marketing.accepted_not_delivered.' . $campaign['channel'])) ?></p>
    </section>

    <section class="card">
        <h2><?= e(t('marketing.recipients')) ?></h2>
        <form method="get" action="/marketing/campaigns/<?= $id ?>" class="inline-form-row filter-bar">
            <input type="text" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('common.search')) ?>" aria-label="<?= e(t('common.search')) ?>">
            <button type="submit" class="btn"><?= icon('search') ?><?= e(t('common.search')) ?></button>
            <select name="status" aria-label="<?= e(t('marketing.status_label')) ?>">
                <option value=""><?= e(t('marketing.all_statuses')) ?></option>
                <?php foreach (['pending', 'processing', 'sent', 'failed', 'skipped', 'cancelled'] as $s): ?>
                    <option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e(t('marketing.message_status.' . $s)) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn"><?= icon('filter') ?><?= e(t('filters.apply')) ?></button>
            <?php if ($pagination->filterQuery() !== []): ?><a href="/marketing/campaigns/<?= $id ?>" class="btn btn--ghost"><?= icon('x') ?><?= e(t('filters.clear')) ?></a><?php endif; ?>
        </form>

        <?php include __DIR__ . '/../../partials/pagination.php'; ?>

        <?php include __DIR__ . '/../_messages_table.php'; ?>
    </section>
<?php endif; ?>
