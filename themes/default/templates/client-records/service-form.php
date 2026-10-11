<?php
/** @var array $record */
/** @var array $contact */
/** @var array|null $service */
/** @var array $products */
/** @var array $invoices */
/** @var array<int, string> $users */
/** @var array $old */
/** @var array $errors */

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\icon;
use App\Infrastructure\Security\Csrf;

$isEdit = $service !== null;
$val = fn (string $key): string => e((string) ($old[$key] ?? ''));
$err = fn (string $key): string => isset($errors[$key][0]) ? '<div class="field-error">' . e(t($errors[$key][0])) . '</div>' : '';
$selected = fn (string $key, $value): string => (string) ($old[$key] ?? '') === (string) $value ? ' selected' : '';
$back = '/client-records/' . (int) $record['id'] . '?tab=services';
$action = '/client-records/' . (int) $record['id'] . '/services' . ($isEdit ? '/' . (int) $service['id'] : '');
?>
<div class="page-head">
    <div>
        <h1><?= e(t($isEdit ? 'client_records.edit_service' : 'client_records.new_service')) ?></h1>
        <p><?= e(trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''))) ?> · <span class="mono"><?= e($record['record_number']) ?></span></p>
    </div>
    <div class="quick-actions">
        <a href="<?= e($back) ?>" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('client_records.back')) ?></a>
    </div>
</div>

<section class="card card--form">
    <form method="post" action="<?= e($action) ?>">
        <?= Csrf::field() ?>

        <?php if ($products !== []): ?>
            <label for="product_id"><?= e(t('client_records.catalogue_service')) ?></label>
            <select id="product_id" name="product_id">
                <option value=""><?= e(t('client_records.not_from_catalogue')) ?></option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= e($p['id']) ?>"<?= $selected('product_id', $p['id']) ?>><?= e($p['name']) ?><?= !empty($p['sku']) ? ' (' . e($p['sku']) . ')' : '' ?></option>
                <?php endforeach; ?>
            </select>
            <?= $err('product_id') ?>
            <p class="field-hint"><?= e(t('client_records.catalogue_hint')) ?></p>
        <?php endif; ?>

        <label for="title"><?= e(t('client_records.service_title')) ?></label>
        <input type="text" id="title" name="title" value="<?= $val('title') ?>" maxlength="255"<?= $products === [] ? ' required' : '' ?>>
        <?= $err('title') ?>

        <div class="form-grid">
            <div>
                <label for="status"><?= e(t('client_records.status')) ?></label>
                <select id="status" name="status">
                    <?php foreach (['planned', 'in_progress', 'completed', 'cancelled'] as $s): ?>
                        <option value="<?= e($s) ?>"<?= $selected('status', $s) ?>><?= e(t('client_records.service_status.' . $s)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('status') ?>
            </div>
            <div>
                <label for="planned_sessions"><?= e(t('client_records.planned_sessions')) ?></label>
                <input type="number" id="planned_sessions" name="planned_sessions" min="1" max="500" value="<?= $val('planned_sessions') ?>">
                <?= $err('planned_sessions') ?>
            </div>
            <div>
                <label for="provider_user_id"><?= e(t('client_records.provider')) ?></label>
                <select id="provider_user_id" name="provider_user_id">
                    <option value=""><?= e(t('common.none')) ?></option>
                    <?php foreach ($users as $uid => $name): ?>
                        <option value="<?= e($uid) ?>"<?= $selected('provider_user_id', $uid) ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('provider_user_id') ?>
            </div>
            <div>
                <label for="location"><?= e(t('client_records.location')) ?></label>
                <input type="text" id="location" name="location" value="<?= $val('location') ?>" maxlength="150" placeholder="<?= e(t('client_records.location_placeholder')) ?>">
            </div>
            <div>
                <label for="started_on"><?= e(t('client_records.started_on')) ?></label>
                <input type="text" class="crm-datepicker" id="started_on" name="started_on" value="<?= $val('started_on') ?>">
                <?= $err('started_on') ?>
            </div>
            <div>
                <label for="completed_on"><?= e(t('client_records.completed_on')) ?></label>
                <input type="text" class="crm-datepicker" id="completed_on" name="completed_on" value="<?= $val('completed_on') ?>">
                <?= $err('completed_on') ?>
            </div>
        </div>

        <?php if ($invoices !== []): ?>
            <label for="invoice_id"><?= e(t('client_records.related_invoice')) ?></label>
            <select id="invoice_id" name="invoice_id">
                <option value=""><?= e(t('common.none')) ?></option>
                <?php foreach ($invoices as $inv): ?>
                    <option value="<?= e($inv['id']) ?>"<?= $selected('invoice_id', $inv['id']) ?>><?= e(($inv['invoice_number'] ?? '#' . $inv['id']) . ' — ' . $inv['issue_date'] . ' — ' . number_format((float) $inv['grand_total'], 0) . ' ' . $inv['currency_code']) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $err('invoice_id') ?>
        <?php endif; ?>

        <label for="notes"><?= e(t('client_records.service_notes')) ?></label>
        <textarea id="notes" name="notes" rows="4"><?= $val('notes') ?></textarea>

        <div class="actions">
            <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('common.save')) ?></button>
            <a href="<?= e($back) ?>" class="btn btn--ghost"><?= icon('x') ?><?= e(t('common.cancel')) ?></a>
        </div>
    </form>
</section>
