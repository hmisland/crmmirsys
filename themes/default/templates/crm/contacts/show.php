<?php
/** @var array $contact */
/** @var array|null $company */
/** @var array|null $category */
/** @var array $related array{phones: array, emails: array, social: array, interests: array} */
/** @var array $notes */
/** @var array $tags */
/** @var array $allTags */
/** @var array $timeline */
/** @var array $customFieldDefs */
/** @var array $customFieldValues */
/** @var array $relatedSummary tiles: type, icon, label_key, tone, count */
/** @var \App\Infrastructure\Theme\ThemeLoader $theme */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$genderLabel = match ($contact['gender'] ?? null) {
    'male' => t('contacts.title_mr'),
    'female' => t('contacts.title_ms'),
    default => null,
};

$socialLabels = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'youtube' => 'YouTube',
    'twitter' => 'Twitter/X', 'linkedin' => 'LinkedIn', 'telegram' => 'Telegram', 'whatsapp' => 'WhatsApp'];

// Contacts stored only in the legacy contacts.phone/email columns
// (older CSV imports) have no child rows — show those values instead.
$phones = $related['phones'];
if ($phones === [] && !empty($contact['phone'])) {
    $phones = [['type' => 'mobile', 'number' => $contact['phone'], 'extension' => null, 'is_primary' => 1]];
}
$emails = $related['emails'];
if ($emails === [] && !empty($contact['email'])) {
    $emails = [['email' => $contact['email'], 'is_primary' => 1]];
}

$location = implode('، ', array_filter([$contact['address'] ?? null, $contact['city'] ?? null, $contact['province'] ?? null, $contact['country'] ?? null]));
// label key => [value, is LTR]; only the ones actually filled in.
$facts = array_filter([
    'contacts.postal_code' => [$contact['postal_code'] ?? '', true],
    'contacts.national_id' => [$contact['national_id'] ?? '', true],
    'contacts.economic_code' => [$contact['economic_code'] ?? '', true],
    'leads.source' => [$contact['source'] ?? '', false],
], fn ($f) => (string) $f[0] !== '');
$relatedSummary = $relatedSummary ?? [];
$hasCustomFields = $customFieldDefs !== [];
?>
<section class="card">
    <h1><?= $genderLabel !== null ? e($genderLabel) . ' ' : '' ?><?= e(trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''))) ?></h1>
    <p class="muted">
        <?= e($contact['email'] ?? '') ?>
        <?= $contact['phone'] ? ' · ' . e($contact['phone']) : '' ?>
        <?= $contact['position'] ? ' · ' . e($contact['position']) : '' ?>
        <?= $contact['province'] || $contact['city'] ? ' · ' . e(trim(($contact['city'] ?? '') . ' ' . ($contact['province'] ?? ''))) : '' ?>
        <?= !empty($contact['date_of_birth']) ? ' · ' . e(t('contacts.born')) . ' ' . e($contact['date_of_birth']) : '' ?>
    </p>
    <?php if ($company !== null): ?>
        <p><a href="/companies/<?= e($company['id']) ?>"><?= icon('building') ?> <?= e($company['name']) ?></a></p>
    <?php endif; ?>
    <?php if ($category !== null): ?>
        <p class="muted"><?= e(t('contacts.category')) ?>: <?= e($category['name']) ?></p>
    <?php endif; ?>
    <div class="actions">
        <?php if (($clientRecord ?? null) !== null): ?>
            <a href="/client-records/for-contact/<?= e($contact['id']) ?>" class="btn btn--primary"><?= icon('clipboard') ?><?= e(t($clientRecord['exists'] ? 'client_records.open_record' : 'client_records.start_record')) ?></a>
        <?php endif; ?>
        <a href="/contacts/<?= e($contact['id']) ?>/edit" class="btn"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
        <?php if ($canCreateFollowUp ?? false): ?>
            <a href="/followups/create?related_type=contact&amp;related_id=<?= e($contact['id']) ?>" class="btn"><?= icon('calendar-check') ?><?= e(t('followups.new')) ?></a>
        <?php endif; ?>
        <form method="post" action="/contacts/<?= e($contact['id']) ?>/delete" class="inline-form">
            <?= Csrf::field() ?>
            <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
        </form>
    </div>
</section>

<?php if ($relatedSummary !== [] || $company !== null): ?>
<section aria-labelledby="contact-related-title">
    <h2 id="contact-related-title" class="visually-hidden"><?= e(t('contacts.related_title_section')) ?></h2>
    <div class="kpi-grid kpi-grid--compact">
        <?php if ($company !== null): ?>
            <a href="/companies/<?= e($company['id']) ?>" class="card kpi">
                <span class="kpi__top">
                    <span class="kpi__label"><?= e(t('common.company')) ?></span>
                    <span class="kpi__icon"><?= icon('building') ?></span>
                </span>
                <span class="kpi__unit"><?= e($company['name']) ?></span>
            </a>
        <?php endif; ?>
        <?php foreach ($relatedSummary as $tile): ?>
            <button type="button" class="card kpi"
                    data-related-url="/contacts/<?= e($contact['id']) ?>/related/<?= e($tile['type']) ?>"
                    data-related-title="<?= e(t($tile['label_key'])) ?>">
                <span class="kpi__top">
                    <span class="kpi__label"><?= e(t($tile['label_key'])) ?></span>
                    <span class="kpi__icon<?= $tile['tone'] !== '' ? ' kpi__icon--' . e($tile['tone']) : '' ?>"><?= icon($tile['icon']) ?></span>
                </span>
                <span class="kpi__value"><?= e($tile['count']) ?></span>
            </button>
        <?php endforeach; ?>
    </div>
