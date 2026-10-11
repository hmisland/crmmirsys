<?php
/**
 * Intake tab: contact data (read from the contact, never copied), the
 * record's own intake text, sensitive section, and organization-defined
 * custom fields. Variables come from show.php's scope.
 *
 * @var array $customFieldDefs
 * @var array $customFieldValues
 */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;

$text = function (?string $value): string {
    return $value !== null && $value !== '' ? '<p class="prose-text">' . e($value) . '</p>' : '<span class="muted">—</span>';
};
$gender = match ($contact['gender'] ?? null) {
    'male' => t('client_records.gender_male'),
    'female' => t('client_records.gender_female'),
    default => '—',
};
$customDisplay = function (array $def, ?string $value): string {
    if ($value === null || $value === '' || $value === '[]') {
        return '—';
    }
    return match ($def['field_type']) {
        'boolean' => $value === '1' ? t('common.yes') : t('common.no'),
        'multiselect' => implode(', ', (array) (json_decode($value, true) ?: [])),
        default => $value,
    };
};
?>
<div class="record-layout">
    <div>
        <section class="card">
            <div class="card__header">
                <h2><?= icon('clipboard') ?><?= e(t('client_records.section_important')) ?></h2>
                <?php if ($can['edit']): ?>
                    <a href="/client-records/<?= e($record['id']) ?>/edit" class="btn btn--sm"><?= icon('pencil') ?><?= e(t('client_records.edit_intake')) ?></a>
                <?php endif; ?>
            </div>
            <dl class="detail-list">
                <dt><?= e(t('client_records.important_notes')) ?></dt>
                <dd><?= $text($record['important_notes']) ?></dd>
                <dt><?= e(t('client_records.preferences')) ?></dt>
                <dd><?= $text($record['preferences']) ?></dd>
            </dl>
        </section>

        <section class="card">
            <h2><?= icon('lock', 'icon--sm') ?> <?= e(t('client_records.section_sensitive')) ?></h2>
            <?php if ($can['sensitive']): ?>
                <dl class="detail-list">
                    <dt><?= e(t('client_records.sensitivities')) ?></dt>
                    <dd><?= $text($record['sensitivities']) ?></dd>
                    <dt><?= e(t('client_records.restrictions')) ?></dt>
                    <dd><?= $text($record['restrictions']) ?></dd>
                    <dt><?= e(t('client_records.observations')) ?></dt>
                    <dd><?= $text($record['observations']) ?></dd>
                </dl>
            <?php else: ?>
                <p class="locked"><?= icon('lock') ?><?= e(t('client_records.sensitive_locked')) ?></p>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2><?= e(t('client_records.section_custom')) ?></h2>
            <?php if ($customFieldDefs === []): ?>
                <p class="muted"><?= e(t('client_records.custom_fields_hint')) ?></p>
            <?php else: ?>
                <dl class="detail-list">
                    <?php foreach ($customFieldDefs as $def): ?>
                        <dt><?= e($def['label']) ?></dt>
                        <dd><?= e($customDisplay($def, $customFieldValues[$def['field_key']] ?? null)) ?></dd>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
        </section>
    </div>

    <div>
        <section class="card">
            <div class="card__header">
                <h2><?= icon('user') ?><?= e(t('client_records.section_client')) ?></h2>
                <?php if ($can['contact']): ?>
                    <a href="/contacts/<?= e($contact['id']) ?>" class="btn btn--ghost btn--sm"><?= e(t('client_records.view_contact')) ?><?= icon('arrow-right', 'icon--sm') ?></a>
                <?php endif; ?>
            </div>
            <dl class="detail-list">
                <dt><?= e(t('common.name')) ?></dt>
                <dd><?= e(trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''))) ?></dd>
                <dt><?= e(t('common.phone')) ?></dt>
                <dd><span class="ltr"><?= e($contact['phone'] ?: '—') ?></span></dd>
                <dt><?= e(t('common.email')) ?></dt>
                <dd><span class="ltr"><?= e($contact['email'] ?: '—') ?></span></dd>
                <dt><?= e(t('client_records.date_of_birth')) ?></dt>
                <dd><?= e($contact['date_of_birth'] ?? '—') ?></dd>
                <dt><?= e(t('client_records.gender')) ?></dt>
                <dd><?= e($gender) ?></dd>
                <dt><?= e(t('client_records.source')) ?></dt>
                <dd><?= e($contact['source'] ?: '—') ?></dd>
            </dl>
            <p class="field-hint"><?= e(t('client_records.contact_data_hint')) ?></p>
        </section>

        <section class="card">
            <h2><?= icon('clipboard') ?><?= e(t('client_records.section_record')) ?></h2>
            <dl class="detail-list">
                <dt><?= e(t('client_records.record_number')) ?></dt>
                <dd class="mono"><?= e($record['record_number']) ?></dd>
                <dt><?= e(t('client_records.status')) ?></dt>
                <dd><?= badge(t('client_records.status.' . $record['status']), $record['status']) ?></dd>
                <dt><?= e(t('client_records.assigned_specialist')) ?></dt>
                <dd><?= e($users[$record['assigned_user_id']] ?? '—') ?></dd>
                <dt><?= e(t('client_records.opened_by')) ?></dt>
                <dd><?= e($users[$record['owner_id']] ?? '—') ?></dd>
                <dt><?= e(t('client_records.opened_on')) ?></dt>
                <dd><?= e(substr((string) $record['created_at'], 0, 10)) ?></dd>
            </dl>
        </section>
    </div>
</div>
