<?php

declare(strict_types=1);

namespace Modules\Marketing\Segments\Conditions;

use Modules\Marketing\Segments\SegmentCondition;

/** Shared value validation for the built-in conditions. */
abstract class BaseCondition implements SegmentCondition
{
    public const MAX_TEXT = 200;

    public function __construct(
        protected readonly string $key,
        protected readonly string $labelKey,
        protected readonly string $categoryKey,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function labelKey(): string
    {
        return $this->labelKey;
    }

    public function categoryKey(): string
    {
        return $this->categoryKey;
    }

    public function choices(): array
    {
        return [];
    }

    public function isPersonalData(): bool
    {
        return false;
    }

    public function normalize(string $operator, mixed $value): array
    {
        $type = $this->operators()[$operator] ?? null;
        if ($type === null) {
            return [null, 'marketing.segment.error.operator'];
        }

        return match ($type) {
            self::TYPE_NONE => [null, null],
            self::TYPE_TEXT => $this->text($value),
            self::TYPE_NUMBER => $this->number($value),
            self::TYPE_DATE => $this->date($value),
            self::TYPE_CHOICE => $this->choice($value),
            default => [null, 'marketing.segment.error.value'],
        };
    }

    /** @return array{0: ?string, 1: ?string} */
    protected function text(mixed $value): array
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' || mb_strlen($text) > self::MAX_TEXT ? [null, 'marketing.segment.error.value'] : [$text, null];
    }

    /** @return array{0: ?int, 1: ?string} */
    protected function number(mixed $value): array
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return preg_match('/^\d{1,4}$/', $text) === 1 && (int) $text >= 1 && (int) $text <= 3650
            ? [(int) $text, null]
            : [null, 'marketing.segment.error.value'];
    }

    /** @return array{0: ?string, 1: ?string} Y-m-d */
    protected function date(mixed $value): array
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return [null, 'marketing.segment.error.value'];
        }

        return [$text, null];
    }

    /** @return array{0: ?string, 1: ?string} a key of choices() */
    protected function choice(mixed $value): array
    {
        $text = is_scalar($value) ? (string) $value : '';

        return array_key_exists($text, $this->choices()) ? [$text, null] : [null, 'marketing.segment.error.value'];
    }

    /** LIKE pattern with %, _ and \ escaped (MySQL default escape character). */
    protected static function like(string $text, bool $prefixOnly = false): string
    {
        $escaped = addcslashes($text, '%_\\');

        return $prefixOnly ? $escaped . '%' : '%' . $escaped . '%';
    }
}
