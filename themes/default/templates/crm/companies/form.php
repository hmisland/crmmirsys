<?php
/** @var array|null $company */
/** @var array $errors */
/** @var array $old */
/** @var array $categories */
/** @var bool $canManageCategories */
/** @var array $customFieldDefs */
/** @var array $customFieldValues */
/** @var array $contactOptions contacts the user may link (create only) */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$isNew = $company === null;
$fieldError = fn (string $field) => isset($errors[$field]) ? t((string) $errors[$field][0]) : null;
$val = fn (string $field, string $default = '') => e($old[$field] ?? $company[$field] ?? $default);
$action = $isNew ? '/companies' : '/companies/' . $company['id'];
$selectedCategory = (string) ($old['category_id'] ?? $company['category_id'] ?? '');
$oldTags = array_filter(array_map('strval', (array) ($old['tags'] ?? [])));
$oldContacts = implode(',', array_map('intval', (array) ($old['contact_ids'] ?? [])));
?>
<?php if ($isNew): ?>
<script type="application/json" id="company-contacts-data"><?= json_encode($contactOptions, JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>

<section class="card card--form">
    <h1><?= $isNew ? e(t('companies.new')) : e(t('companies.edit')) ?></h1>

    <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data">
        <?= Csrf::field() ?>

        <h2><?= e(t('companies.section_profile')) ?></h2>
        <div class="form-grid">
            <div>
                <label for="name"><?= e(t('common.name')) ?></label>
                <input type="text" id="name" name="name" value="<?= $val('name') ?>" required autofocus>
                <?php if ($fieldError('name')): ?><div class="field-error"><?= e($fieldError('name')) ?></div><?php endif; ?>
            </div>
            <div>
                <label for="brand"><?= e(t('companies.brand')) ?></label>
                <input type="text" id="brand" name="brand" value="<?= $val('brand') ?>" maxlength="150">
                <div class="field-hint"><?= e(t('companies.brand_hint')) ?></div>
            </div>
            <div>
                <label for="category_id"><?= e(t('companies.category')) ?></label>
                <select id="category_id" name="category_id">
                    <option value=""><?= e(t('common.none')) ?></option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat['id']) ?>"<?= $selectedCategory === (string) $cat['id'] ? ' selected' : '' ?>><?= e($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($canManageCategories): ?>
                    <div class="field-hint"><a href="/companies/categories"><?= icon('tag', 'icon--sm') ?><?= e(t('companies.manage_categories')) ?></a></div>
                <?php endif; ?>
            </div>
            <div>
                <label for="ceo_name"><?= e(t('companies.ceo_name')) ?></label>
                <input type="text" id="ceo_name" name="ceo_name" value="<?= $val('ceo_name') ?>" maxlength="150">
            </div>
            <div>
                <label for="industry"><?= e(t('companies.industry')) ?></label>
                <input type="text" id="industry" name="industry" value="<?= $val('industry') ?>">
            </div>
            <div>
                <label for="employees_count"><?= e(t('companies.employees')) ?></label>
                <input type="number" id="employees_count" name="employees_count" value="<?= $val('employees_count') ?>" min="0" data-no-format>
            </div>
        </div>

        <h2><?= e(t('companies.section_registration')) ?></h2>
        <div class="form-grid">
            <div>
                <label for="national_id"><?= e(t('companies.national_id')) ?></label>
                <input type="text" id="national_id" name="national_id" value="<?= $val('national_id') ?>" maxlength="30" dir="ltr" inputmode="numeric">
            </div>
            <div>
                <label for="economic_code"><?= e(t('companies.economic_code')) ?></label>
                <input type="text" id="economic_code" name="economic_code" value="<?= $val('economic_code') ?>" maxlength="30" dir="ltr">
            </div>
            <div>
                <label for="registration_number"><?= e(t('companies.registration_number')) ?></label>
                <input type="text" id="registration_number" name="registration_number" value="<?= $val('registration_number') ?>" maxlength="50" dir="ltr">
            </div>
        </div>

        <h2><?= e(t('companies.section_contact')) ?></h2>
        <div class="form-grid">
            <div>
                <label for="phone"><?= e(t('common.phone')) ?></label>
                <input type="text" id="phone" name="phone" value="<?= $val('phone') ?>" dir="ltr">
            </div>
            <div>
                <label for="email"><?= e(t('common.email')) ?></label>
                <input type="email" id="email" name="email" value="<?= $val('email') ?>" dir="ltr">
            </div>
            <div>
                <label for="website"><?= e(t('companies.website')) ?></label>
                <input type="text" id="website" name="website" value="<?= $val('website') ?>" dir="ltr">
            </div>
            <div>
                <label for="country"><?= e(t('companies.country')) ?></label>
                <input type="text" id="country" name="country" value="<?= $val('country') ?>">
            </div>
            <div>
                <label for="city"><?= e(t('companies.city')) ?></label>
                <input type="text" id="city" name="city" value="<?= $val('city') ?>">
            </div>
            <div>
                <label for="address"><?= e(t('companies.address')) ?></label>
                <input type="text" id="address" name="address" value="<?= $val('address') ?>">
            </div>
        </div>

        <label for="description"><?= e(t('companies.description')) ?></label>
        <textarea id="description" name="description" rows="4"><?= $val('description') ?></textarea>

        <?php if ($customFieldDefs !== []): ?>
            <h2><?= e(t('common.custom_fields')) ?></h2>
            <?php foreach ($customFieldDefs as $def): ?>
                <label for="custom_<?= e($def['field_key']) ?>"><?= e($def['label']) ?></label>
                <input type="text" id="custom_<?= e($def['field_key']) ?>" name="custom[<?= e($def['field_key']) ?>]"
                       value="<?= e($old['custom'][$def['field_key']] ?? $customFieldValues[$def['field_key']] ?? '') ?>">
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($isNew): ?>
            <h2><?= e(t('companies.section_more')) ?></h2>

            <label for="company-tags"><?= e(t('common.tags')) ?></label>
            <input type="text" id="company-tags" class="crm-tag-input" data-name="tags[]" placeholder="<?= e(t('files.add_a_tag_hint')) ?>">
            <?php foreach ($oldTags as $tag): ?>
                <input type="hidden" name="tags[]" value="<?= e($tag) ?>">
            <?php endforeach; ?>

            <label for="company-contacts"><?= e(t('companies.link_contacts')) ?></label>
            <input type="text" id="company-contacts" class="crm-recipient-picker" data-name="contact_ids[]" data-source="company-contacts-data" data-selected="<?= e($oldContacts) ?>" placeholder="<?= e(t('mail.recipient_placeholder')) ?>">
            <div class="field-hint"><?= e(t('companies.link_contacts_hint')) ?></div>

            <label for="company-files"><?= e(t('common.attachments')) ?></label>
            <input type="file" id="company-files" name="attachments[]" multiple>
            <div class="field-hint"><?= e(t('companies.attachments_hint')) ?></div>
        <?php else: ?>
            <p class="field-hint"><?= e(t('companies.edit_more_hint')) ?></p>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button>
            <a href="<?= e($isNew ? '/companies' : '/companies/' . $company['id']) ?>" class="btn"><?= icon('x') ?><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</section>
