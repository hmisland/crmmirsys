# Follow-up Engine — Architecture Amendment (Activities/Sales)

Implemented 2026-10-02 per the client's architecture amendment document.
Analysis was produced and approved before any code was written, per the
client's explicit instruction. The REST API section of the amendment
(§21) was explicitly deferred by the client — see "Deferred" below.

## What this is

A reusable Follow-up domain, independent of Tasks (general work items)
and Activities (logged via Notes/Timeline) — a planned or automated
action arising from an interaction, event, or business rule. Lives in
its own module (`Modules\Followups`), not inside Sales, so it can be
reused by Leads/Deals/Support/Marketing without those modules becoming
a dependency of each other.

## Codebase inspection (amendment §28, done before writing any code)

| Area | Finding |
|---|---|
| Tasks | `Modules\Sales` — `related_type`/`related_id` is the project's real polymorphic-link naming (used instead of the amendment's `related_entity_type`) |
| Activities | Not a formal entity — represented via Notes + Timeline |
| EventBus | Real, fully implemented (`App\Core\Events\EventBus`), injected into many services — **but zero concrete events existed anywhere in the project before this amendment**. `DealStageChanged` is the first real event ever dispatched. |
| Timeline/Audit | `timeline_events` is the project's only audit/activity-feed mechanism — reused directly, no second audit system |
| RBAC | `AuthorizationEngine::can()` already fully implements OWN/TEAM/DEPARTMENT/ORGANIZATION/ALL scope checks, including TEAM/DEPARTMENT against real `users.team_id`/`department_id` columns — no engine changes needed |
| Calendar | Does not exist as a module — out of scope for this amendment regardless |
| Notification channels | Only Bale/SMS gateways exist — no real IN_APP or EMAIL channel. Reminder `channel` ENUM is extensible (`in_app`/`email`/`sms`/`push`) but only `in_app` is active this phase, meaning "appears in the Follow-ups list" — no new notification UI was built |
| REST API | Does not exist for *any* module in this project yet (not even Finance, which was slated to be first). Explicitly deferred by the client for this amendment — see below. |

## What's built

- **Schema**: `follow_ups`, `follow_up_reminders`, `follow_up_recurrences` (data model only — no active recurrence-generation logic), `follow_up_rules`, `follow_up_rule_executions` (the idempotency table, enforced by a real `UNIQUE(rule_id, event_identifier)` constraint, not just an application-level check)
- **Permissions**: `followups.view/create/edit/delete/assign/complete/cancel/export/manage_rules/manage_reminders`, granted with the same OWN/ORGANIZATION split already used for Tasks
- **`FollowUpService`**: CRUD, complete/cancel/reschedule, Timeline integration on every state change
- **Event-driven automation, proven end-to-end** (amendment §24: "prove the architecture supports it, don't hard-code the exact workflow"):
  - `Modules\Sales\Events\DealStageChanged` — dispatched from `DealService::changeStage()`
  - `FollowUpRuleEngineListener` — subscribes via `EventBus::listen()`, matches basic JSON conditions (`stage_id`/`is_won`/`is_lost`), creates a Follow-up through the same idempotency-checked path any other rule would use
  - Adding another event type (`QuoteSent`, `TicketCreated`, etc.) later means one more small match branch in the listener, not restructuring it
- **Rule management UI** at `/followups/rules` — org_admin only, JSON-configured conditions/actions (per the amendment's explicit "do not build a generic workflow engine" boundary)
- **`/followups`** — list (with status/overdue filters), detail, create/edit, complete/cancel/reschedule
- Nav link added, gated on the module being enabled

## Real bugs caught before shipping

1. `FollowUpRuleRepository::enabledForEventType()` — first draft used plain `findMany()` with a comment claiming this was "cross-tenant by design." On reflection this was wrong and would have let one organization's rule fire against another organization's deal. Fixed to `tenantFindMany()`, reasoning corrected in the comment.
2. `FollowUpController::form()` guessed a `UserRepository::allForCurrentOrganization()` method that doesn't exist. Caught by checking the repository's real public methods before shipping; fixed to the actual `findAllInOrganization(int $organizationId)`.
3. `FollowUpRuleEngineListener`'s duplicate-key handling — the first draft's comment said a duplicate-key error means the race was handled correctly (success), but the code underneath recorded it as `'failed'` regardless, contradicting its own comment. Fixed to actually check the PDO error code (`1062`) and branch correctly: a genuine duplicate silently returns (another process already won), anything else is recorded as a real failure under a distinct identifier.

## Deferred (amendment §25 + explicit client instruction)

- **REST API** (`/api/v1/follow-ups`, `/api/v1/follow-up-rules`) — explicitly deferred by the client; nothing in this project has a REST API yet, so this also means defining the project's *first* API authentication convention, which is a bigger decision than this amendment alone
- Advanced recurrence (the table exists; nothing generates a next occurrence yet)
- Escalation (overdue → notify manager, etc.)
- SMS/push reminder channels (ENUM values exist; nothing sends through them)
- Google/Microsoft Calendar integrations
- Advanced workflow branching beyond "Event + basic conditions + Create Follow-up"
- A dedicated "related follow-ups" widget on each entity's own page — Timeline integration already satisfies the amendment's §18 requirement (Follow-ups appear in their related entity's timeline), so this is a nice-to-have, not a gap

## Deployment

1. Replace the zip
2. Run `/system/migrate` (5 new Followups migrations + 1 core grant migration)
3. Enable the Followups module for an organization (`/admin/organizations/{id}/modules`)
4. Try it: create a rule on `/followups/rules` for `DealStageChanged` (e.g. `conditions_json: {"is_won": true}`, `action_configuration_json: {"title": "Follow up on won deal", "due_in_hours": 24}`), then move a deal to a Won stage and confirm a Follow-up appears
