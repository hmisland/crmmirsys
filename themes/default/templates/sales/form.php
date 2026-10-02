<?php
/** @var array|null $deal */
/** @var array $pipeline */
/** @var array $stages */
/** @var array $contacts */
/** @var array $companies */
/** @var array $errors */
/** @var array $old */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use App\Infrastructure\Security\Csrf;

$fieldError = fn (string $field) => isset($errors[$field]) ? $errors[$field][0] : null;
$val = fn (string $field, string $default = '') => e($old[$field] ?? $deal[$field] ?? $default);
$action = $deal !== null ? '/deals/' . $deal['id'] : '/deals';
?>
<section class="card card--form" style="max-width:600px">
    <h1><?= $deal !== null ? e(t('deals.edit')) : e(t('deals.new')) ?></h1>

    <form method="post" action="<?= e($action) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="pipeline_id" value="<?= e($pipeline['id']) ?>">

        <label for="name"><?= e(t('common.name')) ?></label>
        <input type="text" id="name" name="name" value="<?= $val('name') ?>" required autofocus>
        <?php if ($fieldError('name')): ?><div class="field-error"><?= e($fieldError('name')) ?></div><?php endif; ?>

        <label for="stage_id"><?= e(t('deals.stage')) ?></label>
        <select id="stage_id" name="stage_id" required>
            <?php foreach ($stages as $stage): ?>
                <option value="<?= e($stage['id']) ?>" <?= (string) ($old['stage_id'] ?? $deal['stage_id'] ?? $stages[0]['id']) === (string) $stage['id'] ? 'selected' : '' ?>>
                    <?= e($stage['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="value"><?= e(t('deals.value')) ?></label>
        <input type="number" id="value" name="value" step="0.01" min="0" value="<?= $val('value', '0') ?>">

        <label for="currency_code"><?= e(t('deals.currency')) ?></label>
        <input type="text" id="currency_code" name="currency_code" value="<?= $val('currency_code', 'USD') ?>" maxlength="3">

        <label for="contact_id"><?= e(t('nav.contacts')) ?></label>
        <select id="contact_id" name="contact_id">
            <option value=""><?= e(t('common.none')) ?></option>
            <?php foreach ($contacts as $c): ?>
                <option value="<?= e($c['id']) ?>" <?= (string) ($old['contact_id'] ?? $deal['contact_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>>
                    <?= e(trim($c['first_name'] . ' ' . ($c['last_name'] ?? ''))) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="company_id"><?= e(t('common.company')) ?></label>
        <select id="company_id" name="company_id">
            <option value=""><?= e(t('common.none')) ?></option>
            <?php foreach ($companies as $c): ?>
                <option value="<?= e($c['id']) ?>" <?= (string) ($old['company_id'] ?? $deal['company_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>>
                    <?= e($c['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="expected_close_date"><?= e(t('deals.expected_close_date')) ?></label>
        <input type="text" class="crm-datepicker" id="expected_close_date" name="expected_close_date" value="<?= $val('expected_close_date') ?>">

        <label for="source"><?= e(t('leads.source')) ?></label>
        <input type="text" id="source" name="source" value="<?= $val('source') ?>">

        <button type="submit" class="btn btn--primary"><?= e(t('common.save')) ?></button>
    </form>
</section>
