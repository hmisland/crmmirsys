<?php

declare(strict_types=1);

namespace Modules\Appointments\Services;

/**
 * Turns appointment rows into the day grid (time rows × resource
 * columns) and the week overview. Presentation shaping only; the rows
 * come from AppointmentService::list(), already scope-filtered.
 */
final class CalendarService
{
    public const SLOT_MINUTES = 30;

    public function __construct(private readonly AvailabilityService $availability)
    {
    }

    /**
     * @param array $resources active resources to show as columns
     * @param array $rows      appointments starting on $date
     * @return array{columns: array, rows: array}
     */
    public function dayGrid(string $date, array $resources, array $rows): array
    {
        $columns = [];
        foreach ($resources as $resource) {
            $columns[] = ['id' => (int) $resource['id'], 'name' => (string) $resource['name'], 'kind' => (string) $resource['kind']];
        }

        $hasUnassigned = false;
        foreach ($rows as $row) {
            if ($row['resource_id'] === null) {
                $hasUnassigned = true;
            }
        }
        if ($hasUnassigned || $columns === []) {
            $columns[] = ['id' => null, 'name' => '', 'kind' => ''];
        }

        // Visible range: the resources' opening hours, stretched to
        // include any appointment booked outside them.
        [$open, $close] = $this->availability->dayRange(array_column($resources, 'id'), $date);
        foreach ($rows as $row) {
            $open = min($open, substr((string) $row['starts_at'], 11, 5));
            $endsSameDay = substr((string) $row['ends_at'], 0, 10) === $date;
            $close = max($close, $endsSameDay ? substr((string) $row['ends_at'], 11, 5) : '24:00');
        }

        $step = self::SLOT_MINUTES * 60;
        $dayStart = strtotime($date . ' 00:00:00');
        $first = $dayStart + intdiv($this->minutes($open), self::SLOT_MINUTES) * $step;
        $last = $dayStart + $this->minutes($close) * 60;

        $grid = [];
        for ($slot = $first; $slot < $last; $slot += $step) {
            $time = date('H:i', $slot);
            $cells = [];

            foreach ($columns as $column) {
                $items = [];
                $busy = false;

                foreach ($rows as $row) {
                    if ($row['resource_id'] === null ? $column['id'] !== null : (int) $row['resource_id'] !== $column['id']) {
                        continue;
                    }

                    $start = strtotime((string) $row['starts_at']);
                    $end = strtotime((string) $row['ends_at']);

                    if ($start >= $slot && $start < $slot + $step) {
                        $items[] = $row;
                    } elseif ($start < $slot && $end > $slot && !in_array($row['status'], ['cancelled', 'no_show'], true)) {
                        $busy = true;
                    }
                }

                $isOpen = $column['id'] === null || $this->availability->isSlotOpen($column['id'], $date, $time, self::SLOT_MINUTES);
                $cells[] = ['items' => $items, 'state' => $items !== [] ? 'booked' : ($busy ? 'busy' : ($isOpen ? 'open' : 'closed'))];
            }

            $grid[] = ['time' => $time, 'cells' => $cells];
        }

        return ['columns' => $columns, 'rows' => $grid];
    }

    /**
     * Seven days from $weekStart, each with its appointments in time order.
     *
     * @return list<array{date: string, weekday: int, items: array, is_today: bool}>
     */
    public function week(string $weekStart, array $rows): array
    {
        $days = [];
        $today = \App\Infrastructure\Time\OrganizationTimezone::shared()->today(); // the organization's today, not the server's
        for ($i = 0; $i < 7; $i++) {
            $date = date('Y-m-d', strtotime($weekStart . ' +' . $i . ' days'));
            $days[$date] = ['date' => $date, 'weekday' => (int) date('w', strtotime($date)), 'items' => [], 'is_today' => $date === $today];
        }

        foreach ($rows as $row) {
            $date = substr((string) $row['starts_at'], 0, 10);
            if (isset($days[$date])) {
                $days[$date]['items'][] = $row;
            }
        }

        return array_values($days);
    }

    private function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time) + [0, 0]);

        return $h * 60 + $m;
    }
}
