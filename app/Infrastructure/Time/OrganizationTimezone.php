<?php

declare(strict_types=1);

namespace App\Infrastructure\Time;

use App\Infrastructure\Repositories\OrganizationContext;

/**
 * The single source of an organization's time zone
 * (`organizations.default_timezone`, an IANA identifier) and of the
 * conversions between stored time and organization-local time.
 *
 * Storage (docs/organization-timezone-design.md):
 *  - system instants (MySQL CURRENT_TIMESTAMP and PHP date()) are stored
 *    in UTC — PHP's global zone is always UTC and every connection uses
 *    the MySQL session zone +00:00 once migration 0083 has run;
 *  - values typed by users (due dates, appointment times, DATE columns)
 *    are organization-local wall-clock values: never pass them through
 *    toLocal()/toStorage(); compare them with localNow() / today().
 *
 * PHP's global time zone is never changed here — every conversion uses an
 * explicit DateTimeZone, so concurrent organizations can't affect each
 * other. The zone follows OrganizationContext (requests and runAs()
 * workers); without an organization (Super Admin, guests) the configured
 * default is used.
 */
final class OrganizationTimezone
{
    public const FALLBACK = 'Asia/Tehran';
    public const STORAGE = 'UTC';
    private const FORMAT = 'Y-m-d H:i:s';

    private static ?self $shared = null;

    /** @var array<int, \DateTimeZone> */
    private array $memo = [];
    private readonly \DateTimeZone $utc;
    private readonly string $default;

    /**
     * @param \Closure(int): ?string $lookup reads organizations.default_timezone
     * @param (\Closure(): \DateTimeImmutable)|null $clock the current instant (tests); default: the real clock
     */
    public function __construct(private readonly \Closure $lookup, ?string $default = null, private readonly ?\Closure $clock = null)
    {
        $this->utc = new \DateTimeZone(self::STORAGE);
        $this->default = $default !== null && self::isValid($default) ? $default : self::FALLBACK;
    }

    /** The instance used by templates and services (set by Application). */
    public static function shared(): self
    {
        return self::$shared ??= new self(static function (int $organizationId): ?string {
            $stmt = \App\Infrastructure\Database\Connection::pdo()->prepare('SELECT default_timezone FROM organizations WHERE id = :id');
            $stmt->execute(['id' => $organizationId]);
            $value = $stmt->fetchColumn();

            return $value === false ? null : (string) $value;
        }, (string) \App\Infrastructure\Config\Config::get('app.organization_timezone', self::FALLBACK));
    }

    public static function setShared(?self $instance): void
    {
        self::$shared = $instance;
    }

    // --- Validation ------------------------------------------------------------

