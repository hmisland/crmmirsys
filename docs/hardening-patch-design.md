# Hardening patch (Follow-ups, Task/Ticket attachments) — audit and design

Status: **approved (D1 yes, D2 view level, D3 yes) and implemented (2026-10-09).**
Baseline 366 → **396 passed, 0 failed, 0 skipped** (new suite `HardeningPatchTest`, 20 tests).
Every security test failed on the unpatched files and passes after the patch; 10 mutation checks
are all caught.

Implementation notes (inside the approved scope):
- **Actual message counts:** 11 flash literals (the audit said 12) and **7** service validation
  messages (the audit said 5). All of them were translated, per D3's intent. Together with H1's 3
  error keys that makes 21 keys × 5 languages.
- **H1 assignee picker:** the follow-up form's assignee select now lists active users plus the saved
  assignee. This is in `FollowUpController::form()`, an approved file. Without it, a user could pick
  an inactive user and get a 422 with no visible field error. No template change.
- **H4 test limit:** CLI can't run `move_uploaded_file()`. The tests prove the refusal happens
  *before* `upload()` (no row, no timeline, exception before the service), and that an allowed
  request reaches the upload step.
- **Language files:** they had 45 more keys at the start of this patch than after Contract↔Finance.
  Those were added outside this work and were left untouched; this patch added exactly 21.

Scope: the five low-severity items listed after the Contract ↔ Finance step. The changes are
limited to the **Followups**, **Sales** (tasks) and **Support** (tickets) modules plus translation
files. No Core, no schema, no permission keys, no URLs.

---

## A. Audit

### H1 — Follow-up `assignee_user_id`, `team_id`, `department_id` not checked against the organization (G4)

**Today:**
- `FollowUpService::validatedFields()` casts the three ids to int and stores them. Only the
  foreign keys hold, and they accept **any** existing user, team or department, including
  another organization's.
- The form offers an assignee select (users of the organization). `team_id` and `department_id`
  have no field at all, so they arrive only through a tampered POST.
- The same `validatedFields()` serves create and update.
- `createAutomated()` (rule engine) takes `owner_id` / `assignee_user_id` from the rule's action JSON
  (`assign_to_user_id`). That is written by org admins (`followups.manage_rules`) and is not
  validated either.
- ClientRecords already does this correctly: `ClientRecordController` validates the assignee
  through `validatedUserId()` before calling the service.

**Risk:**
- **Cross-tenant name leak.** `FollowUpRepository::search()` shows the assignee through an
  **unscoped** subquery: `(SELECT CONCAT(first_name, …) FROM users WHERE users.id = f.assignee_user_id)`.
  A tampered `assignee_user_id` of another organization therefore puts that person's name into
  your follow-up list and Follow-ups panels. This is the same pattern as Finance F1. **Severity: medium.**
- **Integrity:** `team_id` / `department_id` drive RBAC team/department scope. A foreign id can
  never equal a user's own team (ids are global), so there is **no privilege escalation**, only
  bad data.

**Minimal fix:**
- In `FollowUpService::validatedFields()`, check the three ids through **tenant-scoped** lookups:
  - `UserRepository::findById()` + `organization_id` = the caller's organization + `status = 'active'`
    for the assignee (the same rule Projects and ClientRecords use);
  - `TeamRepository` / `DepartmentRepository::findById()` (already `TenantScopedRepository`).
- A failing id gives `ValidationException`, field-keyed, so it surfaces as a form error and 422.
  Empty stays allowed (all three are nullable).
- **Edit path:** an assignee who is **already saved** and later deactivated may be kept unchanged,
  so editing an old follow-up doesn't fail. A *new* assignee value must be active.
- **Rule engine (decision D1):** validate `assign_to_user_id` the same way when it is used. If the user
  isn't an active user of the event's organization, fall back to the actor (the existing
  `deal_owner` default). This changes no rule data.

### H2 — Follow-up `type` / `priority` missing → null → database error

**Today:**
```php
$type     = in_array($input['type'] ?? 'task', self::TYPES, true) ? $input['type'] : 'task';
$priority = in_array($input['priority'] ?? 'normal', self::PRIORITIES, true) ? $input['priority'] : 'normal';
```
When the key is **absent**, `in_array('task', …)` is true, so the code returns `$input['type']`, which
is undefined (null), with a PHP warning. The insert then fails on `NOT NULL` (seen while building
`FollowUpRelatedRecordsTest`). An empty string `''` is handled correctly ('task'); only a missing key breaks.

**Risk:** a 500 for any caller that omits the field: an API or script caller, or a future form. The
real form always sends both, so users don't hit it today. **Severity: low (robustness).**

**Minimal fix:**
- **Create:** a missing, empty or invalid value → `'task'` / `'normal'`. These are the same defaults
  as today's intent and as the column defaults in migration 0001.
