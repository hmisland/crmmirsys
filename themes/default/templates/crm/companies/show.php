<?php
/** @var array $company */
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
use App\Infrastructure\Security\Csrf;
?>
<section class="card">
    <h1><?= e($company['name']) ?></h1>
    <p class="muted">
        <?= $company['website'] ? e($company['website']) . ' · ' : '' ?>
        <?= $company['industry'] ? e($company['industry']) . ' · ' : '' ?>
        <?= e($company['city'] ?? '') ?>
    </p>
    <p>
        <a href="/companies/<?= e($company['id']) ?>/edit" class="btn"><?= e(t('common.edit')) ?></a>
        <form method="post" action="/companies/<?= e($company['id']) ?>/delete" class="inline-form">
            <?= Csrf::field() ?>
            <button type="submit" class="link-button"><?= e(t('common.delete')) ?></button>
        </form>
    </p>
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
        <button type="submit" class="btn btn--primary"><?= e(t('common.save_custom_fields')) ?></button>
    </form>
</section>
<?php endif; ?>

<?php
$type = 'company';
$id = (int) $company['id'];
include $theme->templatesPath() . '/crm/_record_support.php';
?>
