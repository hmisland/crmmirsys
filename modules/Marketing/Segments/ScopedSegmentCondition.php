<?php

declare(strict_types=1);

namespace Modules\Marketing\Segments;

/**
 * Optional companion of SegmentCondition for conditions that can be
 * limited to one part of a data source — e.g. WooCommerce purchases "in
 * all shops" or "in shop X" (a contact can be a customer of several
 * shops). The rule stores the choice as "scope" next to field / op /
 * value; it must be a key of scopes() when saved, and is re-checked and
 * only ever bound as a parameter when compiled. Conditions without it
 * are unchanged.
 */
interface ScopedSegmentCondition extends SegmentCondition
{
    public const SCOPE_ALL = 'all';

    /**
     * Allowed scopes, value => label ("t:" prefix = translation key), for
     * the current organization; always includes SCOPE_ALL.
     *
     * @return array<string, string>
     */
    public function scopes(): array;

    /** Like toSql(), limited to $scope (SCOPE_ALL or a record id already checked to be numeric). */
    public function toScopedSql(string $operator, mixed $value, string $scope, SqlParams $params): string;
}
