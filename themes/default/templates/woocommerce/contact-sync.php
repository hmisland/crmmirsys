<?php
/**
 * Organization settings → Integrations → a WooCommerce shop → Contact
 * sync: whether shop customers become CRM contacts, the contact group
 * and tags they get, the source label, the field mapping, a manual run,
 * and the current state per customer.
 *
 * @var array $connection IntegrationConnection::toView()
 * @var array $form ContactSyncSettingsService::forForm()
 * @var array[] $categories contact groups of the organization
 * @var array<string, int> $counts customer sync status => count
 * @var array[] $problems skipped / conflicting customers
 * @var array{customers: int, orders: int, products: int, oldest_order: ?string, import_position: ?string} $shopData rows imported from the shop so far
 * @var array{pending: int, running: int, failed: int} $jobs the shop's import queue
 * @var array<string, string> $errors
 */

use App\Infrastructure\Security\Csrf;
use Modules\WooCommerce\ContactSync\ContactFieldMapper;

use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\t;
use function App\Infrastructure\Theme\help_link;

$id = (int) $connection['id'];
$error = fn (string $field): string => isset($errors[$field]) ? '<div class="field-error">' . e(t($errors[$field])) . '</div>' : '';
$summary = is_array($form['last_run_summary']) ? $form['last_run_summary'] : null;
$statusTone = ['created' => 'active', 'updated' => 'active', 'matched' => 'sent', 'skipped' => 'pending', 'conflict' => 'failed', 'pending' => 'pending', 'processing' => 'pending'];
?>
<div class="page-head">
    <div>
        <h1><?= e(t('woocommerce.contacts.title')) ?><?= help_link('woocommerce-contact-sync') ?></h1>
        <p><?= e($connection['name']) ?> — <span class="ltr"><?= e((string) ($connection['config']['store_url'] ?? '')) ?></span></p>
    </div>
    <div class="actions">
        <a href="/org/integrations#connection-<?= e($id) ?>" class="btn btn--ghost"><?= icon('arrow-left') ?><?= e(t('woocommerce.contacts.back')) ?></a>
    </div>
</div>

<section class="card">
    <div class="provider-card__head">
        <h2><?= icon('users') ?><?= e(t('woocommerce.contacts.state')) ?></h2>
        <?= $form['enabled'] ? badge(t('integrations.enabled'), 'enabled') : badge(t('integrations.disabled'), 'disabled') ?>
    </div>
    <p class="field-hint"><?= e(t('woocommerce.contacts.intro')) ?></p>
    <?php if (!empty($form['last_error'])): ?>
        <div class="alert alert--error"><?= e(t((string) $form['last_error'])) ?></div>
    <?php endif; ?>
    <?php if (!empty($connection['last_error'])): ?>
        <div class="alert alert--error"><?= e(t('woocommerce.contacts.import_error', ['error' => t((string) $connection['last_error'])])) ?></div>
    <?php endif; ?>
    <dl class="fact-row">
        <div class="fact">
            <dt><?= icon('package') ?><?= e(t('woocommerce.contacts.imported_customers')) ?></dt>
            <dd><?= e((string) $shopData['customers']) ?></dd>
        </div>
        <div class="fact">
            <dt><?= e(t('woocommerce.contacts.imported_orders')) ?></dt>
            <dd><?= e((string) $shopData['orders']) ?></dd>
        </div>
        <div class="fact">
            <dt><?= icon('check-circle') ?><?= e(t('integrations.last_success')) ?></dt>
            <dd><span class="ltr"><?= e(local_time($connection['last_successful_sync_at']) ?? '—') ?></span></dd>
        </div>
        <div class="fact">
            <dt><?= icon('list') ?><?= e(t('integrations.queue')) ?><?= help_link('woocommerce-order-sync') ?></dt>
            <dd><?= e(t('integrations.queue_counts', ['pending' => (string) ($jobs['pending'] + $jobs['running']), 'failed' => (string) $jobs['failed']])) ?></dd>
        </div>
    </dl>
    <p class="field-hint"><?= e(t('woocommerce.contacts.orders_vs_contacts')) ?></p>
    <?php if ($shopData['customers'] === 0): ?>
        <div class="alert alert--warning"><?= e(t('woocommerce.contacts.no_customers_yet')) ?></div>
    <?php endif; ?>
    <dl class="fact-row">
        <div class="fact">
            <dt><?= icon('clock') ?><?= e(t('woocommerce.contacts.last_run')) ?></dt>
            <dd><span class="ltr"><?= e(local_time($form['last_run_at']) ?? '—') ?></span></dd>
        </div>
        <?php foreach (['created', 'updated', 'matched', 'skipped', 'conflict', 'pending'] as $status): ?>
            <div class="fact">
                <dt><?= e(t('woocommerce.contacts.status.' . $status)) ?></dt>
                <dd><?= e((string) ($counts[$status] ?? 0)) ?></dd>
            </div>
        <?php endforeach; ?>
    </dl>
    <?php if ($summary !== null): ?>
        <p class="muted"><?= e(t('woocommerce.contacts.last_summary', array_map('strval', $summary + ['remaining' => 0]))) ?></p>
    <?php endif; ?>
    <div class="actions">
        <form method="post" action="/org/integrations/<?= e($id) ?>/contact-sync/run">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn--primary"<?= $form['enabled'] ? '' : ' disabled' ?>><?= icon('refresh') ?><?= e(t('woocommerce.contacts.run')) ?></button>
        </form>
    </div>
    <div class="field-hint"><?= e(t($form['enabled'] ? 'woocommerce.contacts.run_hint' : 'woocommerce.contacts.run_disabled_hint')) ?></div>