- **Update:** a missing value → **keep the follow-up's current value**, so an edit never silently
  resets the type or priority. An invalid value → current value as well.
- No change for valid input.

### H3 — Task and Ticket attachment download doesn't check access to the parent

**Today:**
- `GET /tasks/{id}/attachments/{attachmentId}/download` → `TaskController::downloadAttachment`
  matches the attachment against `TaskService::attachments($id)`, so the child ↔ parent match is
  correct and tenant-scoped. But it **never loads the task through `TaskService::find()`**. The route
  has only `Auth + ModuleMiddleware('sales')`.
- `GET /support/tickets/{id}/attachments/{attachmentId}/download` → `TicketController::downloadAttachment`
  has the same gap (no `TicketService::find()`, no RBAC on the route).

**Risk:**
- **Task:** a user of the same organization whose `tasks.view` is OWN (creator or assignee only),
  or who has no `tasks.view` at all, can download files of tasks they can't open, by guessing
  `{id}` and `{attachmentId}`.
- **Ticket:** any logged-in user of the organization with Support enabled can download ticket files
  without `tickets.view`.
- Cross-tenant is **not** possible: attachments are tenant-scoped.
- **Path tampering is not possible:** the file path comes from the DB row (`stored_path`, a random
  name under `storage/uploads/{org}/`), never from the request.
- **Severity: medium (same-organization, permission bypass).**

**Minimal fix (same view rule as the record's own page):**
- **Task:** a new `TaskService::attachmentForDownload(array $user, int $taskId, int $attachmentId): ?array`
  does `find($user, $taskId)` (`tasks.view` + OWN / assignee rule), then the existing Core
  `AttachmentService::findForSubject('task', $taskId, $attachmentId)` from the record-support
  patch. The controller uses it and otherwise 404s.
- **Ticket:** `TicketController::downloadAttachment` first calls `$this->tickets->find($user, $id)`
  (`tickets.view`, the check the ticket page already uses), then `AttachmentService::findForSubject('ticket', …)`.
- **Error codes:** a task or ticket outside the user's rights gives 403 (AuthorizationException,
  as the record page). A missing record or an attachment of another record gives 404. File
  serving is unchanged (`Content-Disposition: attachment`, name sanitized).

### H4 — Ticket attachment upload doesn't check the ticket

**Today:** `POST /support/tickets/{id}/attachments` → `TicketController::uploadAttachment` calls
`AttachmentService::upload('ticket', $id, …)` directly. Nothing checks that ticket `{id}` exists in
the organization or that the user may view it. The file is stored and the timeline is written for
any `{id}`.

**Risk:**
- **Orphan files:** a missing or foreign id stores a row and a file in the caller's own
  organization; nothing leaks.
- **Bypass:** a user without `tickets.view` can attach files to any ticket of the organization.
- **Severity: low–medium.**

**Minimal fix:** `$this->tickets->find($user, $id)` **before** `upload()`. The file is only moved into
storage after the check passes; a failing check gives 404 or 403. The permission level stays "can view
the ticket", which is today's effective rule for everyone who sees the ticket page. No new permission
and no edit-level requirement (see out of scope).

### H5 — Follow-ups: English flash messages (G6)

**Today:**
- `FollowUpController`, 7 literals: "Follow-up created / updated / deleted / completed /
  cancelled / rescheduled.", fallback "Could not reschedule.".
- `FollowUpRuleController`, 5 literals: "Rule created / updated / deleted.", fallback
  "Could not create this rule.".

These show in English in every language.

**Minimal fix:** `t('followups.flash.created')` etc., with **12 new keys** in all five language files
(en is the fallback). Behaviour is otherwise identical: same flash type and redirect.

