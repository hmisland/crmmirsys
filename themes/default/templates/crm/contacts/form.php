<?php
/** @var array|null $contact */
/** @var array $companies */
/** @var array $related array{phones: array, emails: array, social: array} */
/** @var array $errors */
/** @var array $old */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
use App\Infrastructure\Geography\IranProvinces;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
$val = fn (string $field, string $default = '') => e($old[$field] ?? $contact[$field] ?? $default);
$action = $contact !== null ? '/contacts/' . $contact['id'] : '/contacts';

// Pick out which stored phone is "the" quick mobile / quick landline
// so the Details tab can show them directly, and the "additional"
// list on the other tab doesn't repeat them.
$quickMobile = '';
$quickLandline = '';
$quickExtension = '';
$additionalPhones = [];
$usedMobile = false;
$usedLandline = false;
foreach ($related['phones'] as $p) {
    if (!$usedMobile && $p['type'] === 'mobile') {
        $quickMobile = $p['number'];
        $usedMobile = true;
    } elseif (!$usedLandline && $p['type'] === 'landline') {
        $quickLandline = $p['number'];
        $quickExtension = $p['extension'] ?? '';
        $usedLandline = true;
    } else {
        $additionalPhones[] = $p;
    }
}

// Contacts written only to the legacy contacts.phone/email columns
// (e.g. by an older CSV import) have no child rows yet — show those.
if ($contact !== null && $related['phones'] === [] && !empty($contact['phone'])) {
    $quickMobile = (string) $contact['phone'];
}

$quickEmail = '';
$additionalEmails = [];
$usedEmail = false;
foreach ($related['emails'] as $em) {
    if (!$usedEmail) {
        $quickEmail = $em['email'];
        $usedEmail = true;
    } else {
        $additionalEmails[] = $em;
    }
}
if ($contact !== null && $related['emails'] === [] && !empty($contact['email'])) {
    $quickEmail = (string) $contact['email'];
}

// After a failed validation the raw submitted values are already in
// $old (Request::all() carries them) — prefer those so in-progress
// edits aren't lost.
$mobileVal = $old['mobile'] ?? $quickMobile;
$landlineVal = $old['landline'] ?? $quickLandline;
$extensionVal = $old['landline_extension'] ?? $quickExtension;
$emailVal = $old['email'] ?? $quickEmail;
$additionalPhoneRows = $old['phones'] ?? $additionalPhones;
$additionalEmailRows = $old['emails'] ?? $additionalEmails;
$socialRows = $old['social'] ?? $related['social'];

// Brand/platform names are proper nouns — deliberately not translated
// per-locale (Facebook is "Facebook" in every language).
$socialPlatforms = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'youtube' => 'YouTube',
    'twitter' => 'Twitter/X', 'linkedin' => 'LinkedIn', 'telegram' => 'Telegram', 'whatsapp' => 'WhatsApp', 'custom' => t('contacts.social_other')];
