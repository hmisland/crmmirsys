<?php

declare(strict_types=1);

namespace Modules\Marketing\Segments\Conditions;

use Modules\Marketing\Segments\SqlParams;

/**
 * A text column of contacts (name, email, city, …). The column name comes
 * from code (ContactConditions), never from the request.
 */
final class TextColumnCondition extends BaseCondition
{
    public function __construct(string $key, string $labelKey, string $categoryKey, private readonly string $column, private readonly bool $personal = false)
    {
        if (preg_match('/^[a-z_]+$/', $column) !== 1) {
            throw new \InvalidArgumentException('Invalid column.');
        }
        parent::__construct($key, $labelKey, $categoryKey);
    }

    public function operators(): array
    {
        return [
            'contains' => self::TYPE_TEXT,
            'not_contains' => self::TYPE_TEXT,
            'eq' => self::TYPE_TEXT,
            'neq' => self::TYPE_TEXT,
            'starts_with' => self::TYPE_TEXT,
            'empty' => self::TYPE_NONE,
            'not_empty' => self::TYPE_NONE,
        ];
    }

    public function isPersonalData(): bool
    {
        return $this->personal;
    }

    public function toSql(string $operator, mixed $value, SqlParams $params): string
    {
        $col = 'c.' . $this->column;

        return match ($operator) {
            'contains' => "{$col} LIKE " . $params->bind(self::like((string) $value)),
            'not_contains' => "({$col} IS NULL OR {$col} NOT LIKE " . $params->bind(self::like((string) $value)) . ')',
            'starts_with' => "{$col} LIKE " . $params->bind(self::like((string) $value, true)),
            'eq' => "{$col} = " . $params->bind((string) $value),
            'neq' => "({$col} IS NULL OR {$col} <> " . $params->bind((string) $value) . ')',
            'empty' => "({$col} IS NULL OR {$col} = '')",
            'not_empty' => "({$col} IS NOT NULL AND {$col} <> '')",
            default => throw new \LogicException('Unvalidated operator.'),
        };
    }
}