</section>

<dialog class="modal modal--wide" id="related-dialog" aria-labelledby="related-dialog-title" data-error-text="<?= e(t('contacts.related_load_error')) ?>">
    <div class="modal__head">
        <h2 class="modal__title" id="related-dialog-title" data-related-title></h2>
        <form method="dialog">
            <button type="submit" class="btn btn--ghost btn--icon" aria-label="<?= e(t('common.close')) ?>"><?= icon('x') ?></button>
        </form>
    </div>
    <div class="modal__content" data-related-body></div>
</dialog>
<?php endif; ?>

<div class="tab-group" data-tabs>
<section class="card card--tabs">
    <div class="tabs" role="tablist">
        <button type="button" class="tab-btn active" data-tab="info" aria-selected="true"><?= icon('user') ?><?= e(t('contacts.tab_details')) ?></button>
        <?php if ($hasCustomFields): ?>
            <button type="button" class="tab-btn" data-tab="custom" aria-selected="false"><?= icon('sliders') ?><?= e(t('common.custom_fields')) ?></button>
        <?php endif; ?>
        <button type="button" class="tab-btn" data-tab="activity" aria-selected="false"><?= icon('activity') ?><?= e(t('contacts.tab_activity')) ?></button>
    </div>
</section>

<div class="tab-panel tab-panel--stack" data-panel="info">
    <section class="card">
        <h2><?= e(t('contacts.phones_and_emails')) ?></h2>
        <?php if ($phones === [] && $emails === []): ?>
            <p class="muted"><?= e(t('contacts.no_contact_info')) ?></p>
        <?php else: ?>
        <ul>
            <?php foreach ($phones as $p): ?>
                <li>
                    <?= e(t('contacts.phone_type_' . $p['type'])) ?>: <span class="ltr"><?= e($p['number']) ?></span><?= !empty($p['extension']) ? ' (' . e(t('contacts.ext')) . ' ' . e($p['extension']) . ')' : '' ?><?= $p['is_primary'] ? ' <span class="tag-pill">' . e(t('common.default')) . '</span>' : '' ?>
                </li>
            <?php endforeach; ?>
            <?php foreach ($emails as $em): ?>
                <li><?= e(t('common.email')) ?>: <span class="ltr"><?= e($em['email']) ?></span><?= $em['is_primary'] ? ' <span class="tag-pill">' . e(t('common.default')) . '</span>' : '' ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php if ($related['social'] !== []): ?>
            <h3><?= e(t('contacts.social_networks')) ?></h3>
            <ul>
                <?php foreach ($related['social'] as $s): ?>
                    <li>
                        <?= e($s['platform'] === 'custom' ? ($s['custom_label'] ?? t('common.other')) : ($socialLabels[$s['platform']] ?? $s['platform'])) ?>:
                        <span class="ltr"><?= e($s['value']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php if ($location !== '' || $facts !== []): ?>
    <section class="card">
        <h2><?= e(t('companies.address')) ?></h2>
        <?php if ($location !== ''): ?><p><?= e($location) ?></p><?php endif; ?>
        <?php if ($facts !== []): ?>
        <dl class="fact-row">
            <?php foreach ($facts as $labelKey => [$value, $ltr]): ?>
                <div class="fact"><dt><?= e(t($labelKey)) ?></dt><dd<?= $ltr ? ' class="ltr"' : '' ?>><?= e($value) ?></dd></div>
            <?php endforeach; ?>
        </dl>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if (!empty($contact['description']) || $related['interests'] !== []): ?>
    <section class="card">
        <h2><?= e(t('contacts.tab_notes_interests')) ?></h2>
        <?php if (!empty($contact['description'])): ?>
            <p class="prose-text"><?= nl2br(e($contact['description'])) ?></p>
        <?php endif; ?>
        <?php if ($related['interests'] !== []): ?>
            <p>
                <?php foreach ($related['interests'] as $interest): ?>
                    <span class="tag-pill"><?= e($interest['interest']) ?></span>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
    </section>
    <?php endif; ?>
    <?php $panelSubjectType = 'contact'; $panelSubject = $contact; include $theme->templatesPath() . '/partials/related-panels.php'; ?>
</div>

<?php if ($hasCustomFields): ?>
<div class="tab-panel tab-panel--stack" data-panel="custom" hidden>
    <section class="card card--form">
        <h2><?= e(t('common.custom_fields')) ?></h2>
        <form method="post" action="/contacts/<?= e($contact['id']) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="first_name" value="<?= e($contact['first_name']) ?>">
            <?php foreach ($customFieldDefs as $def): ?>
                <label for="custom_<?= e($def['field_key']) ?>"><?= e($def['label']) ?></label>
                <input type="text" id="custom_<?= e($def['field_key']) ?>" name="custom[<?= e($def['field_key']) ?>]"
                       value="<?= e($customFieldValues[$def['field_key']] ?? '') ?>">
            <?php endforeach; ?>
            <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save_custom_fields')) ?></button>
        </form>
    </section>
</div>
<?php endif; ?>

<div class="tab-panel tab-panel--stack" data-panel="activity" hidden>
<?php
$type = 'contact';
$id = (int) $contact['id'];
include $theme->templatesPath() . '/crm/_record_support.php';
?>
</div>
</div>