?>
<section class="card card--form" style="max-width:680px" data-tabs>
    <h1><?= $contact !== null ? e(t('contacts.edit')) : e(t('contacts.new')) ?></h1>

    <div class="tabs" role="tablist">
        <button type="button" class="tab-btn active" data-tab="details" aria-selected="true"><?= icon('user') ?><?= e(t('contacts.tab_details')) ?></button>
        <button type="button" class="tab-btn" data-tab="contactinfo" aria-selected="false"><?= icon('phone') ?><?= e(t('contacts.tab_contact_channels')) ?></button>
        <button type="button" class="tab-btn" data-tab="notes" aria-selected="false"><?= icon('message') ?><?= e(t('contacts.tab_notes_interests')) ?></button>
    </div>

    <form method="post" action="<?= e($action) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="full_contact_form" value="1">

        <div class="tab-panel" data-panel="details">
            <label for="first_name"><?= e(t('auth.first_name')) ?></label>
            <input type="text" id="first_name" name="first_name" value="<?= $val('first_name') ?>" required autofocus>
            <?php if ($fieldError('first_name')): ?><div class="field-error"><?= e($fieldError('first_name')) ?></div><?php endif; ?>

            <label for="last_name"><?= e(t('auth.last_name')) ?></label>
            <input type="text" id="last_name" name="last_name" value="<?= $val('last_name') ?>">

            <label><?= e(t('contacts.field_title')) ?></label>
            <label class="check check--spaced">
                <input type="radio" name="gender" value="male" <?= ($old['gender'] ?? $contact['gender'] ?? '') === 'male' ? 'checked' : '' ?>> <?= e(t('contacts.title_mr')) ?>
            </label>
            <label class="check">
                <input type="radio" name="gender" value="female" <?= ($old['gender'] ?? $contact['gender'] ?? '') === 'female' ? 'checked' : '' ?>> <?= e(t('contacts.title_ms')) ?>
            </label>

            <label for="date_of_birth"><?= e(t('contacts.date_of_birth')) ?></label>
            <input type="text" class="crm-datepicker" id="date_of_birth" name="date_of_birth" value="<?= $val('date_of_birth') ?>">

            <label for="mobile"><?= e(t('contacts.mobile')) ?></label>
            <input type="text" id="mobile" name="mobile" value="<?= e($mobileVal) ?>" placeholder="09xxxxxxxxx" dir="ltr">

            <label for="email"><?= e(t('common.email')) ?></label>
            <input type="email" id="email" name="email" value="<?= e($emailVal) ?>">

            <label for="landline"><?= e(t('contacts.landline')) ?></label>
            <input type="text" id="landline" name="landline" value="<?= e($landlineVal) ?>" placeholder="021xxxxxxxx" dir="ltr">

            <label for="landline_extension"><?= e(t('contacts.extension')) ?></label>
            <input type="text" id="landline_extension" name="landline_extension" value="<?= e($extensionVal) ?>" class="field-sm">

            <label for="company_id"><?= e(t('common.company')) ?></label>
            <select id="company_id" name="company_id">
                <option value=""><?= e(t('common.none')) ?></option>
                <?php foreach ($companies as $c): ?>
                    <option value="<?= e($c['id']) ?>" <?= (string) ($old['company_id'] ?? $contact['company_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>>
                        <?= e($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="category_id"><?= e(t('contacts.category')) ?></label>
            <select id="category_id" name="category_id">
                <option value=""><?= e(t('common.none')) ?></option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= e($cat['id']) ?>" <?= (string) ($old['category_id'] ?? $contact['category_id'] ?? '') === (string) $cat['id'] ? 'selected' : '' ?>>
                        <?= e($cat['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="position"><?= e(t('contacts.position')) ?></label>
            <input type="text" id="position" name="position" value="<?= $val('position') ?>">

            <label for="source"><?= e(t('leads.source')) ?></label>
            <input type="text" id="source" name="source" value="<?= $val('source') ?>">

            <label for="national_id"><?= e(t('contacts.national_id')) ?></label>
            <input type="text" id="national_id" name="national_id" value="<?= $val('national_id') ?>" maxlength="30" dir="ltr">

            <label for="economic_code"><?= e(t('contacts.economic_code')) ?></label>
            <input type="text" id="economic_code" name="economic_code" value="<?= $val('economic_code') ?>" maxlength="30" dir="ltr">

            <label for="address"><?= e(t('companies.address')) ?></label>
            <input type="text" id="address" name="address" value="<?= $val('address') ?>">

            <label for="province"><?= e(t('contacts.province')) ?></label>
            <select id="province" name="province">
                <option value="">—</option>
                <?php foreach (IranProvinces::provinceNames() as $province): ?>
                    <option value="<?= e($province) ?>" <?= ($old['province'] ?? $contact['province'] ?? '') === $province ? 'selected' : '' ?>><?= e($province) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="city"><?= e(t('companies.city')) ?></label>
            <input type="text" id="city" name="city" list="city-suggestions" value="<?= $val('city') ?>">
            <datalist id="city-suggestions">
                <?php foreach (IranProvinces::allSuggestedCities() as $city): ?><option value="<?= e($city) ?>"><?php endforeach; ?>
            </datalist>
            <div class="field-hint"><?= e(t('contacts.city_hint')) ?></div>

            <label for="postal_code"><?= e(t('contacts.postal_code')) ?></label>
            <input type="text" id="postal_code" name="postal_code" value="<?= $val('postal_code') ?>" maxlength="20" dir="ltr">

            <label for="country"><?= e(t('companies.country')) ?></label>
            <input type="text" id="country" name="country" value="<?= $val('country') ?>">

        </div>

        <div class="tab-panel" data-panel="contactinfo" hidden>
            <p class="muted"><?= e(t('contacts.contactinfo_hint')) ?></p>

            <h3><?= e(t('contacts.additional_phones')) ?></h3>
            <div id="phone-rows">
                <?php foreach ($additionalPhoneRows as $key => $row): ?>
                    <div class="repeat-row">
                        <select name="phones[<?= e($key) ?>][type]">
                            <option value="mobile" <?= ($row['type'] ?? 'mobile') === 'mobile' ? 'selected' : '' ?>><?= e(t('contacts.phone_type_mobile')) ?></option>
                            <option value="landline" <?= ($row['type'] ?? '') === 'landline' ? 'selected' : '' ?>><?= e(t('contacts.phone_type_landline')) ?></option>
                        </select>
                        <input type="text" name="phones[<?= e($key) ?>][number]" value="<?= e($row['number'] ?? '') ?>" placeholder="<?= e(t('contacts.phone_number_placeholder')) ?>">
                        <button type="button" class="link-button remove-row"><?= icon('x') ?><?= e(t('common.remove')) ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn" id="add-phone"><?= icon('plus') ?><?= e(t('contacts.add_another_phone')) ?></button>

            <h3><?= e(t('contacts.additional_emails')) ?></h3>
            <div id="email-rows">
                <?php foreach ($additionalEmailRows as $key => $row): ?>
                    <div class="repeat-row">
                        <input type="email" name="emails[<?= e($key) ?>][email]" value="<?= e($row['email'] ?? '') ?>" placeholder="<?= e(t('contacts.email_address_placeholder')) ?>">
                        <button type="button" class="link-button remove-row"><?= icon('x') ?><?= e(t('common.remove')) ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn" id="add-email"><?= icon('plus') ?><?= e(t('contacts.add_another_email')) ?></button>

            <h3><?= e(t('contacts.social_networks')) ?></h3>
            <div id="social-rows">
                <?php foreach ($socialRows as $key => $row): ?>
                    <div class="repeat-row repeat-row--social">
                        <select name="social[<?= e($key) ?>][platform]" class="social-platform-select">
                            <?php foreach ($socialPlatforms as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= ($row['platform'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="social[<?= e($key) ?>][custom_label]" value="<?= e($row['custom_label'] ?? '') ?>"
                               placeholder="<?= e(t('contacts.network_name_placeholder')) ?>" class="social-custom-label" <?= ($row['platform'] ?? '') !== 'custom' ? 'hidden' : '' ?>>
                        <input type="text" name="social[<?= e($key) ?>][value]" value="<?= e($row['value'] ?? '') ?>" placeholder="<?= e(t('contacts.profile_url_placeholder')) ?>">
                        <button type="button" class="link-button remove-row"><?= icon('x') ?><?= e(t('common.remove')) ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn" id="add-social"><?= icon('plus') ?><?= e(t('contacts.add_social_network')) ?></button>
        </div>

        <div class="tab-panel" data-panel="notes" hidden>
            <label for="description"><?= e(t('contacts.description')) ?></label>
            <textarea id="description" name="description" rows="4"><?= $val('description') ?></textarea>

            <label for="interests"><?= e(t('contacts.interests')) ?></label>
            <input type="text" id="interests" name="interests" value="<?= e($old['interests'] ?? implode(', ', array_column($related['interests'], 'interest'))) ?>" placeholder="<?= e(t('contacts.interests_placeholder')) ?>">
            <div class="field-hint"><?= e(t('contacts.interests_hint')) ?></div>
        </div>

        <p class="actions"><button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button></p>
    </form>
</section>

<template id="phone-row-template">
    <div class="repeat-row">
        <select name="phones[__INDEX__][type]">
            <option value="mobile" selected><?= e(t('contacts.phone_type_mobile')) ?></option>
            <option value="landline"><?= e(t('contacts.phone_type_landline')) ?></option>
        </select>
        <input type="text" name="phones[__INDEX__][number]" placeholder="<?= e(t('contacts.phone_number_placeholder')) ?>">
        <button type="button" class="link-button remove-row"><?= icon('x') ?><?= e(t('common.remove')) ?></button>
    </div>
</template>

<template id="email-row-template">
    <div class="repeat-row">
        <input type="email" name="emails[__INDEX__][email]" placeholder="<?= e(t('contacts.email_address_placeholder')) ?>">
        <button type="button" class="link-button remove-row"><?= icon('x') ?><?= e(t('common.remove')) ?></button>
    </div>
</template>

<template id="social-row-template">
    <div class="repeat-row repeat-row--social">
        <select name="social[__INDEX__][platform]" class="social-platform-select">
            <?php foreach ($socialPlatforms as $value => $label): ?>
                <option value="<?= e($value) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="social[__INDEX__][custom_label]" placeholder="<?= e(t('contacts.network_name_placeholder')) ?>" class="social-custom-label" hidden>
        <input type="text" name="social[__INDEX__][value]" placeholder="<?= e(t('contacts.profile_url_placeholder')) ?>">
        <button type="button" class="link-button remove-row"><?= icon('x') ?><?= e(t('common.remove')) ?></button>
    </div>
</template>

<script>
(function () {
    var counter = <?= (int) max(count($additionalPhoneRows), count($additionalEmailRows), count($socialRows)) + 1000 ?>;

    function addRow(containerId, templateId) {
        var container = document.getElementById(containerId);
        var template = document.getElementById(templateId);
        var html = template.innerHTML.split('__INDEX__').join(String(counter++));
        var wrapper = document.createElement('div');
        wrapper.innerHTML = html.trim();
        container.appendChild(wrapper.firstElementChild);
    }

    document.getElementById('add-phone').addEventListener('click', function () {
        addRow('phone-rows', 'phone-row-template');
    });
    document.getElementById('add-email').addEventListener('click', function () {
        addRow('email-rows', 'email-row-template');
    });
    document.getElementById('add-social').addEventListener('click', function () {
        addRow('social-rows', 'social-row-template');
    });

    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-row')) {
            e.target.closest('.repeat-row').remove();
        }
    });

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('social-platform-select')) {
            var labelInput = e.target.closest('.repeat-row--social').querySelector('.social-custom-label');
            labelInput.hidden = e.target.value !== 'custom';
        }
    });
})();
</script>
