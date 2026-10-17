<?php

declare(strict_types=1);

namespace Modules\Marketing\Segments;

/**
 * Collects bound parameters for one compiled segment, with names that can
 * never clash with the caller's own (prefix + counter). Native prepared
 * statements need every placeholder name to be unique.
 */
final class SqlParams
{
    /** @var array<string, mixed> */
    private array $params = [];
    private int $counter = 0;

    /**
     * @param string|null $asOf a frozen "now" (DB clock, Y-m-d H:i:s) for relative dates — set when
     *                         resolving a campaign snapshot, so "last N days" means as of approval
     */
    public function __construct(private readonly int $organizationId, private readonly string $prefix = 'seg', private readonly ?string $asOf = null)
    {
    }

    /** SQL for "now": the database clock, or the bound approval time of a snapshot. */
    public function now(): string
    {
        return $this->asOf !== null && $this->asOf !== '' ? 'CAST(' . $this->bind($this->asOf) . ' AS DATETIME)' : 'NOW()';
    }

    /** Returns a fresh placeholder (":seg3") bound to $value. */
    public function bind(mixed $value): string
    {
        $name = $this->prefix . $this->counter++;
        $this->params[$name] = $value;

        return ':' . $name;
    }

    /** Placeholder bound to the organization id (a new one each time). */
    public function org(): string
    {
        return $this->bind($this->organizationId);
    }

    /** Number of parameters bound so far (see discardFrom()). */
    public function mark(): int
    {
        return count($this->params);
    }

    /**
     * Drops the parameters bound after mark() — when a compiled fragment is
     * thrown away, its placeholders must go too (native prepared statements
     * reject parameters that the SQL doesn't use).
     */
    public function discardFrom(int $mark): void
    {
        $this->params = array_slice($this->params, 0, $mark, true);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->params;
    }
}
