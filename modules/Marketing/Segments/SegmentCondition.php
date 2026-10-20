<?php

declare(strict_types=1);

namespace Modules\Marketing\Segments;

/**
 * One filter a dynamic segment can use (e.g. "city", "tag", "created").
 * The engine only ever builds SQL through these objects: field names,
 * operators and SQL fragments never come from user input — the user picks
 * a condition KEY and an operator KEY from this allowlist, and values are
 * always bound parameters.
 *
 * A future data provider (e.g. WooCommerce: purchased product, order
 * count, total spend) adds its own SegmentCondition objects through a
 * SegmentConditionProvider; the engine does not change.
 */
interface SegmentCondition
{
    public const TYPE_TEXT = 'text';
    public const TYPE_CHOICE = 'choice';
    public const TYPE_DATE = 'date';
    public const TYPE_NUMBER = 'number';
    /** A money amount (>= 0, up to 2 decimals), e.g. total spent. */
    public const TYPE_AMOUNT = 'amount';
    public const TYPE_NONE = 'none';

    /** Stable key stored in the rules JSON, [a-z][a-z0-9_]*. */
    public function key(): string;

    /** Translation key of the field label. */
    public function labelKey(): string;

    /** Translation key of the group the field is listed under (contact, relations, marketing, …). */
    public function categoryKey(): string;

    /**
     * Allowed operators => value type for that operator (TYPE_*).
     *
     * @return array<string, string>
     */
    public function operators(): array;

    /**
     * For TYPE_CHOICE values: value => label (already translated or a
     * translation key prefixed with "t:"), for the current organization.
     *
     * @return array<string, string>
     */
    public function choices(): array;

    /** True when the field reveals contact details (name, email, phone): editing/previewing it needs contacts.view. */
    public function isPersonalData(): bool;

    /**
     * Validates and normalizes a value for an operator.
     *
     * @return array{0: mixed, 1: ?string} [normalized value, error translation key or null]
     */
    public function normalize(string $operator, mixed $value): array;

    /**
     * SQL predicate on the contacts row aliased "c". Every value goes
     * through $params->bind(); the organization id through $params->org().
     */
    public function toSql(string $operator, mixed $value, SqlParams $params): string;
}
