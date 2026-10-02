<?php

declare(strict_types=1);

namespace Modules\Followups;

use App\Infrastructure\Repositories\TenantScopedRepository;

final class FollowUpRuleRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'follow_up_rules';
    }

    public function all(): array
    {
        return $this->tenantFindMany('SELECT * FROM follow_up_rules WHERE 1 = 1 ORDER BY priority DESC, id');
    }

    /**
     * What the rule engine evaluates against a dispatched event —
     * this organization's enabled rules for this event type, in
     * priority order. The EventBus is synchronous and in-process,
     * dispatched within the same request as the acting user who
     * triggered it, so OrganizationContext is already correctly set
     * to the right organization — tenantFindMany() here is both
     * correct and necessary, not optional: without it, one
     * organization's automation rule could fire against another
     * organization's entity.
     */
    public function enabledForEventType(string $eventType): array
    {
        return $this->tenantFindMany(
            "SELECT * FROM follow_up_rules WHERE event_type = :event_type AND enabled = 1 ORDER BY priority DESC, id",
            ['event_type' => $eventType]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM follow_up_rules WHERE id = :id', ['id' => $id]);
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }

    public function updateRule(int $id, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteRule(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
