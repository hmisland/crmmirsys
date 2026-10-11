<?php
/** @var array $company */
/** @var array|null $category */
/** @var array $contacts */
/** @var array $notes */
/** @var array $tags */
/** @var array $allTags */
/** @var array $timeline */
/** @var array $customFieldDefs */
/** @var array $customFieldValues */
/** @var array $attachments */
/** @var \App\Infrastructure\Theme\ThemeLoader $theme */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;
?>
<?php
$facts = array_filter([
    ['tag', t('companies.category'), $category['name'] ?? null, false],
    ['user', t('companies.ceo_name'), $company['ceo_name'] ?? null, false],
    ['key', t('companies.national_id'), $company['national_id'] ?? null, true],
    ['key', t('companies.economic_code'), $company['economic_code'] ?? null, true],
    ['clipboard', t('companies.registration_number'), $company['registration_number'] ?? null, true],
    ['phone', t('common.phone'), $company['phone'] ?? null, true],
    ['mail', t('common.email'), $company['email'] ?? null, true],
    ['globe', t('companies.website'), $company['website'] ?? null, true],
    ['briefcase', t('companies.industry'), $company['industry'] ?? null, false],
    ['users', t('companies.employees'), $company['employees_count'] ?? null, false],
    ['building', t('companies.address'), trim(implode('، ', array_filter([$company['country'] ?? '', $company['city'] ?? '', $company['address'] ?? '']))), false],
], fn ($f) => $f[2] !== null && $f[2] !== '');
?>
<section class="card">
    <h1><?= e($company['name']) ?></h1>
    <?php if (!empty($company['brand'])): ?>
        <p class="muted"><?= e(t('companies.brand')) ?>: <strong><?= e($company['brand']) ?></strong></p>
    <?php endif; ?>
    <?php if ($facts !== []): ?>
        <dl class="fact-row">
            <?php foreach ($facts as [$factIcon, $factLabel, $factValue, $factLtr]): ?>
                <div class="fact">
                    <dt><?= icon($factIcon) ?><?= e($factLabel) ?></dt>
                    <dd<?= $factLtr ? ' class="ltr"' : '' ?>><?= e((string) $factValue) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>
    <?php if (!empty($company['description'])): ?>
        <h2><?= e(t('companies.description')) ?></h2>
        <p><?= nl2br(e($company['description'])) ?></p>
    <?php endif; ?>
    <div class="actions">
        <a href="/companies/<?= e($company['id']) ?>/edit" class="btn"><?= icon('pencil') ?><?= e(t('common.edit')) ?></a>
        <form method="post" action="/companies/<?= e($company['id']) ?>/delete" class="inline-form">
            <?= Csrf::field() ?>
            <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
        </form>
    </div>
</section>

<section class="card">
    <h2><?= e(t('companies.contacts_at_company')) ?></h2>
    <ul>
        <?php foreach ($contacts as $c): ?>
            <li><a href="/contacts/<?= e($c['id']) ?>"><?= e(trim($c['first_name'] . ' ' . ($c['last_name'] ?? ''))) ?></a></li>
        <?php endforeach; ?>
        <?php if ($contacts === []): ?><li class="muted"><?= e(t('companies.none_yet')) ?></li><?php endif; ?>
    </ul>
</section>

<?php if ($customFieldDefs !== []): ?>
<section class="card">
    <h2><?= e(t('common.custom_fields')) ?></h2>
    <form method="post" action="/companies/<?= e($company['id']) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="name" value="<?= e($company['name']) ?>">
        <?php foreach ($customFieldDefs as $def): ?>
            <label for="custom_<?= e($def['field_key']) ?>"><?= e($def['label']) ?></label>
            <input type="text" id="custom_<?= e($def['field_key']) ?>" name="custom[<?= e($def['field_key']) ?>]"
                   value="<?= e($customFieldValues[$def['field_key']] ?? '') ?>">
        <?php endforeach; ?>
        <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save_custom_fields')) ?></button>
    </form>
</section>
<?php endif; ?>

<?php
$panelSubjectType = 'company';
$panelSubject = $company;
include $theme->templatesPath() . '/partials/related-panels.php';

$type = 'company';
$id = (int) $company['id'];
include $theme->templatesPath() . '/crm/_record_support.php';
?>
