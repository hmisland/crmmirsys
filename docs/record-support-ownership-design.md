# Record support: child ↔ parent ownership — audit and design

Status: **approved and implemented (2026-10-09).** D1 yes (Sales/Support routes fixed too);
D2 parent = "exists in the user's organization" (no `RecordTypeRegistry::find()` scope); D3 unchanged.
Baseline: `php tests/lite/run.php` → 303 tests, 303 passed. After: **318 passed, 0 failed, 0 skipped.**

Implementation notes (inside the approved design):
- `routes/web.php` needed no change. The three Core repositories are optional constructor
  parameters of `RecordSupportController`, with default instances.
- `TagService::detach()` itself became a **no-op** for a tag outside the organization (no
  exception). The contact-import undo calls it in a loop and must not crash. The route uses the
  new `detachFromSubject()`, which returns 404 before any write.
- Sales/Support parent check = the module's existing `find()` (`TaskService::find`, `TicketService::find`).
  These are the same checks the task and ticket pages already apply, so nothing a user can see
  becomes unusable.
- Proof:
  - `RecordSupportOwnershipTest` was run against the **unpatched** code: all 11 security tests fail;
    the 2 route regressions (R1, R2) pass on both versions.
  - Mutation checks are all caught by the suite:
    1. note parent match removed;
    2. note type match removed;
    3. attachment parent match removed;
    4. `detach()` guard removed;
    5. `detachFromSubject()` guard removed;
    6. `requireParent()` removed;
    7. the controller's organization re-check removed (test S8b, which uses a deliberately leaky finder);
    8. the unchecked note delete put back in the controller;
    9. the unchecked task comment delete put back;
    10. the unchecked ticket attachment delete put back.

Scope: make every note / attachment / tag action prove that the child really belongs to the
`{type}/{id}` in the URL, and that this parent exists in the user's organization. Existing
organization isolation stays exactly as it is; this only adds checks.

---

## A. Audit

### A.1 How the shared routes are guarded today

`routes/web.php`, group `/records/{type}/{id}` (AuthMiddleware + CsrfMiddleware on POST):

| # | Route | Handler | `{type}` checked | `{id}` (parent) checked | child belongs to parent |
|---|---|---|---|---|---|
| 1 | `POST /notes` | `addNote` | ✅ `subject()` | ❌ | — |
| 2 | `POST /notes/{noteId}/delete` | `deleteNote` | ⚠️ **after** the delete | ❌ | ❌ |
| 3 | `POST /tags` | `addTag` | ✅ | ❌ | — |
| 4 | `POST /tags/{tagId}/delete` | `removeTag` | ✅ | ❌ | ⚠️ tag not org-checked (A.2.3) |
| 5 | `POST /attachments` | `uploadAttachment` | ✅ | ❌ | — |
| 6 | `GET /attachments/{attachmentId}/download` | `downloadAttachment` | ✅ | ❌ | ✅ via `forSubject($type, $id)` |
| 7 | `POST /attachments/{attachmentId}/delete` | `deleteAttachment` | ✅ | ❌ | ❌ |

`subject()` checks only that the user `canView()` the **kind** of record (`RecordTypeRegistry`:
module available + view permission) and that `recordSupport` is on. It never looks at `{id}`.

