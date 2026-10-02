<?php
/** @var array $contact */
/** @var array|null $company */
/** @var array|null $category */
/** @var array $related array{phones: array, emails: array, social: array} */
/** @var array $notes */
/** @var array $tags */
/** @var array $allTags */
/** @var array $timeline */
/** @var array $customFieldDefs */
/** @var array $customFieldValues */
/** @var \App\Infrastructure\Theme\ThemeLoader $theme */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;

$genderLabel = match ($contact['gender'] ?? null) {
    'male' => t('contacts.title_mr'),
    'female' => t('contacts.title_ms'),
    default => null,
};

$socialLabels = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'youtube' => 'YouTube',
    'twitter' => 'Twitter/X', 'linkedin' => 'LinkedIn', 'telegram' => 'Telegram', 'whatsapp' => 'WhatsApp'];
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
        <p><a href="/companies/<?= e($company['id']) ?>"><?= e($company['name']) ?></a></p>
    <?php endif; ?>
    <?php if ($category !== null): ?>
        <p class="muted"><?= e(t('contacts.category')) ?>: <?= e($category['name']) ?></p>
    <?php endif; ?>
    <?php if (!empty($contact['description'])): ?>
        <p><?= nl2br(e($contact['description'])) ?></p>
    <?php endif; ?>
    <?php if ($related['interests'] !== []): ?>
        <p>
            <?php foreach ($related['interests'] as $interest): ?>
                <span class="tag-pill"><?= e($interest['interest']) ?></span>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>
    <p>
        <a href="/contacts/<?= e($contact['id']) ?>/edit" class="btn"><?= e(t('common.edit')) ?></a>
        <form method="post" action="/contacts/<?= e($contact['id']) ?>/delete" class="inline-form">
            <?= Csrf::field() ?>
            <button type="submit" class="link-button"><?= e(t('common.delete')) ?></button>
        </form>
    </p>
</section>

<?php if ($related['phones'] !== [] || $related['emails'] !== []): ?>
<section class="card">
    <h2><?= e(t('contacts.phones_and_emails')) ?></h2>
    <ul>
        <?php foreach ($related['phones'] as $p): ?>
            <li>
                <?= e(ucfirst($p['type'])) ?>: <?= e($p['number']) ?><?= !empty($p['extension']) ? ' (' . e(t('contacts.ext')) . ' ' . e($p['extension']) . ')' : '' ?><?= $p['is_primary'] ? ' <span class="tag-pill">' . e(t('common.default')) . '</span>' : '' ?>
            </li>
        <?php endforeach; ?>
        <?php foreach ($related['emails'] as $em): ?>
            <li><?= e(t('common.email')) ?>: <?= e($em['email']) ?><?= $em['is_primary'] ? ' <span class="tag-pill">' . e(t('common.default')) . '</span>' : '' ?></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($related['social'] !== []): ?>
<section class="card">
    <h2><?= e(t('contacts.social_networks')) ?></h2>
    <ul>
        <?php foreach ($related['social'] as $s): ?>
            <li>
                <?= e($s['platform'] === 'custom' ? ($s['custom_label'] ?? t('common.other')) : ($socialLabels[$s['platform']] ?? $s['platform'])) ?>:
                <?= e($s['value']) ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($customFieldDefs !== []): ?>
<section class="card">
    <h2><?= e(t('common.custom_fields')) ?></h2>
    <form method="post" action="/contacts/<?= e($contact['id']) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="first_name" value="<?= e($contact['first_name']) ?>">
        <?php foreach ($customFieldDefs as $def): ?>
            <label for="custom_<?= e($def['field_key']) ?>"><?= e($def['label']) ?></label>
            <input type="text" id="custom_<?= e($def['field_key']) ?>" name="custom[<?= e($def['field_key']) ?>]"
                   value="<?= e($customFieldValues[$def['field_key']] ?? '') ?>">
        <?php endforeach; ?>
        <button type="submit" class="btn btn--primary"><?= e(t('common.save_custom_fields')) ?></button>
    </form>
</section>
<?php endif; ?>

<?php
$type = 'contact';
$id = (int) $contact['id'];
include $theme->templatesPath() . '/crm/_record_support.php';
?>