Related but **not** flash: service validation messages are still English ("Title is required.",
"A new due date/time is required.", and the rule service's JSON messages). The reschedule
**error flash** displays the service text. See decision D3.

---

## B. Design summary

| # | Files (expected) | Change |
|---|---|---|
| H1 | `modules/Followups/Services/FollowUpService.php` (+ `Listeners/FollowUpRuleEngineListener.php` if D1) | tenant-scoped checks of assignee / team / department; rule-engine assignee fallback |
| H2 | `modules/Followups/Services/FollowUpService.php` | safe defaults on create, keep current values on update |
| H3 | `modules/Sales/Services/TaskService.php`, `modules/Sales/Controllers/TaskController.php`, `modules/Support/Controllers/TicketController.php` | parent `find()` before serving; `findForSubject()` match |
| H4 | `modules/Support/Controllers/TicketController.php` | `find()` before `upload()` |
| H5 | `modules/Followups/Controllers/FollowUpController.php`, `FollowUpRuleController.php`, `lang/{en,fa,ar,tr,fr}.php` | `t()` keys |

**No change to:**
- Core, routes/URLs, permissions, schema/migrations;
- Contracts, Projects, Finance, ClientRecords;
- the existing Core `AttachmentService` API (reused as is).

New dependencies are optional constructor parameters with defaults (`UserRepository`,
`TeamRepository`, `DepartmentRepository`), so module wiring stays as it is.

## C. Tests (new suite `tests/lite/HardeningPatchTest.php`, crm_local, rolled back)

**H1:**
- **Positive:** an active org user as assignee, the user's own team/department, and empty values.
- **Negative (422, nothing stored):**
  - another organization's user, team or department;
  - an unknown id;
  - an inactive user as a *new* assignee.
- **Edit:** keeping a since-deactivated saved assignee succeeds; switching to a foreign one gives 422.
- **Cross-tenant:** the follow-up list and the contract/project Follow-ups panel never show a
  foreign user's name.
- **Rule engine (if D1):** a rule with a foreign `assign_to_user_id` creates the follow-up assigned to
  the actor, never the foreign user.

**H2:**
- Create with `type` / `priority` missing → `task` / `normal`, no warning or 500. With `''` → defaults.
  With an invalid value → defaults.
- Update with them missing → the stored values are unchanged. Update with valid new values → changed.

**H3:**
- **Task, positive:** download as admin, as the creator and as an assignee (OWN).
- **Task, negative:** OWN user who is neither creator nor assignee → 403; no `tasks.view` → 403;
  attachment of task A through task B → 404; missing task → 404.
- **Ticket, positive:** download with `tickets.view`.
- **Ticket, negative:** without `tickets.view` → 403; attachment of another ticket, or of a contact, → 404.
- **Cross-tenant:** a foreign attachment id → 404.
- **Body check:** the response body is the file only on success; no bytes are served on any refusal.

**H4:**
- Upload to an existing ticket works (attachment row + timeline).
- A missing or foreign ticket gives 404; no `tickets.view` gives 403. In both cases **no attachment
  row, no stored file, no timeline**.
- `move_uploaded_file()` can't run in CLI, so the test proves the refusal happens *before*
  `upload()`: row count and the storage folder are unchanged.

**H5:**
- Every new `followups.flash.*` key exists in all five languages.
- Each action (create, update, delete, complete, cancel, reschedule, rule create/toggle/delete)
  stores the translated flash in fa/ar/en/tr/fr, and no English literal remains in the controllers
  (source scan).

**Regression:**
- The whole suite (366), in particular `FollowUpRelatedRecordsTest`, `RecordSupportOwnershipTest`
  (task comments/attachments, ticket attachments) and ClientRecords follow-ups.
- The real task and ticket pages still list and download files for allowed users.

**Mutation checks:**
- remove each H1 check;
- revert H2 to the old expression;
- remove `find()` in each download;
- remove `find()` before upload;
- put one literal flash back.

Each must turn a test red.

## D. Decisions needed

- **D1 — Rule-engine assignee.** Also guard `assign_to_user_id` in `FollowUpRuleEngineListener`
  (fall back to the actor) — **recommended**: same G4 hole through automation — or leave the engine as is?
- **D2 — Ticket upload permission level.** Keep "can view the ticket" (**recommended**: today's
  effective rule, no new restriction), or require an edit-level permission (a product rule change)?
- **D3 — Service validation messages.** Translate the 5 English validation messages of
  `FollowUpService` / `FollowUpRuleService` in this patch as well (**recommended**: one of them
  already shows as a flash on reschedule), or keep strictly to controller flash literals?

## E. Out of scope (recorded, not changed)

- `followups.assign` exists in `Permission.php` but is **never enforced**. Anyone with
  `followups.create` / `edit` can set an assignee. Enforcing it would be a permission behaviour change.
- The task attachment *upload* already requires `tasks.edit` and is unchanged. Ticket *reply* rules
  are unchanged.
- English validation messages elsewhere (Finance, Support, Sales services).
- Other unscoped name subqueries in other modules: none found in this audit besides H1's
  `assignee_name`, already covered by validating writes.
- Follow-up `timezone` default `Asia/Tehran` (pre-existing data-model note).

## F. Implementation plan (after approval)

| Stage | Work | Acceptance |
|---|---|---|
| 1 | H2 (defaults) | H2 tests green; no PHP warning on missing keys; full suite green |
| 2 | H1 (+ D1) | H1 tests green, including the cross-tenant name check; the H1 tests **fail on the unpatched service**; full suite green |
| 3 | H3 (task + ticket download) | H3 tests green; they fail on the unpatched controllers; allowed users still download; full suite green |
| 4 | H4 (ticket upload) | H4 tests green; no row or file stored on refusal; full suite green |
| 5 | H5 (+ D3) translations | key and source-scan tests green in 5 languages; full suite green |
| 6 | Mutation checks, docs, CHANGELOG, `upload.zip` | every mutation caught; `php tests/lite/run.php` all green; package without `.env` / `ddddd/` / tests / docs |

Anything that turns out to need a change outside these files stops the work and is reported first.
