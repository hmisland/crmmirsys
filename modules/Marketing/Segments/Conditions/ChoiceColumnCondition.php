<?php

declare(strict_types=1);

namespace Modules\Marketing\Segments\Conditions;

use Modules\Marketing\Segments\SqlParams;

/** A column with a fixed set of values (status, gender). */
final class ChoiceColumnCondition extends BaseCondition
{
    /**
     * @param array<string, string> $choices value => "t:translation.key"
     */
    public function __construct(string $key, string $labelKey, string $categoryKey, private readonly string $column, private readonly array $choices, private readonly bool $allowEmpty = false)
    {
        if (preg_match('/^[a-z_]+$/', $column) !== 1) {
            throw new \InvalidArgumentException('Invalid column.');
        }
        parent::__construct($key, $labelKey, $categoryKey);
    }

    public function operators(): array
    {
        $ops = ['eq' => self::TYPE_CHOICE, 'neq' => self::TYPE_CHOICE];

        return $this->allowEmpty ? $ops + ['empty' => self::TYPE_NONE] : $ops;
    }

    public function choices(): array
    {
        return $this->choices;
    }

    public function toSql(string $operator, mixed $value, SqlParams $params): string
    {
        $col = 'c.' . $this->column;

        return match ($operator) {
            'eq' => "{$col} = " . $params->bind((string) $value),
            'neq' => "({$col} IS NULL OR {$col} <> " . $params->bind((string) $value) . ')',
            'empty' => "{$col} IS NULL",
            default => throw new \LogicException('Unvalidated operator.'),
        };
    }
}
