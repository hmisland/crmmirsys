<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use App\Infrastructure\Localization\LocaleFormatter;

/**
 * The task calendar's date logic (T9) — pure functions, no database.
 *
 * Every value is the organization's local wall clock exactly as stored
 * (start_at / due_at, C3): dates are compared and cut as strings, never
 * converted, so the server's or browser's time zone cannot shift a task.
 *
 * Periods follow the user's calendar like the rest of the CRM: the week
 * starts on Saturday in Persian and Arabic, Monday otherwise (as the
 * Appointments calendar); in Persian a month is the Jalali month
 * (found with LocaleFormatter::gregorianToJalali), otherwise the Gregorian one.
 *
 * Placement (T2 semantics):
 *  - a date without a time (*_all_day = 1) is an all-day item;
 *  - a timed start shows at its time, with the due time as the end when the
 *    task ends the same day; start without due → at its start; due without
 *    start → at the due time (or all day for a date-only due);
 *  - a task from one date to another shows on every date in between: timed
 *    on its first day (from its start time) and last day (until its due
 *    time) when those are timed, all-day on the days between.
 */
final class TaskCalendar
{
    public const VIEWS = ['month', 'week', 'day'];

    /** First weekday (0 = Sunday … 6 = Saturday) for a locale. */
    public static function firstWeekday(string $locale): int
    {
        return in_array($locale, ['fa', 'ar'], true) ? 6 : 1;
    }

    /** @return int[] the weekdays in display order */
    public static function weekOrder(string $locale): array
    {
        $first = self::firstWeekday($locale);

        return array_map(fn (int $i) => ($first + $i) % 7, range(0, 6));
    }

    public static function weekStart(string $date, string $locale): string
    {
        $back = ((int) self::day($date)->format('w') - self::firstWeekday($locale) + 7) % 7;

        return self::shift($date, -$back);
    }

    /**
     * The visible period of a view around $date.
     *
     * @return array{from: string, to: string, prev: string, next: string, month_from: ?string, month_to: ?string}
     *   from / to: the first day shown and the day after the last (Y-m-d);
     *   month_from / month_to: the month's own first and last day (month view)
     */
    public static function period(string $view, string $date, string $locale): array
    {
        if ($view === 'day') {
            return ['from' => $date, 'to' => self::shift($date, 1), 'prev' => self::shift($date, -1), 'next' => self::shift($date, 1), 'month_from' => null, 'month_to' => null];
        }
        if ($view === 'week') {
            $from = self::weekStart($date, $locale);

            return ['from' => $from, 'to' => self::shift($from, 7), 'prev' => self::shift($from, -7), 'next' => self::shift($from, 7), 'month_from' => null, 'month_to' => null];
        }

        [$first, $last] = self::monthBounds($date, $locale);
        $from = self::weekStart($first, $locale);
        $to = self::shift(self::weekStart($last, $locale), 7);

        return ['from' => $from, 'to' => $to, 'prev' => self::shift($first, -1), 'next' => self::shift($last, 1), 'month_from' => $first, 'month_to' => $last];
    }

    /** @return array{0: string, 1: string} the first and last day of the month containing $date (Jalali in Persian) */
    public static function monthBounds(string $date, string $locale): array
    {
        if ($locale !== 'fa') {
            $day = self::day($date);

            return [$day->format('Y-m-01'), $day->format('Y-m-t')];
        }
        $month = fn (string $d): int => self::jalali($d)[1];
        $target = $month($date);
        $first = $date;
        while ($month(self::shift($first, -1)) === $target) {
            $first = self::shift($first, -1);
        }
        $last = $date;
        while ($month(self::shift($last, 1)) === $target) {
            $last = self::shift($last, 1);
        }

        return [$first, $last];
    }

    /** The day of the month as the user reads it (Jalali in Persian), in their digits. */
    public static function dayNumber(string $date, LocaleFormatter $fmt): string
    {
        $number = $fmt->locale() === 'fa' ? self::jalali($date)[2] : (int) self::day($date)->format('j');

        return $fmt->digits((string) $number);
    }

    /** @return string[] every date from $from up to (not including) $to */
    public static function days(string $from, string $to): array
    {
        $days = [];
        for ($d = $from; $d < $to; $d = self::shift($d, 1)) {
            $days[] = $d;
        }

        return $days;
    }

    /**
     * Places tasks on the dates of [$from, $to).
     *
     * @return array<string, array{all_day: array, timed: array}> per date; each item:
     *   task, time (start 'HH:MM' or null), end ('HH:MM' or null), continues (bool: spans other days);
     *   timed items have a time and/or an end (the last day of a span: only an end)
     */
    public static function place(array $tasks, string $from, string $to): array
    {
        $calendar = [];
        foreach (self::days($from, $to) as $d) {
            $calendar[$d] = ['all_day' => [], 'timed' => []];
        }

        foreach ($tasks as $task) {
            $start = !empty($task['start_at']) ? (string) $task['start_at'] : null;
            $due = !empty($task['due_at']) ? (string) $task['due_at'] : null;
            if ($start === null && $due === null) {
                continue;
            }
            $startTimed = $start !== null && !self::allDay($task, 'start');
            $dueTimed = $due !== null && !self::allDay($task, 'due');
            $firstDay = substr($start ?? $due, 0, 10);
            $lastDay = substr($due ?? $start, 0, 10);
            if ($lastDay < $firstDay) {
                $lastDay = $firstDay;
            }
            $spans = $firstDay !== $lastDay;

            $dueTime = $dueTimed ? substr((string) $due, 11, 5) : null;
            foreach (self::days(max($firstDay, $from), min(self::shift($lastDay, 1), $to)) as $d) {
                $time = null; // shown start time
                $end = null;  // shown end time
                if ($d === $firstDay && $startTimed) {
                    $time = substr((string) $start, 11, 5);
                    $end = !$spans ? $dueTime : null;
                } elseif ($d === $lastDay && $dueTime !== null) {
                    // Due-only, or a date-only start: at the due time; the last day of a span: until it.
                    if ($spans) {
                        $end = $dueTime;
                    } else {
                        $time = $dueTime;
                    }
                }
                $timed = $time !== null || $end !== null;
                $calendar[$d][$timed ? 'timed' : 'all_day'][] = ['task' => $task, 'time' => $time, 'end' => $end, 'continues' => $spans];
            }
        }

        foreach ($calendar as &$cell) {
            usort($cell['timed'], fn (array $a, array $b) => [$a['time'] ?? '00:00', (int) $a['task']['id']] <=> [$b['time'] ?? '00:00', (int) $b['task']['id']]);
        }
        unset($cell);

        return $calendar;
    }

    public static function validDate(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return $day !== false && $day->format('Y-m-d') === $value ? $value : null;
    }

    private static function allDay(array $task, string $prefix): bool
    {
        $value = (string) $task[$prefix . '_at'];
        $flag = $task[$prefix . '_all_day'] ?? null;

        // As the list shows it: all day when flagged, unless a legacy value carries a time.
        return $flag === null ? substr($value, 11, 5) === '00:00' : ((bool) $flag && substr($value, 11, 5) === '00:00');
    }

    private static function day(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable(substr($date, 0, 10), new \DateTimeZone('UTC'));
    }

    private static function shift(string $date, int $days): string
    {
        return self::day($date)->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    /** @return int[] [year, month, day] in the Jalali calendar */
    private static function jalali(string $date): array
    {
        $day = self::day($date);

        return LocaleFormatter::gregorianToJalali((int) $day->format('Y'), (int) $day->format('n'), (int) $day->format('j'));
    }
}
