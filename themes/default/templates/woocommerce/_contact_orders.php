<?php
/**
 * "Shop orders" panel on the contact page: the existing woo_orders rows
 * of every shop customer linked to this contact (no copy of the orders).
 *
 * @var array[] $orders ContactSyncRepository::ordersForContact()
 */

use function App\Infrastructure\Theme\badge;
use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\local_time;
use function App\Infrastructure\Theme\money;
use function App\Infrastructure\Theme\t;

// WooCommerce's standard order statuses; a custom status from a plugin is shown as stored.
$tone = ['completed' => 'completed', 'processing' => 'in_progress', 'on-hold' => 'pending', 'pending' => 'pending', 'cancelled' => 'cancelled', 'refunded' => 'cancelled', 'failed' => 'failed', 'checkout-draft' => null];
?>
<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th><?= e(t('woocommerce.orders.shop')) ?></th>
                <th><?= e(t('woocommerce.orders.number')) ?></th>
                <th><?= e(t('woocommerce.orders.date')) ?></th>
                <th><?= e(t('woocommerce.orders.status')) ?></th>
                <th class="num"><?= e(t('woocommerce.orders.total')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($orders === []): ?>
                <tr><td colspan="5" class="muted"><?= e(t('woocommerce.orders.none')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($orders as $o): ?>
                <?php $status = (string) $o['status']; ?>
                <tr>
                    <td><?= e($o['shop_name']) ?></td>
                    <td><span class="ltr">#<?= e($o['order_number']) ?></span></td>
                    <td><span class="ltr"><?= e(local_time($o['external_created_at']) ?? '—') ?></span></td>
                    <td><?= badge(array_key_exists($status, $tone) ? t('woocommerce.orders.status.' . $status) : $status, $tone[$status] ?? null) ?></td>
                    <td class="num"><span class="ltr"><?= e(money($o['total'], $o['currency'] !== null ? (string) $o['currency'] : null)) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