</section>

<section class="card card--form" id="import-history">
    <h2><?= icon('history') ?><?= e(t('woocommerce.contacts.history.title')) ?><?= help_link('woocommerce-import-older-orders') ?></h2>
    <p class="field-hint"><?= e(t('woocommerce.contacts.history.intro')) ?></p>
    <dl class="fact-row">
        <div class="fact">
            <dt><?= e(t('woocommerce.contacts.history.oldest')) ?></dt>
            <dd><span class="ltr"><?= e(local_time($shopData['oldest_order']) ?? '—') ?></span></dd>
        </div>
        <div class="fact">
            <dt><?= e(t('woocommerce.contacts.history.position')) ?></dt>
            <dd><span class="ltr"><?= e(local_time($shopData['import_position']) ?? '—') ?></span></dd>
        </div>
    </dl>
    <form method="post" action="/org/integrations/<?= e($id) ?>/contact-sync/history">
        <?= Csrf::field() ?>
        <label for="wcs-since"><?= e(t('woocommerce.contacts.history.since')) ?></label>
        <input type="text" class="crm-datepicker" id="wcs-since" name="since">
        <label class="check"><input type="checkbox" name="all_history" value="1"> <?= e(t('woocommerce.contacts.history.all')) ?></label>
        <div class="field-hint"><?= e(t('woocommerce.contacts.history.hint')) ?></div>
        <div class="actions">
            <button type="submit" class="btn"<?= $form['enabled'] ? '' : ' disabled' ?> data-confirm="<?= e(t('woocommerce.contacts.history.confirm')) ?>"><?= icon('download') ?><?= e(t('woocommerce.contacts.history.run')) ?></button>
        </div>
    </form>
</section>

