<?php
/** @var array|null $record */
/** @var array|null $contact */
/** @var array $contactOptions */
/** @var array<int, string> $users */
/** @var bool $canSensitive */
/** @var array $customFieldDefs */
/** @var array $old */
/** @var array $errors */
/** @var \App\Infrastructure\Theme\ThemeLoader $theme */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$isEdit = $record !== null;
$val = fn (string $key): string => e((string) ($old[$key] ?? ''));
$err = function (string $key) use ($errors): string {
    return isset($errors[$key][0]) ? '<div class="field-error">' . e(t($errors[$key][0])) . '</div>' : '';
};
$customValues = (array) ($old['custom'] ?? []);
$back = $isEdit ? '/client-records/' . (int) $record['id'] . '?tab=intake' : '/client-records';
?>
<div class="page-head">
    <div>
        <h1><?= e(t($isEdit ? 'client_records.edit_intake' : 'client_records.new')) ?></h1>
        <p><?= e(t($isEdit ? 'client_records.edit_intake_hint' : 'client_records.new_hint')) ?></p>
    </div>
    <div class="quick-actions">
        <a href="<?= e($back) ?>" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('client_records.back')) ?></a>
    </div>
</div>

<section class="card card--form">
    <form method="post" action="<?= e($isEdit ? '/client-records/' . (int) $record['id'] : '/client-records') ?>">
        <?= Csrf::field() ?>

        <?php if ($isEdit): ?>
            <h2 class="form-section-title"><?= e(t('client_records.section_client')) ?></h2>
            <p>
                <strong><?= e(trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''))) ?></strong>
                <span class="muted mono"><?= e($record['record_number']) ?></span>
            </p>
            <p class="field-hint"><?= e(t('client_records.contact_data_hint')) ?>
                <a href="/contacts/<?= e($contact['id']) ?>/edit"><?= e(t('client_records.edit_contact')) ?></a></p>
        <?php else: ?>
            <label for="contact_id"><?= e(t('client_records.client')) ?></label>
            <select id="contact_id" name="contact_id" required>
                <option value=""><?= e(t('client_records.choose_contact')) ?></option>
                <?php foreach ($contactOptions as $c): ?>
                    <option value="<?= e($c['id']) ?>" <?= (string) ($old['contact_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>>
                        <?= e(trim($c['first_name'] . ' ' . ($c['last_name'] ?? '')) . ($c['phone'] ? ' — ' . $c['phone'] : '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?= $err('contact_id') ?>
            <p class="field-hint"><?= e(t('client_records.contact_missing_hint')) ?>
                <a href="/contacts/create"><?= e(t('contacts.new')) ?></a></p>
        <?php endif; ?>

        <div class="form-grid">
            <div>
                <label for="assigned_user_id"><?= e(t('client_records.assigned_specialist')) ?></label>
                <select id="assigned_user_id" name="assigned_user_id">
                    <option value=""><?= e(t('common.none')) ?></option>
                    <?php foreach ($users as $uid => $name): ?>
                        <option value="<?= e($uid) ?>" <?= (string) ($old['assigned_user_id'] ?? '') === (string) $uid ? 'selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('assigned_user_id') ?>
            </div>
            <?php if ($isEdit): ?>
                <div>
                    <label for="status"><?= e(t('client_records.status')) ?></label>
                    <select id="status" name="status">
                        <?php foreach (['active', 'inactive', 'archived'] as $s): ?>
                            <option value="<?= e($s) ?>" <?= ($old['status'] ?? '') === $s ? 'selected' : '' ?>><?= e(t('client_records.status.' . $s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>

        <h2 class="form-section-title"><?= e(t('client_records.section_important')) ?></h2>
        <label for="important_notes"><?= e(t('client_records.important_notes')) ?></label>
        <textarea id="important_notes" name="important_notes" rows="3" placeholder="<?= e(t('client_records.important_notes_placeholder')) ?>"><?= $val('important_notes') ?></textarea>

        <label for="preferences"><?= e(t('client_records.preferences')) ?></label>
        <textarea id="preferences" name="preferences" rows="3"><?= $val('preferences') ?></textarea>

        <?php if ($canSensitive): ?>
            <h2 class="form-section-title"><?= icon('lock', 'icon--sm') ?> <?= e(t('client_records.section_sensitive')) ?></h2>
            <p class="field-hint"><?= e(t('client_records.sensitive_hint')) ?></p>

            <label for="sensitivities"><?= e(t('client_records.sensitivities')) ?></label>
            <textarea id="sensitivities" name="sensitivities" rows="3"><?= $val('sensitivities') ?></textarea>

            <label for="restrictions"><?= e(t('client_records.restrictions')) ?></label>
            <textarea id="restrictions" name="restrictions" rows="3"><?= $val('restrictions') ?></textarea>

            <label for="observations"><?= e(t('client_records.observations')) ?></label>
            <textarea id="observations" name="observations" rows="3"><?= $val('observations') ?></textarea>
        <?php endif; ?>

        <?php if ($customFieldDefs !== []): ?>
            <h2 class="form-section-title"><?= e(t('client_records.section_custom')) ?></h2>
            <div class="form-grid">
                <?php foreach ($customFieldDefs as $def): ?>
                    <?php
                    $key = (string) $def['field_key'];
                    $id = 'custom_' . $key;
                    $name = 'custom[' . $key . ']';
                    $value = (string) ($customValues[$key] ?? '');
                    $options = json_decode((string) ($def['options'] ?? '[]'), true) ?: [];
                    ?>
                    <div>
                        <?php if ($def['field_type'] === 'multiselect'): ?>
                            <?php $selected = json_decode($value, true) ?: []; ?>
                            <fieldset>
                                <legend><?= e($def['label']) ?></legend>
                                <input type="hidden" name="<?= e($name) ?>[]" value="">
                                <?php foreach ($options as $option): ?>
                                    <label class="check check--spaced"><input type="checkbox" name="<?= e($name) ?>[]" value="<?= e($option) ?>" <?= in_array($option, $selected, true) ? 'checked' : '' ?>> <?= e($option) ?></label>
                                <?php endforeach; ?>
                            </fieldset>
                        <?php else: ?>
                            <label for="<?= e($id) ?>"><?= e($def['label']) ?></label>
                            <?php if ($def['field_type'] === 'select' || $def['field_type'] === 'boolean'): ?>
                                <select id="<?= e($id) ?>" name="<?= e($name) ?>">
                                    <option value=""><?= e(t('common.none')) ?></option>
                                    <?php if ($def['field_type'] === 'boolean'): ?>
                                        <option value="1" <?= $value === '1' ? 'selected' : '' ?>><?= e(t('common.yes')) ?></option>
                                        <option value="0" <?= $value === '0' ? 'selected' : '' ?>><?= e(t('common.no')) ?></option>
                                    <?php else: ?>
                                        <?php foreach ($options as $option): ?>
                                            <option value="<?= e($option) ?>" <?= $value === (string) $option ? 'selected' : '' ?>><?= e($option) ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            <?php elseif ($def['field_type'] === 'date'): ?>
                                <input type="text" class="crm-datepicker" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>">
                            <?php else: ?>
                                <?php $type = match ($def['field_type']) { 'number' => 'number', 'email' => 'email', 'phone' => 'tel', 'url' => 'url', default => 'text' }; ?>
                                <input type="<?= e($type) ?>" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>"<?= $type === 'number' ? ' step="any"' : '' ?>>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="field-hint"><?= e(t('client_records.custom_fields_hint')) ?></p>
        <?php endif; ?>

        <div class="actions">
            <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t($isEdit ? 'common.save' : 'client_records.create_record')) ?></button>
            <a href="<?= e($back) ?>" class="btn btn--ghost"><?= icon('x') ?><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</section>
