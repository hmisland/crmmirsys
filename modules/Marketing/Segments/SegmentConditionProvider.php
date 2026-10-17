<?php

declare(strict_types=1);

namespace Modules\Marketing\Segments;

/**
 * A source of segment conditions. Core contact data is one provider
 * (ContactConditions). A future integration (e.g. WooCommerce orders)
 * registers its own provider with SegmentConditionRegistry::addProvider();
 * its conditions then appear in the rule builder and are validated and
 * compiled by the same engine. Providers must keep every predicate
 * pinned to the organization ($params->org()).
 */
interface SegmentConditionProvider
{
    /** @return SegmentCondition[] */
    public function conditions(): array;
}