<form method="post" action="/org/integrations/<?= e($id) ?>/contact-sync" class="card card--form">
    <?= Csrf::field() ?>
    <h2><?= icon('settings') ?><?= e(t('woocommerce.contacts.settings')) ?></h2>

    <label class="check"><input type="checkbox" name="enabled" value="1"<?= $form['enabled'] ? ' checked' : '' ?>> <?= e(t('woocommerce.contacts.field.enabled')) ?></label>
    <?= $error('enabled') ?>
    <div class="field-hint"><?= e(t('woocommerce.contacts.enabled_hint')) ?></div>

    <label for="wcs-category"><?= e(t('woocommerce.contacts.field.category')) ?></label>
    <select id="wcs-category" name="category_id">
        <option value=""><?= e(t('woocommerce.contacts.choose_category')) ?></option>
        <?php foreach ($categories as $category): ?>
            <option value="<?= e($category['id']) ?>"<?= (string) ($form['category_id'] ?? '') === (string) $category['id'] ? ' selected' : '' ?>><?= e($category['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?= $error('category_id') ?>
    <?php if ($categories === []): ?>
        <div class="alert alert--warning"><?= e(t('woocommerce.contacts.no_categories')) ?></div>
    <?php endif; ?>
    <div class="field-hint"><?= e(t('woocommerce.contacts.category_hint')) ?></div>

    <label for="wcs-new-category"><?= e(t('woocommerce.contacts.field.new_category')) ?></label>
    <input type="text" id="wcs-new-category" name="new_category" maxlength="100" value="<?= e((string) ($form['new_category'] ?? '')) ?>">
    <?= $error('new_category') ?>
    <div class="field-hint"><?= e(t('woocommerce.contacts.new_category_hint')) ?></div>

    <label for="wcs-tags"><?= e(t('woocommerce.contacts.field.tags')) ?></label>
    <input type="text" id="wcs-tags" name="tags" maxlength="1100" value="<?= e($form['tags']) ?>">
    <?= $error('tags') ?>
    <div class="field-hint"><?= e(t('woocommerce.contacts.tags_hint')) ?></div>

    <label for="wcs-source"><?= e(t('woocommerce.contacts.field.source')) ?></label>
    <input type="text" id="wcs-source" name="source" maxlength="100" value="<?= e($form['source']) ?>">
    <?= $error('source') ?>
    <div class="field-hint"><?= e(t('woocommerce.contacts.source_hint')) ?></div>

    <h3 class="form-section-title"><?= e(t('woocommerce.contacts.mapping')) ?></h3>
    <div class="field-hint"><?= e(t('woocommerce.contacts.mapping_hint')) ?></div>
    <?= $error('map') ?>
    <div class="table-responsive">
        <table class="table table--map">
            <thead>
                <tr>
                    <th><?= e(t('woocommerce.contacts.map.crm_field')) ?></th>
                    <th><?= e(t('woocommerce.contacts.map.shop_field')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (ContactFieldMapper::SOURCES as $destination => $sources): ?>
                    <tr>
                        <td><label for="wcs-map-<?= e($destination) ?>"><?= e(t('woocommerce.contacts.dest.' . $destination)) ?></label></td>
                        <td>
                            <select id="wcs-map-<?= e($destination) ?>" name="map[<?= e($destination) ?>]">
                                <?php foreach ($sources as $source): ?>
                                    <option value="<?= e($source) ?>"<?= ($form['mapping'][$destination] ?? '') === $source ? ' selected' : '' ?>><?= e(t('woocommerce.contacts.src.' . $source)) ?></option>
                                <?php endforeach; ?>
                                <option value=""<?= ($form['mapping'][$destination] ?? '') === '' ? ' selected' : '' ?>><?= e(t('woocommerce.contacts.src.none')) ?></option>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="field-hint"><?= e(t('woocommerce.contacts.phone_hint')) ?></div>
    <div class="field-hint"><?= e(t('woocommerce.contacts.rules_hint')) ?></div>

    <div class="actions">
        <button type="submit" class="btn btn--primary"><?= icon('save') ?><?= e(t('woocommerce.contacts.save')) ?></button>
    </div>
</form>

<section class="card">
    <h2><?= icon('alert-triangle') ?><?= e(t('woocommerce.contacts.problems')) ?></h2>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th><?= e(t('woocommerce.contacts.col.customer')) ?></th>
                    <th><?= e(t('woocommerce.contacts.col.email')) ?></th>
                    <th><?= e(t('woocommerce.contacts.col.phone')) ?></th>
                    <th><?= e(t('woocommerce.contacts.col.status')) ?></th>
                    <th><?= e(t('woocommerce.contacts.col.reason')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($problems === []): ?>
                    <tr><td colspan="5" class="muted"><?= e(t('woocommerce.contacts.no_problems')) ?></td></tr>
                <?php endif; ?>
                <?php foreach ($problems as $p): ?>
                    <?php $status = (string) $p['contact_sync_status']; ?>
                    <tr>
                        <td><?= e(trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')) ?: '—') ?></td>
                        <td><span class="ltr"><?= e((string) ($p['email'] ?? '—')) ?></span></td>
                        <td><span class="ltr"><?= e((string) ($p['phone'] ?? '—')) ?></span></td>
                        <td><?= badge(t('woocommerce.contacts.status.' . $status), $statusTone[$status] ?? null) ?></td>
                        <td><?= e(!empty($p['contact_sync_note']) ? t((string) $p['contact_sync_note']) : '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
