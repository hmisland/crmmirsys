<?php
/** @var array|null $campaign */
/** @var array $old */
/** @var array<string, string[]> $errors */
/** @var string[] $channels */
/** @var string $channel */
/** @var array $templates */
/** @var string[] $emailProviders */
/** @var array $groups */
/** @var array $tags */
/** @var array $contactOptions */
/** @var string[] $variables */
/** @var bool $canSeeContacts */
/** @var bool $canTransactional */
/** @var array $segments */

use App\Infrastructure\Security\Csrf;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\help_link;

$isNew = $campaign === null;
$action = $isNew ? '/marketing/campaigns' : '/marketing/campaigns/' . (int) $campaign['id'];
$err = fn (string $field): ?string => isset($errors[$field][0]) ? t($errors[$field][0]) : null;
$val = fn (string $field): string => (string) ($old[$field] ?? '');
$audienceType = $val('audience_type') ?: 'contacts';
$selectedContacts = implode(',', array_map('intval', (array) ($old['contact_ids'] ?? [])));
$active = 'campaigns';
?>
<script type="application/json" id="marketing-contacts-data"><?= json_encode($contactOptions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<div class="page-head">
    <div><h1><?= e($isNew ? t('marketing.new_campaign') : t('marketing.edit_campaign')) ?><?= help_link($channel === 'sms' ? (\App\Infrastructure\Localization\Locale::get()?->locale() === 'fa' ? 'sms-first-campaign' : 'marketing-email-basics') : 'marketing-email-writing') ?></h1></div>
</div>

<?php include __DIR__ . '/../_nav.php'; ?>

<?php if ($channels === []): ?>
    <div class="alert alert--warning"><?= e(t('marketing.error.module_unavailable')) ?></div>
<?php else: ?>

<?php if ($isNew): ?>
<section class="card">
    <h2><?= e(t('marketing.choose_channel')) ?></h2>
    <div class="actions">
        <?php foreach ($channels as $ch): ?>
            <a href="/marketing/campaigns/create?channel=<?= e($ch) ?>" class="btn<?= $ch === $channel ? ' btn--primary' : '' ?>"<?= $ch === $channel ? ' aria-current="page"' : '' ?>><?= icon($ch === 'sms' ? 'message' : ($ch === 'email' ? 'mail' : 'send')) ?><?= e(t('marketing.channel.' . $ch)) ?></a>
        <?php endforeach; ?>
    </div>
    <?php if ($templates !== []): ?>
        <form method="get" action="/marketing/campaigns/create" class="inline-form-row">
            <input type="hidden" name="channel" value="<?= e($channel) ?>">
            <label for="template-pick" class="visually-hidden"><?= e(t('marketing.load_template')) ?></label>
            <select id="template-pick" name="template">
                <?php foreach ($templates as $tpl): ?>
                    <option value="<?= e($tpl['id']) ?>"><?= e($tpl['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn"><?= icon('file-text') ?><?= e(t('marketing.load_template')) ?></button>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="card card--form">
    <form method="post" action="<?= e($action) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="channel" value="<?= e($channel) ?>">

        <?php if ($err('channel')): ?><div class="alert alert--error"><?= e($err('channel')) ?></div><?php endif; ?>

        <label for="name"><?= e(t('marketing.campaign_name')) ?></label>
        <input type="text" id="name" name="name" required maxlength="150" value="<?= e($val('name')) ?>">
        <?php if ($err('name')): ?><div class="field-error"><?= e($err('name')) ?></div><?php endif; ?>

        <label for="message_class"><?= e(t('marketing.message_class')) ?><?= help_link('marketing-transactional-vs-marketing') ?></label>
        <select id="message_class" name="message_class">
            <?php foreach ($canTransactional || $val('message_class') === 'transactional' ? ['marketing', 'transactional'] : ['marketing'] as $class): ?>
                <option value="<?= e($class) ?>" <?= ($val('message_class') ?: 'marketing') === $class ? 'selected' : '' ?>><?= e(t('marketing.class.' . $class)) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="field-hint"><?= e(t('marketing.message_class_hint')) ?></div>
        <?php if ($err('message_class')): ?><div class="field-error"><?= e($err('message_class')) ?></div><?php endif; ?>

        <?php if ($channel === 'email'): ?>
            <label for="provider"><?= e(t('marketing.email_provider')) ?></label>
            <select id="provider" name="provider">
                <?php foreach ($emailProviders as $p): ?>
                    <option value="<?= e($p) ?>" <?= $val('provider') === $p ? 'selected' : '' ?>><?= e(ucfirst($p)) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($emailProviders === []): ?><div class="field-hint"><?= e(t('marketing.error.email_not_configured')) ?> <a href="/org/messaging"><?= e(t('messaging.title')) ?></a></div><?php endif; ?>
            <?php if ($err('provider')): ?><div class="field-error"><?= e($err('provider')) ?></div><?php endif; ?>

            <label for="subject"><?= e(t('marketing.subject')) ?></label>
            <input type="text" id="subject" name="subject" required maxlength="255" value="<?= e($val('subject')) ?>">
            <?php if ($err('subject')): ?><div class="field-error"><?= e($err('subject')) ?></div><?php endif; ?>

            <label for="body_html"><?= e(t('marketing.body_html')) ?></label>
            <textarea id="body_html" name="body_html" rows="12" dir="auto" required><?= e($val('body_html')) ?></textarea>
            <?php if ($err('body_html')): ?><div class="field-error"><?= e($err('body_html')) ?></div><?php endif; ?>

            <label for="body_text"><?= e(t('marketing.body_text_optional')) ?></label>
            <textarea id="body_text" name="body_text" rows="5" dir="auto"><?= e($val('body_text')) ?></textarea>
        <?php elseif (($phoneChannel = \Modules\Marketing\Channels\ChannelRegistry::phoneChannel($channel)) !== null): ?>
            <label for="body_text"><?= e(t('marketing.channel_text', ['channel' => t('marketing.channel.' . $channel)])) ?></label>
            <textarea id="body_text" name="body_text" rows="6" dir="auto" required maxlength="<?= e((string) $phoneChannel->maxTextLength()) ?>"><?= e($val('body_text')) ?></textarea>
            <div class="field-hint"><?= e(t('marketing.channel_text_hint.' . $channel, ['max' => number_format($phoneChannel->maxTextLength())])) ?></div>
        <?php else: ?>
            <label for="body_text"><?= e(t('marketing.sms_text')) ?></label>
            <textarea id="body_text" name="body_text" rows="5" dir="auto" required maxlength="900"><?= e($val('body_text')) ?></textarea>
            <div class="field-hint"><?= e(t('marketing.sms_text_hint')) ?></div>
        <?php endif; ?>
        <?php if ($err('body_text')): ?><div class="field-error"><?= e($err('body_text')) ?></div><?php endif; ?>
        <div class="field-hint"><?= e(t('marketing.variables_hint')) ?> <span class="ltr"><?= e(implode('  ', array_map(fn ($v) => '{{' . $v . '}}', $variables))) ?></span></div>

        <h3 class="form-section-title"><?= e(t('marketing.audience')) ?></h3>
        <?php if ($err('audience')): ?><div class="alert alert--error"><?= e($err('audience')) ?></div><?php endif; ?>

        <?php if ($canSeeContacts): ?>
        <label class="check check--block">
            <input type="radio" name="audience_type" value="contacts" <?= $audienceType === 'contacts' ? 'checked' : '' ?>>
            <span><strong><?= e(t('marketing.audience.contacts')) ?></strong> — <?= e(t('marketing.audience.contacts_hint')) ?></span>
        </label>
        <label for="contact-picker" class="visually-hidden"><?= e(t('marketing.audience.contacts')) ?></label>
        <input type="text" id="contact-picker" class="crm-recipient-picker" data-name="contact_ids[]" data-source="marketing-contacts-data" data-selected="<?= e($selectedContacts) ?>" placeholder="<?= e(t('mail.recipient_placeholder')) ?>">
        <?php else: ?>
        <p class="locked"><?= icon('lock') ?><?= e(t('marketing.contacts_hidden')) ?></p>
        <?php endif; ?>

        <label class="check check--block">
            <input type="radio" name="audience_type" value="group" <?= $audienceType === 'group' ? 'checked' : '' ?>>
            <span><strong><?= e(t('marketing.audience.group')) ?></strong></span>
        </label>
        <label for="group_id" class="visually-hidden"><?= e(t('marketing.audience.group')) ?></label>
        <select id="group_id" name="group_id">
            <option value="0">—</option>
            <?php foreach ($groups as $g): ?>
                <option value="<?= e($g['id']) ?>" <?= (int) ($old['group_id'] ?? 0) === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label class="check check--block">
            <input type="radio" name="audience_type" value="tag" <?= $audienceType === 'tag' ? 'checked' : '' ?>>
            <span><strong><?= e(t('marketing.audience.tag')) ?></strong> — <?= e(t('marketing.audience.tag_hint')) ?></span>
        </label>
        <label for="tag_id" class="visually-hidden"><?= e(t('marketing.audience.tag')) ?></label>
        <select id="tag_id" name="tag_id">
            <option value="0">—</option>
            <?php foreach ($tags as $tg): ?>
                <option value="<?= e($tg['id']) ?>" <?= (int) ($old['tag_id'] ?? 0) === (int) $tg['id'] ? 'selected' : '' ?>><?= e($tg['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label class="check check--block">
            <input type="radio" name="audience_type" value="segment" <?= $audienceType === 'segment' ? 'checked' : '' ?>>
            <span><strong><?= e(t('marketing.audience.segment')) ?></strong> — <?= e(t('marketing.audience.segment_hint')) ?></span>
        </label>
        <label for="segment_id" class="visually-hidden"><?= e(t('marketing.audience.segment')) ?></label>
        <select id="segment_id" name="segment_id">
            <option value="0">—</option>
            <?php foreach ($segments as $sg): ?>
                <option value="<?= e($sg['id']) ?>" <?= (int) ($old['segment_id'] ?? 0) === (int) $sg['id'] ? 'selected' : '' ?>><?= e($sg['name']) ?> (<?= e(t('marketing.segment.type.' . $sg['type'])) ?>)</option>
            <?php endforeach; ?>
        </select>
        <?php if ($segments === []): ?><div class="field-hint"><?= e(t('marketing.segment.none_yet')) ?> <a href="/marketing/segments"><?= e(t('marketing.nav.segments')) ?></a></div><?php endif; ?>

        <label class="check check--block">
            <input type="radio" name="audience_type" value="all" <?= $audienceType === 'all' ? 'checked' : '' ?>>
            <span><strong><?= e(t('marketing.audience.all')) ?></strong> — <?= e(t('marketing.audience.all_hint')) ?></span>
        </label>

        <div class="actions">
            <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('marketing.save_and_review')) ?></button>
            <a href="<?= e($isNew ? '/marketing/campaigns' : '/marketing/campaigns/' . (int) $campaign['id']) ?>" class="btn btn--ghost"><?= icon('x') ?><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</section>
<?php endif; ?>
