<?php
/**
 * Create / edit one survey (intentionally small: one score question +
 * optional comment).
 *
 * @var array $survey
 * @var array<string, string> $errors
 */

use App\Infrastructure\Security\Csrf;
use Modules\CustomerSatisfaction\Services\SurveyScale;
use Modules\CustomerSatisfaction\Services\SurveyService;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;

$isNew = empty($survey['id']);
$action = $isNew ? '/cx/surveys' : '/cx/surveys/' . (int) $survey['id'];
$error = fn (string $field): string => isset($errors[$field]) ? '<div class="field-error">' . e(t($errors[$field])) . '</div>' : '';
?>
<div class="page-head">
    <div>
        <h1><?= e(t($isNew ? 'cx.new_survey' : 'cx.edit_survey')) ?></h1>
        <p><?= e(t('cx.form_subtitle')) ?></p>
    </div>
    <div class="actions">
        <a href="/cx" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('cx.back')) ?></a>
    </div>
</div>

<form method="post" action="<?= e($action) ?>" class="card card--form">
    <?= Csrf::field() ?>

    <label for="cx-name"><?= e(t('cx.field.name')) ?></label>
    <input type="text" id="cx-name" name="name" required maxlength="150" value="<?= e($survey['name']) ?>">
    <?= $error('name') ?>

    <label for="cx-type"><?= e(t('cx.field.type')) ?></label>
    <select id="cx-type" name="type">
        <?php foreach (SurveyScale::TYPES as $type): ?>
            <option value="<?= e($type) ?>"<?= $survey['type'] === $type ? ' selected' : '' ?>><?= e(t('cx.type.' . $type)) ?></option>
        <?php endforeach; ?>
    </select>
    <?= $error('type') ?>
    <div class="field-hint"><?= e(t('cx.type_hint')) ?></div>

    <label for="cx-question"><?= e(t('cx.field.question')) ?></label>
    <input type="text" id="cx-question" name="question_label" required maxlength="500" value="<?= e($survey['question_label']) ?>" placeholder="<?= e(t('cx.question_placeholder')) ?>">
    <?= $error('question_label') ?>

    <label>
        <input type="checkbox" name="ask_comment" value="1"<?= !empty($survey['ask_comment']) ? ' checked' : '' ?>>
        <?= e(t('cx.field.ask_comment')) ?>
    </label>
    <label for="cx-comment-label"><?= e(t('cx.field.comment_label')) ?></label>
    <input type="text" id="cx-comment-label" name="comment_label" maxlength="500" value="<?= e($survey['comment_label']) ?>" placeholder="<?= e(t('cx.public.comment_default')) ?>">

    <label for="cx-intro"><?= e(t('cx.field.intro_text')) ?></label>
    <textarea id="cx-intro" name="intro_text" maxlength="500" rows="2"><?= e($survey['intro_text'] ?? '') ?></textarea>

    <label for="cx-thanks"><?= e(t('cx.field.thank_you_text')) ?></label>
    <textarea id="cx-thanks" name="thank_you_text" maxlength="500" rows="2"><?= e($survey['thank_you_text'] ?? '') ?></textarea>

    <h3 class="form-section-title"><?= e(t('cx.sending')) ?></h3>

    <label for="cx-trigger"><?= e(t('cx.field.trigger')) ?></label>
    <select id="cx-trigger" name="trigger_event">
        <option value=""<?= $survey['trigger_event'] === null || $survey['trigger_event'] === '' ? ' selected' : '' ?>><?= e(t('cx.trigger.none')) ?></option>
        <?php foreach (SurveyService::TRIGGERS as $trigger): ?>
            <option value="<?= e($trigger) ?>"<?= $survey['trigger_event'] === $trigger ? ' selected' : '' ?>><?= e(t('cx.trigger.' . $trigger)) ?></option>
        <?php endforeach; ?>
    </select>
    <?= $error('trigger_event') ?>

    <label for="cx-delay"><?= e(t('cx.field.send_delay_minutes')) ?></label>
    <input type="number" id="cx-delay" name="send_delay_minutes" min="0" max="43200" value="<?= e((string) (int) $survey['send_delay_minutes']) ?>">
    <?= $error('send_delay_minutes') ?>

    <label for="cx-valid"><?= e(t('cx.field.link_valid_days')) ?></label>
    <input type="number" id="cx-valid" name="link_valid_days" min="1" max="90" value="<?= e((string) (int) $survey['link_valid_days']) ?>">
    <?= $error('link_valid_days') ?>

    <label for="cx-channel"><?= e(t('cx.field.channel')) ?></label>
    <select id="cx-channel" name="channel">
        <option value=""><?= e(t('cx.channel.auto')) ?></option>
        <?php foreach (SurveyService::CHANNELS as $channel): ?>
            <option value="<?= e($channel) ?>"<?= ($survey['channel'] ?? '') === $channel ? ' selected' : '' ?>><?= e(t('cx.channel.' . $channel)) ?></option>
        <?php endforeach; ?>
    </select>
    <?= $error('channel') ?>
    <div class="field-hint"><?= e(t('cx.channel_hint')) ?></div>

    <label for="cx-message"><?= e(t('cx.field.message_text')) ?></label>
    <textarea id="cx-message" name="message_text" maxlength="500" rows="3" placeholder="<?= e(t('cx.invitation.default_text')) ?>"><?= e($survey['message_text'] ?? '') ?></textarea>
    <div class="field-hint"><?= e(t('cx.message_hint')) ?></div>

    <label for="cx-status"><?= e(t('cx.field.status')) ?></label>
    <select id="cx-status" name="status">
        <option value="inactive"<?= $survey['status'] !== 'active' ? ' selected' : '' ?>><?= e(t('cx.status.inactive')) ?></option>
        <option value="active"<?= $survey['status'] === 'active' ? ' selected' : '' ?>><?= e(t('cx.status.active')) ?></option>
    </select>

    <div class="actions">
        <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('cx.save')) ?></button>
        <a href="/cx" class="btn btn--ghost"><?= icon('x') ?><?= e(t('common.cancel')) ?></a>
    </div>
</form>
