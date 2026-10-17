<?php

declare(strict_types=1);

namespace Modules\Marketing\Segments\Conditions;

use App\Infrastructure\Time\OrganizationTimezone;
use Modules\Marketing\Segments\SqlParams;

/**
 * A date/datetime column (contact created, date of birth). "Last N days"
 * is evaluated each time the segment is resolved — a dynamic segment
 * never needs refreshing.
 *
 * $instant: the column is a stored UTC instant (created_at), so a calendar
 * date means the organization's local day (OrganizationTimezone::dayRange());
 * otherwise (date of birth) the stored value already is the local date.
 */
final class DateColumnCondition extends BaseCondition
{
    public function __construct(string $key, string $labelKey, string $categoryKey, private readonly string $column, private readonly bool $allowEmpty = false, private readonly bool $relative = true, private readonly bool $instant = false)
    {
        if (preg_match('/^[a-z_]+$/', $column) !== 1) {
            throw new \InvalidArgumentException('Invalid column.');
        }
        parent::__construct($key, $labelKey, $categoryKey);
    }

    public function operators(): array
    {
        $ops = ['before' => self::TYPE_DATE, 'after' => self::TYPE_DATE, 'on' => self::TYPE_DATE];
        if ($this->relative) {
            $ops += ['last_days' => self::TYPE_NUMBER, 'older_than_days' => self::TYPE_NUMBER];
        }

        return $this->allowEmpty ? $ops + ['empty' => self::TYPE_NONE, 'not_empty' => self::TYPE_NONE] : $ops;
    }

    public function toSql(string $operator, mixed $value, SqlParams $params): string
    {
        $col = 'c.' . $this->column;
        if ($this->instant && in_array($operator, ['before', 'after', 'on'], true)) {
            [$start, $end] = OrganizationTimezone::shared()->dayRange((string) $value);

            return match ($operator) {
                'before' => "{$col} < " . $params->bind($start),
                'after' => "{$col} >= " . $params->bind($end),
                'on' => "({$col} >= " . $params->bind($start) . " AND {$col} < " . $params->bind($end) . ')',
            };
        }

        return match ($operator) {
            'before' => "{$col} < " . $params->bind($value . ' 00:00:00'),
            'after' => "{$col} >= " . $params->bind(date('Y-m-d', (int) strtotime($value . ' +1 day')) . ' 00:00:00'),
            'on' => "({$col} >= " . $params->bind($value . ' 00:00:00') . " AND {$col} < " . $params->bind(date('Y-m-d', (int) strtotime($value . ' +1 day')) . ' 00:00:00') . ')',
            // Relative to the DATABASE clock (the column is stamped by MySQL; PHP's time zone may differ).
            // A campaign snapshot freezes "now" at approval (SqlParams::now()).
            'last_days' => "{$col} >= " . $params->now() . ' - INTERVAL ' . $params->bind((int) $value) . ' DAY',
            'older_than_days' => "{$col} < " . $params->now() . ' - INTERVAL ' . $params->bind((int) $value) . ' DAY',
            'empty' => "{$col} IS NULL",
            'not_empty' => "{$col} IS NOT NULL",
            default => throw new \LogicException('Unvalidated operator.'),
        };
    }
}
