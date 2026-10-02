<?php
/** @var array<string, string> $entityTypes */
/** @var array<string, array> $definitions */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$fieldTypes = ['text', 'number', 'date', 'boolean', 'select', 'multiselect', 'email', 'phone', 'url'];
?>
<section class="card">
    <h1><?= e(t('org.custom_fields')) ?></h1>
    <p class="muted"><?= e(t('org.custom_fields_hint')) ?></p>

    <?php foreach ($entityTypes as $type => $label): ?>
        <h2><?= e($label) ?></h2>
        <div class="table-responsive">
        <table class="table table--compact">
            <thead><tr><th><?= e(t('org.label')) ?></th><th><?= e(t('org.key')) ?></th><th><?= e(t('org.type')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($definitions[$type] as $def): ?>
                <tr>
                    <td><?= e($def['label']) ?></td>
                    <td><code><?= e($def['field_key']) ?></code></td>
                    <td><?= e(t('org.field_type.' . $def['field_type'])) ?></td>
                    <td>
                        <form method="post" action="/org/custom-fields/<?= e($def['id']) ?>/delete" class="inline-form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-button link-button--danger" data-confirm><?= icon('trash') ?><?= e(t('common.delete')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($definitions[$type] === []): ?>
                <tr><td colspan="4" class="muted"><?= e(t('org.no_custom_fields_for')) ?> <?= e($label) ?> <?= e(t('org.yet_suffix')) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    <?php endforeach; ?>
</section>

<section class="card card--form">
    <h2><?= e(t('org.add_custom_field')) ?></h2>
    <form method="post" action="/org/custom-fields">
        <?= Csrf::field() ?>

        <label for="entity_type"><?= e(t('org.applies_to')) ?></label>
        <select id="entity_type" name="entity_type" required>
            <?php foreach ($entityTypes as $type => $label): ?>
                <option value="<?= e($type) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="label"><?= e(t('org.field_label')) ?></label>
        <input type="text" id="label" name="label" required placeholder="<?= e(t('org.field_label_placeholder')) ?>">

        <label for="field_type"><?= e(t('org.field_type_label')) ?></label>
        <select id="field_type" name="field_type" required>
            <?php foreach ($fieldTypes as $ft): ?>
                <option value="<?= e($ft) ?>"><?= e(t('org.field_type.' . $ft)) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="options"><?= e(t('org.options_label')) ?></label>
        <textarea id="options" name="options" rows="3" style="padding:0.55rem;border:1px solid var(--color-border);border-radius:6px;font-family:inherit;"></textarea>

        <button type="submit" class="btn btn--primary"><?= icon('plus') ?><?= e(t('org.add_field')) ?></button>
    </form>
</section>
