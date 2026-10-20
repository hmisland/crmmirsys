<?php

declare(strict_types=1);

namespace Modules\WooCommerce\Segments;

use Modules\Marketing\Segments\Conditions\BaseCondition;
use Modules\Marketing\Segments\SqlParams;

/**
 * "Purchased product" (Phase C): the contact has a purchase (completed /
 * processing order) containing this product. Products belong to one shop
 * (woo_products is per connection), so the choice already names the shop.
 */
final class WooProductCondition extends BaseCondition
{
    /**
     * @param \Closure(): array<string, string> $products product id => "name — shop", current organization
     */
    public function __construct(private readonly \Closure $products)
    {
        parent::__construct('woo_product', 'woocommerce.segment.field.product', 'woocommerce.segment.category');
    }

    public function operators(): array
    {
        return ['has' => self::TYPE_CHOICE, 'not_has' => self::TYPE_CHOICE];
    }

    public function choices(): array
    {
        return ($this->products)();
    }

    public function toSql(string $operator, mixed $value, SqlParams $params): string
    {
        $exists = 'EXISTS (SELECT 1 FROM woo_order_items i'
            . ' JOIN woo_orders o ON o.id = i.order_id JOIN woo_customers wc ON wc.id = o.customer_id'
            . ' WHERE wc.contact_id = c.id AND i.product_id = ' . $params->bind((int) $value)
            . ' AND i.organization_id = ' . $params->org() . ' AND o.organization_id = ' . $params->org() . ' AND wc.organization_id = ' . $params->org()
            . " AND o.status IN ('" . implode("', '", WooPurchaseCondition::COUNTED_STATUSES) . "'))";

        return $operator === 'has' ? $exists : 'NOT ' . $exists;
    }
}
