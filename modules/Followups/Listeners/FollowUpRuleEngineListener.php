<?php

declare(strict_types=1);

namespace Modules\Followups\Listeners;

use App\Core\Events\EventInterface;
use App\Core\Events\ListenerInterface;
use App\Infrastructure\Repositories\UserRepository;
use Modules\Followups\FollowUpReminderRepository;
use Modules\Followups\FollowUpRuleExecutionRepository;
use Modules\Followups\FollowUpRuleRepository;
use Modules\Followups\Services\FollowUpService;
use Modules\Sales\Events\DealStageChanged;

/**
 * Follow-up Engine must NOT directly query another module's tables
 * to detect events — this listener only ever receives already-built
 * Event objects from the EventBus and reads this module's own rule
 * tables. It never reaches into Sales/Finance/Support internals.
 *
 * One handle() per concrete event type this phase (DealStageChanged
 * is the first and only one wired end-to-end, proving the pattern
 * per the amendment's §24 — adding QuoteSent/TicketCreated/etc. later
 * means adding another small match branch here, not restructuring
 * this class).
 */
final class FollowUpRuleEngineListener implements ListenerInterface
{
    public function __construct(
        private readonly FollowUpRuleRepository $rules,
        private readonly FollowUpRuleExecutionRepository $executions,
        private readonly FollowUpReminderRepository $reminders,
        private readonly FollowUpService $followUps,
        private readonly UserRepository $users = new UserRepository(),
    ) {
    }

    public function handle(EventInterface $event): void
    {
        if ($event instanceof DealStageChanged) {
            $this->handleDealStageChanged($event);
        }
    }

    private function handleDealStageChanged(DealStageChanged $event): void
    {
        $eventIdentifier = "deal:{$event->dealId}:stage:{$event->stageId}";

        $rules = $this->rules->enabledForEventType('DealStageChanged');

        foreach ($rules as $rule) {
            if ($this->executions->alreadyExecuted((int) $rule['id'], $eventIdentifier)) {
                continue;
            }

            $conditions = json_decode((string) ($rule['conditions_json'] ?? ''), true) ?: [];

            if (!$this->dealStageConditionsMatch($conditions, $event)) {
                continue;
            }

            $this->applyRule($rule, $eventIdentifier, $event);
        }
    }

    /**
     * Conditions JSON may optionally include "stage_id" (int),
     * "is_won" (bool), "is_lost" (bool) — any key present must match
     * the event; an absent key places no constraint. Deliberately
     * this simple per the amendment's "basic conditions" MVP scope,
     * not a generic expression evaluator.
     */
    private function dealStageConditionsMatch(array $conditions, DealStageChanged $event): bool
    {
        if (isset($conditions['stage_id']) && (int) $conditions['stage_id'] !== $event->stageId) {
            return false;
        }

        if (isset($conditions['is_won']) && (bool) $conditions['is_won'] !== $event->isWon) {
            return false;
        }

        if (isset($conditions['is_lost']) && (bool) $conditions['is_lost'] !== $event->isLost) {
            return false;
        }

        return true;
    }

    private function applyRule(array $rule, string $eventIdentifier, DealStageChanged $event): void
    {
        $action = json_decode((string) $rule['action_configuration_json'], true) ?: [];

        $dueAt = isset($action['due_in_hours'])
            ? date('Y-m-d H:i:s', strtotime('+' . (int) $action['due_in_hours'] . ' hours'))
            : null;

        $ownerId = match ($action['assign_to'] ?? 'deal_owner') {
            'specific_user' => isset($action['assign_to_user_id']) ? (int) $action['assign_to_user_id'] : $event->actorUserId,
            default => $event->actorUserId,
        };

        // D1 (docs/hardening-patch-design.md): a rule's fixed assignee must be an
        // active user of the EVENT's organization; anything else (another
        // organization's user, unknown, deactivated) falls back to the actor —
        // the same default as "deal_owner". Rule data itself is not changed.
        if ($ownerId !== null && $ownerId !== $event->actorUserId) {
            $user = $this->users->findById($ownerId);
            if ($user === null || (int) $user['organization_id'] !== $event->organizationId || ($user['status'] ?? '') !== 'active') {
                $ownerId = $event->actorUserId;
            }
        }

        if ($ownerId === null) {
            // No sensible owner to assign — skip rather than violate
            // the NOT NULL owner_id constraint with a guess. Not a
            // duplicate, so recording this is safe and desired (stops
            // the rule from being retried forever for this exact
            // transition).
            $this->tryRecordExecution($rule['id'], $eventIdentifier, $event->dealId, 'skipped', null);

            return;
        }

        try {
            $followUpId = $this->followUps->createAutomated([
                'organization_id' => $event->organizationId,
                'title' => (string) ($action['title'] ?? $rule['name']),
                'description' => $action['description'] ?? null,
                'type' => in_array($action['type'] ?? null, ['call', 'email', 'message', 'meeting', 'task', 'reminder', 'note', 'custom'], true) ? $action['type'] : 'task',
                'priority' => in_array($action['priority'] ?? null, ['low', 'normal', 'high', 'urgent'], true) ? $action['priority'] : 'normal',
                'owner_id' => $ownerId,
                'assignee_user_id' => $ownerId,
                'due_at' => $dueAt,
                'related_type' => 'deal',
                'related_id' => $event->dealId,
                'source_type' => 'rule',
                'source_id' => $rule['id'],
            ]);

            if ($dueAt !== null && !empty($action['reminder_minutes_before'])) {
                $this->reminders->create([
                    'follow_up_id' => $followUpId,
                    'channel' => 'in_app',
                    'offset_minutes' => (int) $action['reminder_minutes_before'],
                    'scheduled_at' => date('Y-m-d H:i:s', strtotime($dueAt) - ((int) $action['reminder_minutes_before'] * 60)),
                ]);
            }

            $this->tryRecordExecution($rule['id'], $eventIdentifier, $event->dealId, 'created', $followUpId);
        } catch (\Throwable $e) {
            $isDuplicateKey = $e instanceof \PDOException && ($e->errorInfo[1] ?? null) === 1062;

            if ($isDuplicateKey) {
                // Another process already recorded this exact
                // (rule_id, event_identifier) between our
                // alreadyExecuted() check and this insert — that
                // process's write is the real outcome; nothing more
                // to do here, this is the race being handled
                // correctly, not a failure.
                return;
            }

            // A genuine failure (e.g. the Follow-up insert itself
            // failed). Record it under a distinct identifier so it
            // doesn't collide with the original — a future retry of
            // the real event can still succeed under the original
            // event_identifier.
            $this->tryRecordExecution($rule['id'], $eventIdentifier . ':failed:' . uniqid(), $event->dealId, 'failed', null);
        }
    }

    private function tryRecordExecution(int $ruleId, string $eventIdentifier, int $dealId, string $status, ?int $createdFollowUpId): void
    {
        try {
            $this->executions->record([
                'rule_id' => $ruleId,
                'event_identifier' => $eventIdentifier,
                'related_type' => 'deal',
                'related_id' => $dealId,
                'status' => $status,
                'created_follow_up_id' => $createdFollowUpId,
            ]);
        } catch (\Throwable $e) {
            // Even the bookkeeping insert can lose a race under
            // concurrent duplicate processing — that is still a
            // successful idempotency outcome (someone recorded it),
            // not an error worth propagating.
        }
    }
}
