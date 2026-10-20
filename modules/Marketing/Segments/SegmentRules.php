<?php

declare(strict_types=1);

namespace Modules\Marketing\Segments;

/**
 * Dynamic segment rules — a two-level AND/OR tree:
 *
 *   {"match": "all"|"any",
 *    "groups": [{"match": "all"|"any",
 *                "conditions": [{"field": "city", "op": "eq", "value": "Tabriz"}, …]}, …]}
 *
 * A ScopedSegmentCondition also stores "scope" (e.g. one shop or "all").
 *
 * "all" = AND, "any" = OR, at the top level and inside each group (so
 * "(A AND B) OR (C AND D)" and "(A OR B) AND C" are both expressible).
 *
 * Safety: field and operator must be keys of the registry's allowlist;
 * values are normalized by their condition and only ever bound as
 * parameters. Anything else is rejected — nothing from the request is
 * ever placed into SQL text.
 */
final class SegmentRules
{
    public const MAX_GROUPS = 10;
    public const MAX_CONDITIONS_PER_GROUP = 20;
    public const MAX_CONDITIONS = 50;

    public function __construct(private readonly SegmentConditionRegistry $registry)
    {
    }

    /**
     * @return array{rules: ?array, errors: string[], personal: bool}
     *   rules: normalized tree (null when invalid); personal: uses name/email/phone
     */
    public function validate(mixed $input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (!is_array($input) || !isset($input['groups']) || !is_array($input['groups'])) {
            return ['rules' => null, 'errors' => ['marketing.segment.error.rules'], 'personal' => false];
        }

        $errors = [];
        $personal = false;
        $total = 0;
        $groups = [];
        $rawGroups = array_values($input['groups']);
        if ($rawGroups === [] || count($rawGroups) > self::MAX_GROUPS) {
            $errors[] = 'marketing.segment.error.groups_count';
        }

        foreach (array_slice($rawGroups, 0, self::MAX_GROUPS) as $group) {
            $conditions = is_array($group) && isset($group['conditions']) && is_array($group['conditions']) ? array_values($group['conditions']) : [];
            if ($conditions === []) {
                continue; // an empty group means nothing; dropped
            }
            if (count($conditions) > self::MAX_CONDITIONS_PER_GROUP) {
                $errors[] = 'marketing.segment.error.conditions_count';
                continue;
            }

            $normalized = [];
            foreach ($conditions as $condition) {
                $total++;
                $field = is_array($condition) && is_string($condition['field'] ?? null) ? $condition['field'] : '';
                $op = is_array($condition) && is_string($condition['op'] ?? null) ? $condition['op'] : '';
                $definition = $this->registry->get($field);
                if ($definition === null) {
                    $errors[] = 'marketing.segment.error.field';
                    continue;
                }
                if (!array_key_exists($op, $definition->operators())) {
                    $errors[] = 'marketing.segment.error.operator';
                    continue;
                }
                [$value, $error] = $definition->normalize($op, $condition['value'] ?? null);
                if ($error !== null) {
                    $errors[] = $error;
                    continue;
                }
                $entry = ['field' => $field, 'op' => $op, 'value' => $value];
                if ($definition instanceof ScopedSegmentCondition) {
                    $scope = is_scalar($condition['scope'] ?? null) ? (string) $condition['scope'] : ScopedSegmentCondition::SCOPE_ALL;
                    if (!array_key_exists($scope, $definition->scopes())) {
                        $errors[] = 'marketing.segment.error.scope';
                        continue;
                    }
                    $entry['scope'] = $scope;
                }
                $personal = $personal || $definition->isPersonalData();
                $normalized[] = $entry;
            }

            $groups[] = ['match' => self::match($group['match'] ?? 'all'), 'conditions' => $normalized];
        }

        if ($total > self::MAX_CONDITIONS) {
            $errors[] = 'marketing.segment.error.conditions_count';
        }
        if ($groups === [] || array_sum(array_map(fn ($g) => count($g['conditions']), $groups)) === 0) {
            $errors[] = 'marketing.segment.error.no_conditions';
        }

        $errors = array_values(array_unique($errors));

        return [
            'rules' => $errors === [] ? ['match' => self::match($input['match'] ?? 'all'), 'groups' => $groups] : null,
            'errors' => $errors,
            'personal' => $personal,
        ];
    }

    /**
     * SQL predicate (on contacts "c") for VALIDATED rules. Re-checks
     * every field/operator against the registry, so even stored rules
     * that were tampered with in the database cannot inject SQL.
     */
    public function compile(array $rules, SqlParams $params): string
    {
        $mark = $params->mark();
        $sql = $this->compileTree($rules, $params);
        if ($sql === '1 = 0') {
            $params->discardFrom($mark); // nothing of a rejected tree may stay bound
        }

        return $sql;
    }

    private function compileTree(array $rules, SqlParams $params): string
    {
        $groupSql = [];
        foreach ((array) ($rules['groups'] ?? []) as $group) {
            $parts = [];
            foreach ((array) ($group['conditions'] ?? []) as $condition) {
                $definition = $this->registry->get((string) ($condition['field'] ?? ''));
                $op = (string) ($condition['op'] ?? '');
                if ($definition === null || !array_key_exists($op, $definition->operators())) {
                    return '1 = 0'; // never match on an unknown rule
                }
                [$value, $error] = $definition->normalize($op, $condition['value'] ?? null);
                if ($error !== null && ($definition->operators()[$op] !== SegmentCondition::TYPE_CHOICE || !self::isId($condition['value'] ?? null))) {
                    return '1 = 0';
                }
                // A choice that no longer exists (deleted group/tag) still compiles safely: the id is bound and org-pinned.
                $compiledValue = $error === null ? $value : ($condition['value'] ?? '');
                if ($definition instanceof ScopedSegmentCondition) {
                    // A removed scope (e.g. a deleted shop) is still a bound, org-pinned id: it simply matches nothing.
                    $scope = is_scalar($condition['scope'] ?? null) ? (string) $condition['scope'] : ScopedSegmentCondition::SCOPE_ALL;
                    if ($scope !== ScopedSegmentCondition::SCOPE_ALL && !self::isId($scope)) {
                        return '1 = 0';
                    }
                    $parts[] = '(' . $definition->toScopedSql($op, $compiledValue, $scope, $params) . ')';
                    continue;
                }
                $parts[] = '(' . $definition->toSql($op, $compiledValue, $params) . ')';
            }
            if ($parts !== []) {
                $groupSql[] = '(' . implode(self::match($group['match'] ?? 'all') === 'any' ? ' OR ' : ' AND ', $parts) . ')';
            }
        }

        if ($groupSql === []) {
            return '1 = 0';
        }

        return '(' . implode(self::match($rules['match'] ?? 'all') === 'any' ? ' OR ' : ' AND ', $groupSql) . ')';
    }

    public function usesPersonalData(array $rules): bool
    {
        foreach ((array) ($rules['groups'] ?? []) as $group) {
            foreach ((array) ($group['conditions'] ?? []) as $condition) {
                if ($this->registry->get((string) ($condition['field'] ?? ''))?->isPersonalData()) {
                    return true;
                }
            }
        }

        return false;
    }

    /** A record id (a removed group/tag) — the only kind of stale choice value that may still compile. */
    private static function isId(mixed $value): bool
    {
        return (is_int($value) || is_string($value)) && preg_match('/^[1-9]\d{0,9}$/', (string) $value) === 1;
    }

    private static function match(mixed $value): string
    {
        return $value === 'any' ? 'any' : 'all';
    }
}
