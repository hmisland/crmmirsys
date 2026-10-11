<?php
/** @var string $token */
/** @var string $fileName */
/** @var string[] $headers column index => header */
/** @var string[] $samples column index => first non-empty value */
/** @var array<int, string> $mapping column index => target */
/** @var array $definitions contact custom field definitions */
/** @var array $rows */
/** @var array $columns preview table columns */
/** @var array<string, string> $targets target => label translation key */
/** @var string $newCustom */
/** @var array{category: bool, company: bool, custom_field: bool} $rights */
/** @var array $categories */
/** @var array $companies */
/** @var array<string, string> $selection */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;
use App\Infrastructure\Security\Csrf;

$validCount = 0;
$updateCount = 0;
foreach ($rows as $r) {
    if ($r['errors'] === []) {
        $validCount++;
        if ($r['action'] === 'update') {
            $updateCount++;
        }
    }
}

$cellValue = function (array $row, array $column): string {
    $target = $column['target'];
    if (str_starts_with($target, 'custom:')) {
        return $row['custom'][substr($target, 7)] ?? '';
    }
    if ($target === '__new_custom__') {
        return $row['new_custom'][$column['column']] ?? '';
    }

    return $row['data'][$target] ?? '';
};
$ltrTargets = ['email', 'phone', 'landline', 'postal_code', 'national_id', 'economic_code'];
?>
<form method="post" action="/contacts/import/commit" class="page-stack" data-import-form>
    <?= Csrf::field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <input type="hidden" name="file_name" value="<?= e($fileName) ?>">
    <input type="hidden" name="excluded_rows" value="" data-excluded-rows>

    <section class="card">
        <h1><?= e(t('import.review_title')) ?></h1>
        <p class="muted">
            <?= e($fileName) ?> ·
            <?= e(t('import.preview_stats', ['total' => count($rows), 'valid' => $validCount, 'update' => $updateCount])) ?>
        </p>
    </section>

    <section class="card card--form">
        <h2><?= e(t('import.batch_settings')) ?></h2>
        <p class="muted"><?= e(t('import.batch_settings_hint')) ?></p>
        <div class="form-grid">
            <div>
                <label for="category_id"><?= e(t('contacts.category')) ?></label>
                <select id="category_id" name="category_id">
                    <option value=""><?= e(t('common.none')) ?></option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat['id']) ?>" <?= $selection['category_id'] === (string) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($rights['category']): ?>
                    <label for="new_category_name"><?= e(t('import.new_category')) ?></label>
                    <input type="text" id="new_category_name" name="new_category_name" value="<?= e($selection['new_category_name']) ?>" maxlength="100">
                    <div class="field-hint"><?= e(t('import.new_category_hint')) ?></div>
                <?php endif; ?>
            </div>
            <div>
                <label for="company_id"><?= e(t('common.company')) ?></label>
                <select id="company_id" name="company_id">
                    <option value=""><?= e(t('common.none')) ?></option>
                    <?php foreach ($companies as $company): ?>
                        <option value="<?= e($company['id']) ?>" <?= $selection['company_id'] === (string) $company['id'] ? 'selected' : '' ?>><?= e($company['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($rights['company']): ?>
                    <label for="new_company_name"><?= e(t('import.new_company')) ?></label>
                    <input type="text" id="new_company_name" name="new_company_name" value="<?= e($selection['new_company_name']) ?>" maxlength="255">
                <?php endif; ?>
                <div class="field-hint"><?= e(t('import.company_hint')) ?></div>
            </div>
        </div>
    </section>

    <section class="card">
        <h2><?= e(t('import.mapping_title')) ?></h2>
        <p class="muted"><?= e(t('import.mapping_hint')) ?></p>

        <div class="table-responsive">
        <table class="table table--compact table--map">
            <thead>
                <tr>
                    <th><?= e(t('import.file_column')) ?></th>
                    <th><?= e(t('import.sample_value')) ?></th>
                    <th><?= e(t('import.import_as')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($headers as $index => $header): ?>
                <?php $current = $mapping[$index] ?? ''; ?>
                <tr>
                    <td><label for="map_<?= e($index) ?>"><?= e($header) ?></label></td>
                    <td class="muted"><span class="cell-clip"><?= e($samples[$index] !== '' ? $samples[$index] : '—') ?></span></td>
                    <td>
                        <select id="map_<?= e($index) ?>" name="mapping[<?= e($index) ?>]" data-remap>
                            <option value=""><?= e(t('import.ignore_column')) ?></option>
                            <optgroup label="<?= e(t('import.contact_fields')) ?>">
                                <?php foreach ($targets as $target => $labelKey): ?>
                                    <option value="<?= e($target) ?>" <?= $current === $target ? 'selected' : '' ?>><?= e(t($labelKey)) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php if ($definitions !== [] || $rights['custom_field']): ?>
                                <optgroup label="<?= e(t('common.custom_fields')) ?>">
                                    <?php foreach ($definitions as $def): ?>
                                        <option value="custom:<?= e($def['field_key']) ?>" <?= $current === 'custom:' . $def['field_key'] ? 'selected' : '' ?>><?= e($def['label']) ?></option>
                                    <?php endforeach; ?>
                                    <?php if ($rights['custom_field']): ?>
                                        <option value="<?= e($newCustom) ?>" <?= $current === $newCustom ? 'selected' : '' ?>>+ <?= e(t('import.new_custom_field', ['name' => $header])) ?></option>
                                    <?php endif; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <p class="actions">
            <button type="submit" class="btn" formaction="/contacts/import/preview" data-remap-submit><?= icon('refresh') ?><?= e(t('import.refresh_preview')) ?></button>
        </p>
    </section>

    <section class="card">
        <h2><?= e(t('import.rows_title')) ?></h2>

        <?php if ($rows === []): ?>
            <p class="muted"><?= e(t('import.no_rows_found')) ?></p>
        <?php else: ?>
        <p class="muted"><?= e(t('import.fix_hint')) ?></p>
        <div class="table-responsive">
        <table class="table table--compact">
            <thead>
                <tr>
                    <th><input type="checkbox" checked data-row-toggle-all aria-label="<?= e(t('import.select_all')) ?>"></th>
                    <th><?= e(t('import.row')) ?></th>
                    <th><?= e(t('import.action')) ?></th>
                    <?php foreach ($columns as $column): ?>
                        <th><?= e($column['label_key'] !== null ? t($column['label_key']) : (string) $column['label']) ?></th>
                    <?php endforeach; ?>
                    <th><?= e(t('org.label')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $hasErrors = $row['errors'] !== []; ?>
                <tr<?= $hasErrors ? ' class="row--danger"' : '' ?>>
                    <td>
                        <input type="checkbox" value="<?= e($row['row_number']) ?>" data-row
                               aria-label="<?= e(t('import.row')) ?> <?= e($row['row_number']) ?>"
                               <?= $hasErrors ? 'disabled' : 'checked' ?>>
                    </td>
                    <td><?= e($row['row_number']) ?></td>
                    <td>
                        <?php if ($hasErrors): ?>
                            <?= badge(t('import.action_skip'), 'cancelled') ?>
                        <?php elseif ($row['action'] === 'update'): ?>
                            <?= badge(t('import.action_update'), 'pending') ?>
                        <?php else: ?>
                            <?= badge(t('import.action_create'), 'new') ?>
                        <?php endif; ?>
                    </td>
                    <?php foreach ($columns as $column): ?>
                        <?php $value = $cellValue($row, $column); ?>
                        <td><span class="cell-clip<?= in_array($column['target'], $ltrTargets, true) ? ' ltr' : '' ?>"><?= e($value !== '' ? $value : '—') ?></span></td>
                    <?php endforeach; ?>
                    <td class="muted cell-note">
                        <?php foreach ($row['errors'] as $code): ?><div class="text-danger"><?= e(t('import.row_error.' . $code)) ?></div><?php endforeach; ?>
                        <?php foreach ($row['notes'] as $code): ?><div><?= e(t('import.row_note.' . $code)) ?></div><?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <p class="actions">
            <?php if ($validCount > 0): ?>
                <button type="submit" class="btn btn--primary"><?= icon('check') ?><?= e(t('import.import_checked')) ?></button>
            <?php endif; ?>
            <a href="/contacts/import" class="btn"><?= icon('x') ?><?= e(t('org.cancel')) ?></a>
        </p>
    </section>
</form>
