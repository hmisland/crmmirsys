<?php
/** @var array|null $company */
/** @var array $errors */
/** @var array $old */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
$val = fn (string $field, string $default = '') => e($old[$field] ?? $company[$field] ?? $default);
$action = $company !== null ? '/companies/' . $company['id'] : '/companies';
?>
<section class="card card--form" style="max-width:600px">
    <h1><?= $company !== null ? e(t('companies.edit')) : e(t('companies.new')) ?></h1>

    <form method="post" action="<?= e($action) ?>">
        <?= Csrf::field() ?>

        <label for="name"><?= e(t('common.name')) ?></label>
        <input type="text" id="name" name="name" value="<?= $val('name') ?>" required autofocus>
        <?php if ($fieldError('name')): ?><div class="field-error"><?= e($fieldError('name')) ?></div><?php endif; ?>

        <label for="website"><?= e(t('companies.website')) ?></label>
        <input type="text" id="website" name="website" value="<?= $val('website') ?>">

        <label for="email"><?= e(t('common.email')) ?></label>
        <input type="email" id="email" name="email" value="<?= $val('email') ?>">

        <label for="phone"><?= e(t('common.phone')) ?></label>
        <input type="text" id="phone" name="phone" value="<?= $val('phone') ?>">

        <label for="industry"><?= e(t('companies.industry')) ?></label>
        <input type="text" id="industry" name="industry" value="<?= $val('industry') ?>">

        <label for="employees_count"><?= e(t('companies.employees')) ?></label>
        <input type="number" id="employees_count" name="employees_count" value="<?= $val('employees_count') ?>" min="0">

        <label for="address"><?= e(t('companies.address')) ?></label>
        <input type="text" id="address" name="address" value="<?= $val('address') ?>">

        <label for="city"><?= e(t('companies.city')) ?></label>
        <input type="text" id="city" name="city" value="<?= $val('city') ?>">

        <label for="country"><?= e(t('companies.country')) ?></label>
        <input type="text" id="country" name="country" value="<?= $val('country') ?>">

        <button type="submit" class="btn btn--primary"><?= e(t('common.save')) ?></button>
    </form>
</section>