Organization isolation that does hold: `notes` and `attachments` are tenant-scoped
(`NoteRepository` / `AttachmentRepository` extend `TenantScopedRepository`, so `findById`,
`deleteNote` and `deleteAttachment` only ever touch the caller's organization). The
`ContractMatrixTest` / `ProjectMatrixTest` cross-tenant checks for `/records` confirm that.

### A.2 Findings

**F1. Delete a note of any record in the organization (the reported issue). Severity: high.**
`deleteNote` runs `$this->notes->delete($noteId)` **before** `subject()`, and never compares
`notes.notable_type/notable_id` with the URL.
- `/records/contact/123/notes/{noteOfContact456}/delete` deletes contact 456's note.
- Because the delete happens before the type check, `/records/anything/1/notes/{id}/delete` also
  deletes the note and only *then* returns 404. That includes a user with **no role**, a user
  for whom the module is disabled, or a `{type}` that doesn't exist.
- `notes` is shared by every type (contact, company, lead, contract, project, task, client_record),
  so this reaches notes of any type in the organization.

**F2. Delete an attachment of any record in the organization. Severity: high.**
`deleteAttachment` deletes `{attachmentId}` (row and stored file) without checking
`attachable_type/attachable_id`. The type check runs first here, but any user who can view *one*
record type can delete any attachment in the organization. That includes contract files, project
files, ticket and task files, and the attachments behind Client Records files.

**F3. Cross-tenant tag writes (taggables has no organization_id). Severity: high.**
- **Remove:** `TagService::detach()` looks the tag up through the tenant-scoped repository, but
  calls `TagRepository::detach()` even when that lookup returns null. `DELETE FROM taggables WHERE
  tag_id AND type AND id` is not tenant-scoped. A user in org A can therefore remove org B's tag
  from org B's record by guessing two integers.
- **Add:** `addTag` attaches the caller's (org A) tag to any `{id}`, including an org B record.
  `TagRepository::forSubject()` (used on show pages) is also not tenant-scoped, so **org A's tag
  name then appears on org B's record page**. This is a leak plus pollution.

**F4. Writes to a parent that doesn't exist or belongs to another organization. Severity: medium.**
`addNote` and `uploadAttachment` accept any `{id}`. The rows are stored in the caller's own
organization, so nothing leaks, but they are orphans pointing at someone else's id. The timeline
entry is orphaned the same way. This was already documented as pre-existing observation 7.3.

**F5. Download doesn't verify the parent. Severity: low.**
The child ↔ parent match is correct here (`forSubject`). Only the parent-exists check (F4) is missing.

### A.3 Same weakness elsewhere (outside RecordSupportController)

| Route | Code | Problem | Severity |
|---|---|---|---|
| `POST /tasks/{id}/comments/{commentId}/delete` (Sales) | `TaskController::deleteComment` → `TaskService::deleteComment($noteId)` → `NoteService::delete` | No task check, no permission check, no ownership check. Any user with Sales can delete **any note in the organization** (contact, contract, project…) through this URL. | high |
| `POST /tasks/{id}/attachments/{attachmentId}/delete` (Sales) | `TaskService::deleteAttachment($id)` | Same, for **any attachment** in the organization. | high |
| `POST /support/tickets/{id}/attachments/{attachmentId}/delete` (Support) | `TicketController::deleteAttachment` → `AttachmentService::delete` | Same: no ticket check, no ownership check. | high |
| `GET /tasks/{id}/attachments/{aid}/download`, `GET /support/tickets/{id}/attachments/{aid}/download` | controller | Child ↔ parent match is correct; the task/ticket itself isn't checked against the user's view scope. | low |

Checked and **already correct** (reference pattern):
- `ClientRecordService::deleteNote` (checks `forSubject('client_record', $id)`).
- `ClientFileService` (`requireFile($record, $fileId)`).
- Contracts `unlink` and Projects `removeMember` / `unlink` (they iterate the parent's own rows).
- ClientRecords services and sessions (`findService($record, …)` / `findSession`).
- Knowledge File Library (no parent in the URL).

Root cause, shared by every finding: `NoteService::delete(int)` and `AttachmentService::delete(int)`
take only a child id. Every caller has to remember the ownership check, and four don't.

---

## B. Design

Principles:
- No new authorization system.
- No URL or API change.
- Organization isolation is kept as is; checks are only added.
- The ownership check moves into the Core services, so callers can't forget it.

### B.1 Core services: ownership-checked variants (additive)

```php
// NoteService
public function deleteFromSubject(string $type, int $id, int $noteId): void
//   $note = NoteRepository::findById($noteId)            (tenant-scoped, unchanged)
//   if null || notable_type !== $type || notable_id !== $id → NotFoundException
//   then deleteNote($noteId)

// AttachmentService
public function findForSubject(string $type, int $id, int $attachmentId): ?array
public function deleteFromSubject(string $type, int $id, int $attachmentId): void
//   same rule on attachable_type / attachable_id; the stored file is unlinked
//   only AFTER the match (no file touched on a mismatch)

// TagService::detach()
//   tag not found in the caller's organization → NotFoundException, and NO taggables delete
```

`NoteService::delete(int)` and `AttachmentService::delete(int)` stay for callers that have already
checked ownership (ClientRecords). Every route-facing caller moves to the `…FromSubject` variant.
No repository, schema or migration change.

### B.2 RecordSupportController

1. `subject()` runs **first** in every action. This fixes the F1 ordering bug.
2. New private `requireParent(string $type, int $id): void`. The parent must **exist in the user's
   organization**, otherwise 404:
   - **Core types** (`contact`, `company`, `lead`): Core's own tenant-scoped repositories
     (`ContactRepository` / `CompanyRepository` / `LeadRepository::findById`), injected into the
     controller. Core resolving its own types is not a module coupling.
   - **Module types** (`contract`, `project`, …): the `RecordType` **finder** the module already
     registered (tenant-scoped), plus the same organization-id re-check `RecordTypeRegistry::find()` does.
   - A type with `recordSupport = true` but **no finder** → 404 (secure default). No such type exists today.
   - It is called in all 7 actions. Fixes F3 (add) and F4/F5.
3. `deleteNote` → `NoteService::deleteFromSubject($type, $id, $noteId)` (F1).
4. `deleteAttachment` → `AttachmentService::deleteFromSubject(...)` (F2).
5. `downloadAttachment` → `AttachmentService::findForSubject(...)`. Same behaviour, now in one place.
6. `removeTag` → `TagService::detach()` now refuses a tag outside the organization (F3 remove).

Responses stay as today: 404 (`NotFoundException`) for anything that doesn't match. A user never
learns whether a child id exists elsewhere.

Why not add finders to the Core `RecordType`s and use `RecordTypeRegistry::find()` everywhere? It
has two side effects outside this task:
- **(a)** Contracts' `createLinked()` / `link()` accept any type that has a finder, so `contact`,
  `company` and `lead` would suddenly become linkable sources for contracts. That would be a
  behaviour change in Contracts.
- **(b)** `find()` also applies own/team/department record scope. That is stricter than today's
  contact, company and lead pages, which check only the blanket view permission. It is also
  stricter than Projects' membership rule (an own-scope project member can view the project but
  `find()` refuses it), so it would block adding notes on pages the user can legitimately see.

Option B.2 fixes ownership and the parent's organization without either side effect. See decision D2.

### B.3 Same fix for the other routes found (decision D1)

Small, mechanical changes, only to swap in the ownership-checked Core calls and check the parent with
the permission the module already uses for the matching "add" action:
- **Sales `TaskService`:**
  - `deleteComment(array $actingUser, int $taskId, int $noteId)`: `requireTask` +
    `authorizeRecord(TASKS_VIEW)` (the same check `addComment` uses) + `NoteService::deleteFromSubject('task', …)`.
  - `deleteAttachment(...)`: same, with `AttachmentService::deleteFromSubject('task', …)`.
  - `TaskController`: pass the user and `{id}`.
- **Support `TicketController::deleteAttachment`:** `$this->tickets->find($user, $id)` (existing, applies
  the ticket view rules) + `AttachmentService::deleteFromSubject('ticket', …)`.
- The two download routes: add the same parent check (`find`) before the existing `forSubject` match.

No URL, permission key or template change. No new feature.

### B.4 Tests (new suite `tests/lite/RecordSupportOwnershipTest.php`, crm_local, rolled back)

Security (each must be 404, with the child row, the stored file and the other parent's data unchanged):
1. **Note:** delete through parent B for every record type `contact`, `company`, `lead`,
   `contract`, `project`, using a note that belongs to parent A of the same organization.
2. **Note, other type:** a note of a different type with the same numeric id
   (`/records/company/5/notes/{noteOfContact5}`) is refused.
3. **Note, ordering bug:** a delete through an unknown `{type}`, through a type whose module is off,
   and by a user with no role must keep the note.
4. **Attachment, delete and download:** through the wrong parent; the file stays on disk.
5. **Tags:** removing another organization's tag (fixture rows in org B) leaves org B's
   `taggables` row intact; adding a tag to another organization's record writes no `taggables` row;
   org B's page never shows org A's tag name.
6. **Parent check:** notes, tags and uploads on a non-existent `{id}` and on another
   organization's record write nothing (no orphan note, attachment, taggables or timeline row).
7. **Cross-tenant:** a foreign note or attachment id on my own record returns 404. The existing
   Contracts/Projects cross-tenant tests keep passing unchanged.
8. **(If D1 approved):** a contact's note deleted through `/tasks/{t}/comments/{id}/delete` and a
   contact's attachment through the task and ticket routes, both 404, both kept. Also a task the
   user can't see.

Regression (must still work):
9. Add/delete note, add/remove tag, upload/download/delete attachment on each of the 5 record
   types, with the correct parent. Same for task comments, task attachments and ticket attachments.
10. The full existing suite (303) stays green, including the Contracts/Projects `/records` tests
    and `StandaloneContractsTest` / `StandaloneProjectsTest`.

Mutation checks after implementation:
- remove the parent match in `deleteFromSubject`;
- move `subject()` back after the delete;
- drop the `tag === null` guard;
- drop `requireParent`.

Each must turn a test red.

### B.5 Files that would change

- `app/Application/Services/NoteService.php`, `AttachmentService.php`, `TagService.php`: additive methods and the detach guard.
- `app/Application/Controllers/RecordSupportController.php`: order, `requireParent`, ownership-checked calls.
- `routes/web.php`: pass the three Core repositories to the `RecordSupportController` constructor (wiring only; no route change).
- **If D1:** `modules/Sales/Services/TaskService.php`, `modules/Sales/Controllers/TaskController.php`, `modules/Support/Controllers/TicketController.php`.
- `tests/lite/RecordSupportOwnershipTest.php` (new), `CHANGELOG.md`, this document, and pre-existing observation 7.3 in `docs/contracts-step2-report.md` marked resolved.

No database change, no new permission, no translation change: errors stay 404, as today.

## C. Decisions needed

- **D1.** Fix the Sales task and Support ticket routes (A.3) in the same change? **Recommended: yes.**
  They expose the very same deletion (any note or attachment in the organization) through a
  different URL, so fixing only `/records` would leave the hole open.
- **D2.** Parent check = "exists in the user's organization" (B.2, **recommended**), or the stricter
  `RecordTypeRegistry::find()` with record scope? The stricter one has the side effects listed in B.2.
- **D3.** Out of scope, noted only: today any user who can view a record may delete **any** note on
  it, not just their own. Restricting to the author or an edit permission would be a product rule
  change, so it is not part of this fix unless you want it.
