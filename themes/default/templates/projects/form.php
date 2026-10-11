<?php
/** @var array|null $project */
/** @var array $errors field => translated messages */
/** @var array $old */
/** @var array $contacts */
/** @var array $companies */
/** @var array $users active users of the organization (manager picker) */
/** @var int $defaultOwnerId */
/** @var array{type: string, id: int, label: string, url: string}|null $source "created from" record, re-derived server-side */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$isEdit = $project !== null;
$source = $source ?? null;
$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
$value = fn (string $field) => (string) ($old[$field] ?? $project[$field] ?? '');
$selected = fn (string $field, $id) => $value($field) === (string) $id ? ' selected' : '';
$err = fn (string $field) => $fieldError($field) ? '<div class="field-error">' . e($fieldError($field)) . '</div>' : '';
$ownerValue = $value('owner_id') !== '' ? $value('owner_id') : (string) $defaultOwnerId;
$action = $isEdit ? '/projects/' . (int) $project['id'] : '/projects';
$back = $isEdit ? '/projects/' . (int) $project['id'] : '/projects';
?>
<div class="page-head">
    <div>
        <h1><?= e(t($isEdit ? 'projects.edit' : 'projects.new')) ?></h1>
        <p><?= e(t('projects.form_subtitle')) ?></p>
    </div>
    <div class="quick-actions">
        <a href="<?= e($back) ?>" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('projects.back')) ?></a>
    </div>
</div>

<?php if ($fieldError('source')): ?>
    <div class="alert alert--error"><div class="alert__body"><?= e($fieldError('source')) ?></div></div>
<?php endif; ?>
<?php if ($source !== null): ?>
    <div class="alert alert--info"><div class="alert__body">
        <?= icon($source['type'] === 'deal' ? 'briefcase' : 'file-text') ?> <?= e(t('projects.created_from_' . $source['type'])) ?>
        <a href="<?= e($source['url']) ?>"><?= e($source['label']) ?></a>
    </div></div>
<?php endif; ?>

<section class="card card--form">
    <form method="post" action="<?= e($action) ?>">
        <?= Csrf::field() ?>
        <?php if ($source !== null): ?>
            <input type="hidden" name="source_type" value="<?= e($source['type']) ?>">
            <input type="hidden" name="source_id" value="<?= e($source['id']) ?>">
        <?php endif; ?>

        <label for="name"><?= e(t('projects.name')) ?></label>
        <input type="text" id="name" name="name" value="<?= e($value('name')) ?>" maxlength="255" required autofocus>
        <?= $err('name') ?>

        <div class="form-grid">
            <div>
                <label for="owner_id"><?= e(t('projects.manager')) ?></label>
                <select id="owner_id" name="owner_id">
                    <?php foreach ($users as $u): ?>
                        <option value="<?= e($u['id']) ?>"<?= $ownerValue === (string) $u['id'] ? ' selected' : '' ?>><?= e(trim($u['first_name'] . ' ' . ($u['last_name'] ?? ''))) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('owner_id') ?>
            </div>
            <div>
                <label for="contact_id"><?= e(t('projects.contact')) ?></label>
                <select id="contact_id" name="contact_id">
                    <option value=""><?= e(t('common.none')) ?></option>
                    <?php foreach ($contacts as $c): ?>
                        <option value="<?= e($c['id']) ?>"<?= $selected('contact_id', $c['id']) ?>><?= e(trim($c['first_name'] . ' ' . ($c['last_name'] ?? '')) . (!empty($c['phone']) ? ' — ' . $c['phone'] : '')) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('contact_id') ?>
            </div>
            <div>
                <label for="company_id"><?= e(t('projects.company')) ?></label>
                <select id="company_id" name="company_id">
                    <option value=""><?= e(t('common.none')) ?></option>
                    <?php foreach ($companies as $co): ?>
                        <option value="<?= e($co['id']) ?>"<?= $selected('company_id', $co['id']) ?>><?= e($co['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('company_id') ?>
            </div>
        </div>

        <div class="form-grid">
            <div>
                <label for="start_date"><?= e(t('projects.start_date')) ?></label>
                <input type="text" class="crm-datepicker" id="start_date" name="start_date" value="<?= e($value('start_date')) ?>">
                <?= $err('start_date') ?>
            </div>
            <div>
                <label for="expected_end_date"><?= e(t('projects.expected_end_date')) ?></label>
                <input type="text" class="crm-datepicker" id="expected_end_date" name="expected_end_date" value="<?= e($value('expected_end_date')) ?>">
                <?= $err('expected_end_date') ?>
            </div>
        </div>

        <label for="description"><?= e(t('projects.description')) ?></label>
        <textarea id="description" name="description" rows="4"><?= e($value('description')) ?></textarea>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button>
            <a href="<?= e($back) ?>" class="btn"><?= icon('x') ?><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</section>