    /** Strict: only canonical IANA identifiers (no offsets like "+03:30", no "GMT+3"). */
    public static function isValid(mixed $identifier): bool
    {
        return is_string($identifier) && $identifier !== '' && in_array($identifier, \DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Dropdown choices grouped by region, each labelled with its current
     * UTC offset: ['Asia' => ['Asia/Tehran' => '(UTC+03:30) Asia/Tehran', …], …].
     *
     * @return array<string, array<string, string>>
     */
    public static function choices(?\DateTimeInterface $at = null): array
    {
        $at ??= new \DateTimeImmutable('now', new \DateTimeZone(self::STORAGE));
        $groups = [];
        foreach (\DateTimeZone::listIdentifiers() as $id) {
            $region = str_contains($id, '/') ? strstr($id, '/', true) : 'UTC';
            $groups[$region][$id] = '(UTC' . self::offset(new \DateTimeZone($id), $at) . ') ' . $id;
        }

        return $groups;
    }

    /** "+03:30" for a zone at a moment (DST-aware). */
    public static function offset(\DateTimeZone $zone, \DateTimeInterface $at): string
    {
        $seconds = $zone->getOffset($at);
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);

        return sprintf('%s%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    // --- Resolution -------------------------------------------------------------

    public function defaultIdentifier(): string
    {
        return $this->default;
    }

    public function forOrganization(int $organizationId): \DateTimeZone
    {
        if (!isset($this->memo[$organizationId])) {
            $id = ($this->lookup)($organizationId);
            $this->memo[$organizationId] = new \DateTimeZone(self::isValid($id) ? (string) $id : $this->default);
        }

        return $this->memo[$organizationId];
    }

    /** After an organization's zone was changed (same request). */
    public function forget(?int $organizationId = null): void
    {
        if ($organizationId === null) {
            $this->memo = [];
        } else {
            unset($this->memo[$organizationId]);
        }
    }

    /** The current organization's zone (OrganizationContext), else the default. */
    public function current(?int $organizationId = null): \DateTimeZone
    {
        $organizationId ??= OrganizationContext::has() ? OrganizationContext::id() : null;

        return $organizationId !== null ? $this->forOrganization($organizationId) : new \DateTimeZone($this->default);
    }

    // --- Clock ------------------------------------------------------------------

    /** Now, in the organization's zone. */
    public function now(?int $organizationId = null): \DateTimeImmutable
    {
        $instant = $this->clock !== null ? ($this->clock)() : new \DateTimeImmutable('now', $this->utc);

        return $instant->setTimezone($this->current($organizationId));
    }

    /** The organization's calendar date today (Y-m-d). */
    public function today(?int $organizationId = null): string
    {
        return $this->now($organizationId)->format('Y-m-d');
    }

    /** Organization-local wall clock now (Y-m-d H:i:s) — to compare with user-entered local values (due dates…). */
    public function localNow(?int $organizationId = null): string
    {
        return $this->now($organizationId)->format(self::FORMAT);
    }

    // --- Conversions --------------------------------------------------------------

    /** A stored UTC instant → the organization's local time; null/empty/invalid → null. */
    public function toLocal(?string $stored, ?int $organizationId = null): ?\DateTimeImmutable
    {
        if ($stored === null || trim($stored) === '' || str_starts_with($stored, '0000-00-00')) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($stored, $this->utc))->setTimezone($this->current($organizationId));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * An organization-local time → the stored UTC value (Y-m-d H:i:s).
     * A string is read as the organization's wall clock; a DateTimeInterface
     * keeps its own zone.
     */
    public function toStorage(\DateTimeInterface|string $local, ?int $organizationId = null): string
    {
        $moment = $local instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($local)
            : new \DateTimeImmutable($local, $this->current($organizationId));

        return $moment->setTimezone($this->utc)->format(self::FORMAT);
    }

    /**
     * One organization calendar day as a stored-UTC half-open range
     * [start, end) for "col >= start AND col < end". DST days are 23 or 25
     * hours long; the range follows the local midnights.
     *
     * @return array{0: string, 1: string}
     */
    public function dayRange(string $ymd, ?int $organizationId = null): array
    {
        $start = $this->localMidnight($ymd, $organizationId);

        return [
            $start->setTimezone($this->utc)->format(self::FORMAT),
            $start->modify('+1 day')->setTimezone($this->utc)->format(self::FORMAT),
        ];
    }

    /**
     * Start of "the last N days" in stored UTC: local midnight N-1 days
     * before today, so N = 1 is today, N = 7 is today and the 6 days before.
     */
    public function daysAgoStart(int $days, ?int $organizationId = null): string
    {
        $today = $this->localMidnight($this->today($organizationId), $organizationId);

        return $today->modify('-' . max(0, $days - 1) . ' days')->setTimezone($this->utc)->format(self::FORMAT);
    }

    private function localMidnight(string $ymd, ?int $organizationId): \DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new \InvalidArgumentException('Expected a Y-m-d date.');
        }

        return (new \DateTimeImmutable($ymd . ' 00:00:00', $this->current($organizationId)));
    }
}
