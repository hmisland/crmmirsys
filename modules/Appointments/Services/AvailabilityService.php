<?php

declare(strict_types=1);

namespace Modules\Appointments\Services;

use Modules\Appointments\AppointmentRepository;
use Modules\Appointments\WorkingHoursRepository;

/**
 * Working hours and free time, kept apart from booking itself so a
 * future public booking page or customer portal can offer slots with
 * exactly the same rules staff bookings are checked against.
 *
 * Times are "HH:MM" strings and dates "Y-m-d" in the organization's local
 * wall clock (appointment times are never converted). PHP arithmetic on them
 * runs in UTC, so "now" must be the organization's local now in the same frame.
 */
final class AvailabilityService
{
    public const DEFAULT_DAY_START = '08:00';
    public const DEFAULT_DAY_END = '20:00';

    /** @var array<int, array<int, list<array{0: string, 1: string}>>>|null */
    private ?array $hoursCache = null;

    public function __construct(
        private readonly WorkingHoursRepository $hours,
        private readonly AppointmentRepository $appointments,
    ) {
    }

    /**
     * resource id => weekday (0 = Sunday) => list of [start, end] ("HH:MM").
     * A resource missing from the map has no hours defined.
     *
     * @return array<int, array<int, list<array{0: string, 1: string}>>>
     */
    public function hoursByResource(): array
    {
        if ($this->hoursCache === null) {
            $this->hoursCache = [];
            foreach ($this->hours->all() as $row) {
                $this->hoursCache[(int) $row['resource_id']][(int) $row['weekday']][] = [
                    substr((string) $row['start_time'], 0, 5),
                    substr((string) $row['end_time'], 0, 5),
                ];
            }
        }

        return $this->hoursCache;
    }

    /** @return array<int, list<array{0: string, 1: string}>> weekday => ranges, empty when none are defined */
    public function hoursFor(int $resourceId): array
    {
        return $this->hoursByResource()[$resourceId] ?? [];
    }

    public function forgetCache(): void
    {
        $this->hoursCache = null;
    }

    /**
     * Whether [$start, $end) fits inside one working range of the
     * resource on that day. Always true for a resource with no hours.
     */
    public function isWithinHours(int $resourceId, string $start, string $end): bool
    {
        $week = $this->hoursFor($resourceId);

        if ($week === []) {
            return true;
        }

        if (substr($start, 0, 10) !== substr($end, 0, 10) && substr($end, 11, 5) !== '00:00') {
            return false; // spans midnight: never inside one day's range
        }

        $weekday = (int) date('w', strtotime($start));
        $from = substr($start, 11, 5);
        $to = substr($end, 11, 5) === '00:00' && substr($start, 0, 10) !== substr($end, 0, 10) ? '24:00' : substr($end, 11, 5);

        foreach ($week[$weekday] ?? [] as [$open, $close]) {
            if ($from >= $open && $to <= $close) {
                return true;
            }
        }

        return false;
    }

    /** Is the resource open at all on this date (true when it has no hours defined). */
    public function isOpenOn(int $resourceId, string $date): bool
    {
        $week = $this->hoursFor($resourceId);

        return $week === [] || !empty($week[(int) date('w', strtotime($date))]);
    }

    /** Whether the slot [$time, $time + $minutes) on $date is inside the resource's hours. */
    public function isSlotOpen(int $resourceId, string $date, string $time, int $minutes): bool
    {
        $start = $date . ' ' . $time . ':00';

        return $this->isWithinHours($resourceId, $start, date('Y-m-d H:i:s', strtotime($start) + $minutes * 60));
    }

    /**
     * Earliest opening and latest closing across the given resources on
     * $date — the visible range of a day grid. Falls back to a default
     * working day when none of them define hours for that day.
     *
     * @param int[] $resourceIds
     * @return array{0: string, 1: string}
     */
    public function dayRange(array $resourceIds, string $date): array
    {
        $weekday = (int) date('w', strtotime($date));
        $open = null;
        $close = null;

        foreach ($resourceIds as $id) {
            foreach ($this->hoursFor((int) $id)[$weekday] ?? [] as [$from, $to]) {
                $open = $open === null || $from < $open ? $from : $open;
                $close = $close === null || $to > $close ? $to : $close;
            }
        }

        return [$open ?? self::DEFAULT_DAY_START, $close ?? self::DEFAULT_DAY_END];
    }

    /**
     * Start times on $date when the resource is open for $minutes (plus
     * $bufferMinutes) and still below its capacity. Used by nothing in
     * the staff UI yet beyond the day grid; it is the building block a
     * self-service booking page needs.
     *
     * @return string[] "HH:MM" start times
     */
    public function freeSlots(array $resource, string $date, int $minutes, int $bufferMinutes = 0, int $stepMinutes = 15): array
    {
        $resourceId = (int) $resource['id'];

        if (!$this->isOpenOn($resourceId, $date)) {
            return [];
        }

        [$open, $close] = $this->dayRange([$resourceId], $date);
        $cursor = strtotime($date . ' ' . $open . ':00');
        $last = strtotime($date . ' ' . $close . ':00') - $minutes * 60;
        $capacity = max(1, (int) $resource['capacity']);
        $now = strtotime(\App\Infrastructure\Time\OrganizationTimezone::shared()->localNow()); // same frame as $cursor (local wall clock read as UTC)
        $slots = [];

        for (; $cursor <= $last; $cursor += $stepMinutes * 60) {
            if ($cursor < $now) {
                continue;
            }

            $time = date('H:i', $cursor);
            if (!$this->isSlotOpen($resourceId, $date, $time, $minutes)) {
                continue;
            }

            $taken = $this->appointments->countOverlappingOnResource(
                $resourceId,
                date('Y-m-d H:i:s', $cursor),
                date('Y-m-d H:i:s', $cursor + ($minutes + $bufferMinutes) * 60),
                null
            );

            if ($taken < $capacity) {
                $slots[] = $time;
            }
        }

        return $slots;
    }

    /**
     * Weekdays in display order for a locale: Saturday-first for fa/ar,
     * Monday-first otherwise. Values are date('w') numbers.
     *
     * @return int[]
     */
    public static function weekOrder(string $locale): array
    {
        return in_array($locale, ['fa', 'ar'], true) ? [6, 0, 1, 2, 3, 4, 5] : [1, 2, 3, 4, 5, 6, 0];
    }

    /** The first day of the week containing $date, for the given locale. */
    public static function weekStart(string $date, string $locale): string
    {
        $first = self::weekOrder($locale)[0];
        $weekday = (int) date('w', strtotime($date));
        $back = ($weekday - $first + 7) % 7;

        return date('Y-m-d', strtotime($date . ' -' . $back . ' days'));
    }
}
