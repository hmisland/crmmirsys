<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use App\Infrastructure\Time\OrganizationTimezone;
use Modules\Projects\ProjectRepository;

/**
 * The Projects dashboard (Phase 3): managerial figures and deadline / delay
 * monitoring over the projects this user may see — exactly the list's rule
 * (ProjectService::visibleFilters()). Every figure is a SQL aggregate; only
 * short, limited lists come back as rows. Dates are the organization's
 * local calendar (OrganizationTimezone).
 *
 * Semantics: a completed or cancelled project is never overdue or "ending
 * soon", and its milestones are never late. A milestone is late while it is
 * still "planned" after its date. Task figures are not here — Projects knows
 * nothing about tasks; the Task Manager contributes them through the page's
 * panel slots when it is available.
 */
final class ProjectDashboardService
{
    /** Days ahead that count as "upcoming" / "ending soon". */
    public const HORIZON_DAYS = 14;
    public const LIST_LIMIT = 20;
    public const MILESTONE_LIMIT = 10;

    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectRepository $repository,
    ) {
    }

    /**
     * @return array{today: string, horizon: string, counts: array, milestones: array, upcoming: array, attention: array{rows: array, total: int}, open_projects: array<int, string>}
     */
    public function overview(array $actingUser, ?string $today = null): array
    {
        $filters = $this->projects->visibleFilters($actingUser);
        $today ??= OrganizationTimezone::shared()->today();
        $horizon = (new \DateTimeImmutable($today))->modify('+' . self::HORIZON_DAYS . ' days')->format('Y-m-d');

        $counts = $this->repository->dashboardCounts($filters, $today, $horizon);
        if ($counts['total'] === 0) {
            // Nothing visible: no figures at all (the page shows an empty state, not zeros).
            return ['today' => $today, 'horizon' => $horizon, 'counts' => $counts, 'milestones' => ['overdue' => 0, 'upcoming' => 0],
                'upcoming' => [], 'attention' => ['rows' => [], 'total' => 0], 'open_projects' => []];
        }

        return [
            'today' => $today,
            'horizon' => $horizon,
            'counts' => $counts,
            'milestones' => $this->repository->milestoneCounts($filters, $today, $horizon),
            'upcoming' => $this->repository->upcomingMilestones($filters, $today, $horizon, self::MILESTONE_LIMIT),
            'attention' => $this->repository->attention($filters, $today, $horizon, self::LIST_LIMIT),
            'open_projects' => $this->repository->openLabels($filters),
        ];
    }

    /** Whole days between two Y-m-d dates (positive when $to is later). */
    public static function daysBetween(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%r%a');
    }
}
