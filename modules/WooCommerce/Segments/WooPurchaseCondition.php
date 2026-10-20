<?php

declare(strict_types=1);

namespace Modules\WooCommerce\Segments;

use App\Infrastructure\Time\OrganizationTimezone;
use Modules\Marketing\Segments\Conditions\BaseCondition;
use Modules\Marketing\Segments\ScopedSegmentCondition;
use Modules\Marketing\Segments\SqlParams;

/**
 * Marketing segment conditions on synchronized WooCommerce orders (Phase C).
 *
 * A contact's orders are the woo_orders of every shop customer linked to
 * it (woo_customers.contact_id — the Phase A contact sync, or the
 * importer's email link). Every subquery is correlated to the contact
 * (c.id), pinned to the organization, and optionally to one shop (scope).
 *
 * A "purchase" is an order with status completed or processing (paid);
 * pending payment, on-hold, failed, cancelled, refunded and drafts never
 * count toward purchased / count / total / first / last order. The
 * "order status" condition matches any status explicitly.
 * Totals add the stored order totals as they are (a shop has one currency).
 */
final class WooPurchaseCondition extends BaseCondition implements ScopedSegmentCondition
{
    public const COUNTED_STATUSES = ['completed', 'processing'];
    public const ORDER_STATUSES = ['completed', 'processing', 'on-hold', 'pending', 'cancelled', 'refunded', 'failed'];

    /**
     * @param \Closure(): array<string, string> $shops connection id => name, current organization
     */
    public function __construct(string $key, string $labelKey, private readonly \Closure $shops)
    {
        parent::__construct($key, $labelKey, 'woocommerce.segment.category');
    }

    public function operators(): array
    {
        return match ($this->key) {
            'woo_purchased' => ['yes' => self::TYPE_NONE, 'no' => self::TYPE_NONE],
            'woo_order_count' => ['gte' => self::TYPE_NUMBER, 'lte' => self::TYPE_NUMBER, 'eq_number' => self::TYPE_NUMBER],
            'woo_total_spent' => ['gte' => self::TYPE_AMOUNT, 'lte' => self::TYPE_AMOUNT],
            'woo_order_status' => ['has' => self::TYPE_CHOICE, 'not_has' => self::TYPE_CHOICE],
            'woo_last_order', 'woo_first_order' => [
                'last_days' => self::TYPE_NUMBER, 'older_than_days' => self::TYPE_NUMBER, 'before' => self::TYPE_DATE, 'after' => self::TYPE_DATE,
            ],
            default => [],
        };
    }

    public function choices(): array
    {
        return $this->key === 'woo_order_status'
            ? array_combine(self::ORDER_STATUSES, array_map(fn (string $s) => 't:woocommerce.orders.status.' . $s, self::ORDER_STATUSES))
            : [];
    }

    public function scopes(): array
    {
        return [self::SCOPE_ALL => 't:woocommerce.segment.scope_all'] + array_map('strval', ($this->shops)());
    }

    public function toSql(string $operator, mixed $value, SqlParams $params): string
    {
        return $this->toScopedSql($operator, $value, self::SCOPE_ALL, $params);
    }

    public function toScopedSql(string $operator, mixed $value, string $scope, SqlParams $params): string
    {
        switch ($this->key) {
            case 'woo_purchased':
                $exists = 'EXISTS (SELECT 1 ' . $this->orders($scope, $params, true) . ')';

                return $operator === 'yes' ? $exists : 'NOT ' . $exists;
            case 'woo_order_count':
                $sql = '(SELECT COUNT(*) ' . $this->orders($scope, $params, true) . ')';

                return $sql . match ($operator) {
                    'gte' => ' >= ',
                    'lte' => ' <= ',
                    'eq_number' => ' = ',
                    default => throw new \LogicException('Unvalidated operator.'),
                } . $params->bind((int) $value);
            case 'woo_total_spent':
                $sql = '(SELECT COALESCE(SUM(o.total), 0) ' . $this->orders($scope, $params, true) . ')';

                return $sql . ($operator === 'gte' ? ' >= ' : ' <= ') . 'CAST(' . $params->bind((string) $value) . ' AS DECIMAL(38,2))';
            case 'woo_order_status':
                $exists = 'EXISTS (SELECT 1 ' . $this->orders($scope, $params, false) . ' AND o.status = ' . $params->bind((string) $value) . ')';

                return $operator === 'has' ? $exists : 'NOT ' . $exists;
            case 'woo_last_order':
            case 'woo_first_order':
                $date = '(SELECT ' . ($this->key === 'woo_last_order' ? 'MAX' : 'MIN') . '(COALESCE(o.external_created_at, o.created_at)) ' . $this->orders($scope, $params, true) . ')';
                if ($operator === 'before' || $operator === 'after') {
                    // Order times are stored UTC: a calendar date is the organization's local day.
                    [$start, $end] = OrganizationTimezone::shared()->dayRange((string) $value);

                    return $operator === 'before' ? "{$date} < " . $params->bind($start) : "{$date} >= " . $params->bind($end);
                }

                // Relative to the database clock; a campaign snapshot freezes "now" at approval.
                return $date . ($operator === 'last_days' ? ' >= ' : ' < ') . $params->now() . ' - INTERVAL ' . $params->bind((int) $value) . ' DAY';
        }

        throw new \LogicException('Unknown WooCommerce condition.');
    }

    /** FROM … WHERE for the contact's orders, pinned to the organization and the scope. */
    private function orders(string $scope, SqlParams $params, bool $countedOnly): string
    {
        $sql = 'FROM woo_orders o JOIN woo_customers wc ON wc.id = o.customer_id'
            . ' WHERE wc.contact_id = c.id AND wc.organization_id = ' . $params->org() . ' AND o.organization_id = ' . $params->org();
        if ($scope !== self::SCOPE_ALL) {
            $sql .= ' AND o.connection_id = ' . $params->bind((int) $scope);
        }
        if ($countedOnly) {
            $sql .= " AND o.status IN ('" . implode("', '", self::COUNTED_STATUSES) . "')";
        }

        return $sql;
    }
}
