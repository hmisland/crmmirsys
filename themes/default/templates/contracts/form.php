<?php
/** @var array|null $contract */
/** @var array $errors field => translated messages */
/** @var array $old */
/** @var array $categories */
/** @var array $contacts */
/** @var array $companies */
/** @var array $currencies */
/** @var array{type: string, id: int, label: string, url: string}|null $source "created from" record (step 2.8), re-derived server-side */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$isEdit = $contract !== null;
$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
$value = fn (string $field) => (string) ($old[$field] ?? $contract[$field] ?? '');
$selected = fn (string $field, $id) => $value($field) === (string) $id ? ' selected' : '';
$err = fn (string $field) => $fieldError($field) ? '<div class="field-error">' . e($fieldError($field)) . '</div>' : '';
// A decimal like "1250000.500000000000000000" is shown without trailing zeros.
$amount = $value('value');
if ($amount !== '' && str_contains($amount, '.')) {
    $amount = rtrim(rtrim($amount, '0'), '.');
}
$action = $isEdit ? '/contracts/' . (int) $contract['id'] : '/contracts';
$back = $isEdit ? '/contracts/' . (int) $contract['id'] : '/contracts';
?>
<div class="page-head">
    <div>
        <h1><?= e(t($isEdit ? 'contracts.edit' : 'contracts.new')) ?></h1>
        <p><?= e(t('contracts.form_subtitle')) ?></p>
    </div>
    <div class="quick-actions">
        <a href="<?= e($back) ?>" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('contracts.back')) ?></a>
    </div>
</div>

<?php if ($fieldError('source')): ?>
    <div class="alert alert--error"><div class="alert__body"><?= e($fieldError('source')) ?></div></div>
<?php endif; ?>
<?php if (($source ?? null) !== null): ?>
    <div class="alert alert--info"><div class="alert__body">
        <?= icon('briefcase') ?> <?= e(t('contracts.created_from_deal')) ?>
        <a href="<?= e($source['url']) ?>"><?= e($source['label']) ?></a>
    </div></div>
<?php endif; ?>

<section class="card card--form">
    <form method="post" action="<?= e($action) ?>">
        <?= Csrf::field() ?>
        <?php if (($source ?? null) !== null): ?>
            <input type="hidden" name="source_type" value="<?= e($source['type']) ?>">
            <input type="hidden" name="source_id" value="<?= e($source['id']) ?>">
        <?php endif; ?>

        <label for="title"><?= e(t('contracts.title_field')) ?></label>
        <input type="text" id="title" name="title" value="<?= e($value('title')) ?>" maxlength="255" required autofocus>
        <?= $err('title') ?>

        <div class="form-grid">
            <div>
                <label for="contact_id"><?= e(t('contracts.contact')) ?></label>
                <select id="contact_id" name="contact_id">
                    <option value=""><?= e(t('common.none')) ?></option>
                    <?php foreach ($contacts as $c): ?>
                        <option value="<?= e($c['id']) ?>"<?= $selected('contact_id', $c['id']) ?>><?= e(trim($c['first_name'] . ' ' . ($c['last_name'] ?? '')) . (!empty($c['phone']) ? ' — ' . $c['phone'] : '')) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('contact_id') ?>
            </div>
            <div>
                <label for="company_id"><?= e(t('contracts.company')) ?></label>
                <select id="company_id" name="company_id">
                    <option value=""><?= e(t('common.none')) ?></option>
                    <?php foreach ($companies as $co): ?>
                        <option value="<?= e($co['id']) ?>"<?= $selected('company_id', $co['id']) ?>><?= e($co['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('company_id') ?>
            </div>
            <div>
                <label for="category_id"><?= e(t('contracts.category')) ?></label>
                <select id="category_id" name="category_id">
                    <option value=""><?= e(t('common.none')) ?></option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat['id']) ?>"<?= $selected('category_id', $cat['id']) ?>><?= e($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('category_id') ?>
            </div>
        </div>

        <div class="form-grid">
            <div>
                <label for="start_date"><?= e(t('contracts.start_date')) ?></label>
                <input type="text" class="crm-datepicker" id="start_date" name="start_date" value="<?= e($value('start_date')) ?>">
                <?= $err('start_date') ?>
            </div>
            <div>
                <label for="end_date"><?= e(t('contracts.end_date')) ?></label>
                <input type="text" class="crm-datepicker" id="end_date" name="end_date" value="<?= e($value('end_date')) ?>">
                <?= $err('end_date') ?>
            </div>
            <div>
                <label for="renewal_notice_days"><?= e(t('contracts.renewal_notice_days')) ?></label>
                <input type="number" id="renewal_notice_days" name="renewal_notice_days" value="<?= e($value('renewal_notice_days')) ?>" min="0" max="3650" dir="ltr">
                <?= $err('renewal_notice_days') ?>
            </div>
        </div>

        <div class="form-grid">
            <div>
                <label for="value"><?= e(t('contracts.value')) ?></label>
                <input type="text" id="value" name="value" value="<?= e($amount) ?>" inputmode="decimal" dir="ltr">
                <?= $err('value') ?>
            </div>
            <div>
                <label for="currency_id"><?= e(t('contracts.currency')) ?></label>
                <select id="currency_id" name="currency_id">
                    <option value=""><?= e(t('common.none')) ?></option>
                    <?php foreach ($currencies as $cur): ?>
                        <option value="<?= e($cur['id']) ?>"<?= $selected('currency_id', $cur['id']) ?>><?= e($cur['code']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('currency_id') ?>
            </div>
        </div>

        <?php $autoRenew = isset($old['title']) ? !empty($old['auto_renew']) : !empty($contract['auto_renew']); ?>
        <label class="check"><input type="checkbox" name="auto_renew" value="1" <?= $autoRenew ? 'checked' : '' ?>> <?= e(t('contracts.auto_renew')) ?></label>

        <label for="description"><?= e(t('contracts.description')) ?></label>
        <textarea id="description" name="description" rows="3"><?= e($value('description')) ?></textarea>

        <label for="terms"><?= e(t('contracts.terms')) ?></label>
        <textarea id="terms" name="terms" rows="6"><?= e($value('terms')) ?></textarea>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button>
            <a href="<?= e($back) ?>" class="btn"><?= icon('x') ?><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</section>
