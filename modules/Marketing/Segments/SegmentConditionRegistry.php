<?php

declare(strict_types=1);

namespace Modules\Marketing\Segments;

/**
 * The allowlist of conditions dynamic segments may use, built from
 * providers. Conditions are created lazily (choices such as groups and
 * tags are read for the current organization when first needed).
 */
final class SegmentConditionRegistry
{
    /** @var SegmentConditionProvider[] */
    private array $providers = [];
    /** @var array<string, SegmentCondition>|null */
    private ?array $conditions = null;

    public function addProvider(SegmentConditionProvider $provider): void
    {
        $this->providers[] = $provider;
        $this->conditions = null;
    }

    public function get(string $key): ?SegmentCondition
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<string, SegmentCondition> first registration of a key wins */
    public function all(): array
    {
        if ($this->conditions === null) {
            $this->conditions = [];
            foreach ($this->providers as $provider) {
                foreach ($provider->conditions() as $condition) {
                    if (preg_match('/^[a-z][a-z0-9_]*$/', $condition->key()) === 1 && !isset($this->conditions[$condition->key()])) {
                        $this->conditions[$condition->key()] = $condition;
                    }
                }
            }
        }

        return $this->conditions;
    }

    /** Forget cached conditions (choices are per organization). */
    public function reset(): void
    {
        $this->conditions = null;
    }
}
