<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Time\OrganizationTimezone;
use Modules\Sales\TaskRecurrenceRepository;
use Modules\Sales\TaskRepository;

/**
 * Recurring tasks (T7): generating a series' next occurrence (the cron
 * processor, modules/Sales/bin/task-reminders.php).
 *
 * When a series head's own date (start, else due — the organization's
 * local wall clock) has come by that organization's clock, its next
 * occurrence is created: origin + n × interval days / weeks / months
 * (months keep the origin's day, clamped to the month's last day). Missed
 * occurrences are skipped, never created in bulk: the next one is the
 * first on or after today. Past recur_until the series stops instead.
 *
 * The new occurrence copies title, description, priority, labels, the
 * checklist (all items open), the schedule (shifted, all-day flags kept)
 * and the reminder as a fresh one (its own occurrence, not fired, same
 * email setting). Assignees are kept while they are active members of the
 * organization; the related record only while the series' creator may
 * still see it (otherwise the new task has none — never a stale or
 * inaccessible link). Archived, deleted or inactive heads generate
 * nothing; UNIQUE(recur_parent_id) plus a row lock make a repeat run a
 * no-op.
 */
final class TaskRecurrenceService
{
    private const MAX_STEPS = 10000;

    public function __construct(
        private readonly TaskRecurrenceRepository $series,
        private readonly TaskRepository $tasks,
        private readonly TaskService $taskService,
        private readonly UserRepository $users,
        private readonly OrganizationTimezone $clock,
    ) {
    }

    /**
     * Every organization with an active series, each in its own context and
     * clock. One organization's failure does not stop the others.
     *
     * @return array{organizations: int, created: int, ended: int, errors: string[]}
     */
    public function runAll(int $perOrganization = 200): array
    {
        $stats = ['organizations' => 0, 'created' => 0, 'ended' => 0, 'errors' => []];

        foreach ($this->series->organizationsWithDueSeries() as $organizationId) {
            $stats['organizations']++;
            try {
                $result = OrganizationContext::runAs($organizationId, fn () => $this->processCurrentOrganization($perOrganization));
                $stats['created'] += $result['created'];
                $stats['ended'] += $result['ended'];
            } catch (\Throwable $e) {
                $stats['errors'][] = 'organization ' . $organizationId . ': ' . $e->getMessage();
            }
        }

        return $stats;
    }

    /** @return array{created: int, ended: int} */
    public function processCurrentOrganization(int $limit = 200): array
    {
        $organizationId = OrganizationContext::id();
        $now = $this->clock->localNow($organizationId);
        $today = substr($now, 0, 10);
        $result = ['created' => 0, 'ended' => 0];

        foreach ($this->series->dueSeries($now, $limit) as $row) {
            $outcome = $this->series->atomically(function () use ($row, $today, $organizationId): ?string {
                $head = $this->series->lockHead((int) $row['id']);
                if ($head === null) {
                    return null; // handled by another run, or changed meanwhile
                }
                $fields = $this->nextOccurrence($head, $today, $organizationId);
                if ($fields === null) {
                    $this->series->endSeries((int) $head['id']);

                    return 'ended';
                }
                $id = $this->series->insertOccurrence($fields);
                if ($id === null) {
                    return null;
                }
                $this->series->copyChecklist((int) $head['id'], $id);
                $this->series->copyLabels((int) $head['id'], $id);
                $this->tasks->setAssignees($id, $this->assignees($head, $organizationId));

                return 'created';
            });

            if ($outcome !== null) {
                $result[$outcome]++;
            }
        }

        return $result;
    }

    /**
     * The local wall-clock anchor of step $index of a series ("Y-m-d H:i:s").
     * Monthly keeps the origin's day, clamped to the month's length.
     */
    public static function anchorAt(string $origin, string $type, int $interval, int $index): string
    {
        $base = new \DateTimeImmutable($origin, new \DateTimeZone('UTC'));
        if ($type === 'monthly') {
            $months = (int) $base->format('Y') * 12 + (int) $base->format('n') - 1 + $interval * $index;
            $year = intdiv($months, 12);
            $month = $months % 12 + 1;
            $first = new \DateTimeImmutable(sprintf('%04d-%02d-01 %s', $year, $month, $base->format('H:i:s')), new \DateTimeZone('UTC'));

            return $first->setDate($year, $month, min((int) $base->format('j'), (int) $first->format('t')))->format('Y-m-d H:i:s');
        }
        $days = $interval * $index * ($type === 'weekly' ? 7 : 1);

        return $base->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
    }

