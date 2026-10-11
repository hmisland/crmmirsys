<?php
/** @var array|null $lead */
/** @var array $errors */
/** @var array $old */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
$val = fn (string $field, string $default = '') => e($old[$field] ?? $lead[$field] ?? $default);
$action = $lead !== null ? '/leads/' . $lead['id'] : '/leads';
$statuses = ['new', 'contacted', 'qualified', 'unqualified'];
?>
<section class="card card--form" style="max-width:600px">
    <h1><?= $lead !== null ? e(t('leads.edit')) : e(t('leads.new')) ?></h1>

    <form method="post" action="<?= e($action) ?>">
        <?= Csrf::field() ?>

        <label for="first_name"><?= e(t('auth.first_name')) ?></label>
        <input type="text" id="first_name" name="first_name" value="<?= $val('first_name') ?>" required autofocus>
        <?php if ($fieldError('first_name')): ?><div class="field-error"><?= e($fieldError('first_name')) ?></div><?php endif; ?>

        <label for="last_name"><?= e(t('auth.last_name')) ?></label>
        <input type="text" id="last_name" name="last_name" value="<?= $val('last_name') ?>">

        <label for="email"><?= e(t('common.email')) ?></label>
        <input type="email" id="email" name="email" value="<?= $val('email') ?>">

        <label for="phone"><?= e(t('common.phone')) ?></label>
        <input type="text" id="phone" name="phone" value="<?= $val('phone') ?>">

        <label for="company_name"><?= e(t('leads.company_name')) ?></label>
        <input type="text" id="company_name" name="company_name" value="<?= $val('company_name') ?>">

        <label for="source"><?= e(t('leads.source')) ?></label>
        <input type="text" id="source" name="source" value="<?= $val('source') ?>">

        <?php if ($lead !== null): ?>
        <label for="status"><?= e(t('leads.status')) ?></label>
        <select id="status" name="status">
            <?php foreach ($statuses as $s): ?>
                <option value="<?= e($s) ?>" <?= ($old['status'] ?? $lead['status']) === $s ? 'selected' : '' ?>><?= e(t('leads.status.' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <label for="description"><?= e(t('leads.description')) ?></label>
        <textarea id="description" name="description" rows="4" placeholder="<?= e(t('leads.description_hint')) ?>"><?= $val('description') ?></textarea>
        <?php if ($fieldError('description')): ?><div class="field-error"><?= e($fieldError('description')) ?></div><?php endif; ?>

        <?php if ($lead === null): ?>
            <label for="lead-tags"><?= e(t('common.tags')) ?></label>
            <input type="text" id="lead-tags" class="crm-tag-input" data-name="tags[]" placeholder="<?= e(t('files.add_a_tag_hint')) ?>">
            <?php foreach ((array) ($old['tags'] ?? []) as $tag): ?>
                <?php if (trim((string) $tag) !== ''): ?><input type="hidden" name="tags[]" value="<?= e($tag) ?>"><?php endif; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="field-hint"><?= e(t('leads.edit_tags_hint')) ?></p>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button>
            <a href="<?= e($lead === null ? '/leads' : '/leads/' . $lead['id']) ?>" class="btn"><?= icon('x') ?><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</section>
