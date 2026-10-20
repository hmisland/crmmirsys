<?php

declare(strict_types=1);

namespace Modules\WooCommerce\Segments;

use App\Infrastructure\Repositories\BaseRepository;
use App\Infrastructure\Repositories\OrganizationContext;
use Modules\Marketing\Segments\SegmentConditionProvider;

/**
 * Phase C: WooCommerce purchase conditions for Marketing's dynamic
 * segments, registered on Marketing's SegmentConditionRegistry (the
 * extension point) when both modules are installed. Membership is
 * resolved from the already synchronized woo_* tables by the existing
 * segment engine (preview, campaign approval snapshot, background
 * expansion) — nothing is copied or pre-aggregated.
 *
 * Not offered: "product category" — WooCommerce order line items carry
 * no categories and the CRM does not store the shop's catalog.
 */
final class WooSegmentConditions extends BaseRepository implements SegmentConditionProvider
{
    private const MAX_PRODUCTS = 1000;

    public function conditions(): array
    {
        $shops = fn (): array => $this->shops();

        return [
            new WooPurchaseCondition('woo_purchased', 'woocommerce.segment.field.purchased', $shops),
            new WooPurchaseCondition('woo_order_count', 'woocommerce.segment.field.order_count', $shops),
            new WooPurchaseCondition('woo_total_spent', 'woocommerce.segment.field.total_spent', $shops),
            new WooProductCondition(fn (): array => $this->products()),
            new WooPurchaseCondition('woo_order_status', 'woocommerce.segment.field.order_status', $shops),
            new WooPurchaseCondition('woo_last_order', 'woocommerce.segment.field.last_order', $shops),
            new WooPurchaseCondition('woo_first_order', 'woocommerce.segment.field.first_order', $shops),
        ];
    }

    /** @return array<string, string> connection id => shop name (current organization) */
    private function shops(): array
    {
        $rows = $this->findMany(
            "SELECT id, name FROM integration_connections WHERE organization_id = :org AND provider = 'woocommerce' ORDER BY name, id",
            ['org' => OrganizationContext::id()]
        );

        return array_column(array_map(fn (array $r) => ['id' => (string) $r['id'], 'name' => (string) $r['name']], $rows), 'name', 'id');
    }

    /** @return array<string, string> product id => "name — shop" (current organization) */
    private function products(): array
    {
        $rows = $this->findMany(
            'SELECT p.id, p.name, c.name AS shop FROM woo_products p JOIN integration_connections c ON c.id = p.connection_id AND c.organization_id = p.organization_id
             WHERE p.organization_id = :org ORDER BY p.name, p.id LIMIT ' . self::MAX_PRODUCTS,
            ['org' => OrganizationContext::id()]
        );
        $choices = [];
        foreach ($rows as $row) {
            $choices[(string) $row['id']] = $row['name'] . ' — ' . $row['shop'];
        }

        return $choices;
    }
}
