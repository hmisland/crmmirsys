<?php

declare(strict_types=1);

namespace Modules\Projects;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * project_alerts (module migration 0007) and the overdue items that still
 * need an alert. organizationsWithCandidates() is the only cross-organization
 * read (the scheduler's entry point); everything else is the current
 * organization's, scoped explicitly on joined queries.
 */
final class ProjectAlertRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'project_alerts';
    }

    /**
     * Organizations that may have an unalerted overdue item by $latest (the
     * latest "today" of any time zone) — a cheap pre-filter; each
     * organization is then checked against its own local date.
     *
     * @return int[]
     */
    public function organizationsWithCandidates(string $latest): array
    {
        $rows = $this->findMany(
            "SELECT DISTINCT p.organization_id FROM projects p
             WHERE p.status NOT IN ('completed', 'cancelled')
               AND ((p.expected_end_date < :latest1 AND NOT EXISTS (SELECT 1 FROM project_alerts a WHERE a.project_id = p.id
                        AND a.kind = 'project_overdue' AND a.ref_id = p.id AND a.due_date = p.expected_end_date))
                 OR EXISTS (SELECT 1 FROM project_milestones m WHERE m.project_id = p.id AND m.organization_id = p.organization_id
                        AND m.status = 'planned' AND m.date < :latest2
                        AND NOT EXISTS (SELECT 1 FROM project_alerts a WHERE a.project_id = p.id
                            AND a.kind = 'milestone_overdue' AND a.ref_id = m.id AND a.due_date = m.date)))",
            ['latest1' => $latest, 'latest2' => $latest]
        );

        return array_map(fn (array $r) => (int) $r['organization_id'], $rows);
    }

    /**
     * The current organization's open projects past their expected end date
     * and planned milestones past their date (of open projects) that have
     * no alert yet for that date, oldest first.
     *
     * @return array<int, array{kind: string, project_id: int, ref_id: int, due_date: string, owner_id: int, code: string, project_name: string, name: string}>
     */
    public function pending(string $today, int $limit): array
    {
        $org = OrganizationContext::id();
        $projects = $this->findMany(
            "SELECT 'project_overdue' AS kind, p.id AS project_id, p.id AS ref_id, p.expected_end_date AS due_date, p.owner_id,
                    p.code, p.name AS project_name, p.name AS name
             FROM projects p
             WHERE p.organization_id = :org AND p.status NOT IN ('completed', 'cancelled') AND p.expected_end_date < :today
               AND NOT EXISTS (SELECT 1 FROM project_alerts a WHERE a.project_id = p.id AND a.kind = 'project_overdue'
                   AND a.ref_id = p.id AND a.due_date = p.expected_end_date)
             ORDER BY p.expected_end_date, p.id LIMIT " . max(1, $limit),
            ['org' => $org, 'today' => $today]
        );
        $milestones = $this->findMany(
            "SELECT 'milestone_overdue' AS kind, p.id AS project_id, m.id AS ref_id, m.date AS due_date, p.owner_id,
                    p.code, p.name AS project_name, m.name AS name
             FROM project_milestones m
             JOIN projects p ON p.id = m.project_id AND p.organization_id = m.organization_id
             WHERE m.organization_id = :org AND m.status = 'planned' AND m.date < :today AND p.status NOT IN ('completed', 'cancelled')
               AND NOT EXISTS (SELECT 1 FROM project_alerts a WHERE a.project_id = p.id AND a.kind = 'milestone_overdue'
                   AND a.ref_id = m.id AND a.due_date = m.date)
             ORDER BY m.date, m.id LIMIT " . max(1, $limit),
            ['org' => $org, 'today' => $today]
        );

        return array_map(fn (array $r) => ['project_id' => (int) $r['project_id'], 'ref_id' => (int) $r['ref_id'], 'owner_id' => (int) $r['owner_id']] + $r, array_merge($projects, $milestones));
    }

    /** Claims one alert (INSERT IGNORE on the unique key): true only for the run that inserted it. */
    public function claim(array $item, ?int $userId, string $status): bool
    {
        $stmt = $this->pdo()->prepare(
            'INSERT IGNORE INTO project_alerts (organization_id, project_id, kind, ref_id, due_date, user_id, status)
             VALUES (:org, :project, :kind, :ref, :due, :user, :status)'
        );
        $stmt->execute(['org' => OrganizationContext::id(), 'project' => $item['project_id'], 'kind' => $item['kind'],
            'ref' => $item['ref_id'], 'due' => $item['due_date'], 'user' => $userId, 'status' => $status]);

        return $stmt->rowCount() === 1;
    }

    public function markFailed(array $item): void
    {
        $this->tenantUpdate(['status' => 'failed'], 'project_id = :project AND kind = :kind AND ref_id = :ref AND due_date = :due',
            ['project' => $item['project_id'], 'kind' => $item['kind'], 'ref' => $item['ref_id'], 'due' => $item['due_date']]);
    }
}