    /** The columns of the next occurrence, or null when the series has ended. */
    private function nextOccurrence(array $head, string $today, int $organizationId): ?array
    {
        $current = (string) ($head['start_at'] ?? $head['due_at']);
        $origin = (string) $head['recur_anchor'];
        $type = (string) $head['recur_type'];
        $interval = max(1, (int) $head['recur_interval']);

        $index = (int) $head['recur_index'];
        $next = null;
        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            $index++;
            $candidate = self::anchorAt($origin, $type, $interval, $index);
            if ($candidate > $current && substr($candidate, 0, 10) >= $today) {
                $next = $candidate;
                break;
            }
        }
        if ($next === null || ($head['recur_until'] !== null && substr($next, 0, 10) > (string) $head['recur_until'])) {
            return null;
        }

        // Every date moves with the anchor, keeping its distance from it.
        $utc = new \DateTimeZone('UTC');
        $offset = fn (?string $value): ?string => $value === null ? null : (new \DateTimeImmutable($next, $utc))
            ->modify(sprintf('%+d seconds', (new \DateTimeImmutable($value, $utc))->getTimestamp() - (new \DateTimeImmutable($current, $utc))->getTimestamp()))
            ->format('Y-m-d H:i:s');

        [$relatedType, $relatedId] = $this->related($head, $organizationId);
        $assignees = $this->assignees($head, $organizationId);
        $remindAt = $offset($head['remind_at']);

        return [
            'title' => $head['title'],
            'description' => $head['description'],
            'priority' => $head['priority'],
            'status' => 'pending',
            'status_id' => $this->taskService->defaultStatusId('open'), // the organization's first open status (T8)
            'assigned_to_user_id' => $assignees[0] ?? null,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'created_by_user_id' => $head['created_by_user_id'],
            'start_at' => $offset($head['start_at']),
            'start_all_day' => (int) $head['start_all_day'],
            'due_at' => $offset($head['due_at']),
            'due_all_day' => (int) $head['due_all_day'],
            'remind_at' => $remindAt,
            'remind_all_day' => (int) $head['remind_all_day'],
            'remind_seq' => $remindAt !== null ? 1 : 0,
            'remind_fired_seq' => 0,
            'remind_email' => $remindAt !== null ? (int) $head['remind_email'] : 0,
            'recur_type' => $type,
            'recur_interval' => $interval,
            'recur_until' => $head['recur_until'],
            'recur_active' => 1,
            'recur_anchor' => $origin,
            'recur_index' => $index,
            'series_id' => $head['series_id'] ?? $head['id'],
            'recur_parent_id' => (int) $head['id'],
        ];
    }

    /** The series' creator, while still an active member of this organization. */
    private function creator(array $head, int $organizationId): ?array
    {
        $user = !empty($head['created_by_user_id']) ? $this->users->findById((int) $head['created_by_user_id']) : null;

        return $user !== null && (int) ($user['organization_id'] ?? 0) === $organizationId && ($user['status'] ?? '') === 'active' ? $user : null;
    }

    /** @return array{0: ?string, 1: ?int} the related record, only while the creator may still see it */
    private function related(array $head, int $organizationId): array
    {
        if (empty($head['related_type']) || empty($head['related_id'])) {
            return [null, null];
        }
        $creator = $this->creator($head, $organizationId);
        $state = $creator !== null ? ($this->taskService->relatedFor($creator, [$head])[(int) $head['id']]['state'] ?? null) : null;

        return $state === 'ok' ? [(string) $head['related_type'], (int) $head['related_id']] : [null, null];
    }

    /** @return int[] the head's assignees who are active members of this organization (else the creator, else none) */
    private function assignees(array $head, int $organizationId): array
    {
        $ids = $this->tasks->assigneeIds((int) $head['id']);
        if (!empty($head['assigned_to_user_id'])) {
            array_unshift($ids, (int) $head['assigned_to_user_id']);
        }
        $kept = [];
        foreach (array_unique(array_map('intval', $ids)) as $userId) {
            $user = $this->users->findById($userId);
            if ($user !== null && (int) ($user['organization_id'] ?? 0) === $organizationId && ($user['status'] ?? '') === 'active') {
                $kept[] = $userId;
            }
        }
        if ($kept === [] && ($creator = $this->creator($head, $organizationId)) !== null) {
            $kept[] = (int) $creator['id'];
        }

        return $kept;
    }
}
