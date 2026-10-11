# Follow-ups on Contracts and Projects — audit and design

Status: **approved and implemented (2026-10-09).** D1 `RecordTypeRegistry::find()`; D2 panels on
contract + project only (deal unchanged); D3 service allow-list. Baseline 318 → **341 passed, 0 failed.**

Implementation notes (inside the approved design):
- **Panel rows** are filtered per row with `AuthorizationEngine::can(followups.view, row)`, the
  exact check of the follow-up page. This is used instead of the "owner or assignee" filter
  `ClientIntegrations` uses, so the panel never lists a row whose link would return 403.
- **The panel lists all of the record's follow-ups**, without a "view all" link. `/followups` has no
  related-record filter, and adding one would be a new feature.
- **Pre-existing bug, left unchanged (out of scope):** `validatedFields()` returns a null
  `type`/`priority` when the request omits them. The real form always sends both.
- **Mutation checks:**
  - **Caught:**
    - POST `find()` re-check removed;
    - GET `find()` check removed;
    - registry bypassed;
    - service allow-list removed;
    - panel row scope filter removed;
    - panel button `find()` removed;
    - panel button permission removed.
  - **Not caught, by design:** removing the early raw-type rejection. `registryRecord()` still
    refuses any type other than contract/project, so it is a second guard.

Goal: a follow-up can be related to a **contract** or a **project**, created from that record's
page, and listed there. This is done without changing Contracts or Projects, and without new
permissions, registries or tables.

---

## A. Audit

### A.1 How follow-ups relate to records today

- Table `follow_ups` (module Followups, migration 0001): polymorphic `related_type VARCHAR(50)` +
  `related_id`, with **no foreign key**. Any type string fits, so **no schema change is needed** for
  `contract` / `project`.
- **Manual follow-ups**, through `FollowUpController`:
  - The form has two selects, `related_contact_id` and `related_lead_id`. `withRelated()` turns
    them into `related_type` / `related_id`.
  - `relatedRecord()` validates only `contact` and `lead`, through Core tenant-scoped repositories.
    It hard-codes the two types.
  - `showCreate(?related_type=&related_id=)` preselects only contact or lead.
  - A follow-up linked to anything else (today: `deal`, created by automation) is shown with its
    link **read-only**: `relatedEditable = false`, and the existing link is kept on edit.
  - `relatedLabel()` builds `'/' . $type . 's/' . $id` and a person's name (`first_name`), so it only
    works for contact and lead.
- **Automated follow-ups:** `FollowUpRuleEngineListener` creates `related_type = 'deal'` rows on
  `DealStageChanged`, through `createAutomated()`.
- **Where follow-ups are listed and created per record:**
  - Contact page: the "New follow-up" button, gated by Core `FollowUpAccess::canCreate()`, and the
    related-records list (`ContactLinkRepository`, hard-coded `follow_ups` source).
  - Lead page and lead list: "New follow-up" button.
  - ClientRecords: `ClientIntegrations::followUpsForContact()` (`forRelated('contact', …)` with OWN
    scope filtered by the caller).
  - **Contract and project pages show nothing about follow-ups today.**
- `FollowUpService::forRelated()` is tenant-scoped (explicit `f.organization_id`). Applying
  `followups.view` scope is documented as the caller's job.
- **Timeline:**
  - `recordTimeline()` writes `follow_up_created` on the **related record's** timeline.
  - complete, cancel and reschedule write on it too.
  - Contract and project pages already render their timeline (`TimelineService::forSubject`), so
    these entries will appear there automatically.
- **Wiring:**
  - Followups has no `ProvidesServices`. `FollowUpService` is bound through the old `instanceof`
    block in `Application::bootstrapModuleRoutes()`. That still works; the block is not touched.
  - Followups does not use `RelatedPanelRegistry` or `RecordTypeRegistry` yet.
- **Extension points available, unchanged:**
  - `RecordTypeRegistry::find('contract'|'project', $id, $user)`: both types have finders. It
    checks module available + view permission + same organization + record scope.
  - `RelatedPanelRegistry`: the `contract` page (`contracts/show.php`) and the `project` page
    (`projects/show.php`) already include the panel slot.

### A.2 Findings (existing code)

| # | Finding | Severity | In this change? |
|---|---|---|---|
| G1 | The related type is hard-coded to contact/lead in the controller: no way to relate to a contract or project. | — (the feature gap) | **yes** |
| G2 | `FollowUpService::validatedFields()` accepts **any** `related_type` string and any `related_id`. Only the controller validates. A future caller could store an unchecked link. | low | yes: whitelist the type in the service (B.4) |
| G3 | `relatedLabel()` builds the URL from the type name and reads `first_name`. It would be wrong for contract/project. | — | yes (B.3) |
| G4 | `assignee_user_id`, `team_id` and `department_id` aren't checked against the organization. A foreign user id can be stored. There is no leak: every read is tenant-scoped. | low | **no** (unrelated; noted) |
| G5 | The Followups module imports `Modules\Sales\Events\DealStageChanged` directly (pre-existing coupling). It is harmless when Sales is absent. | — | no |
| G6 | Flash messages in `FollowUpController` are hard-coded English ("Follow-up created."). | — | no |

### A.3 Constraints that shape the design

- **Contracts and Projects must not change.** So Followups contributes the UI on their pages
  (panels), exactly as Projects did on the contract page.
- **Contract/Project records are read only through `RecordTypeRegistry`.** Followups never imports
  either module and never queries their tables.
- **No list API exists for another module's records.** Followups cannot offer a "pick a contract"
  dropdown without querying the contracts table. So a contract/project link is set **from that
  record's page** (prefilled, then re-validated on the server), not picked from a list in the form.

---

## B. Design

### B.1 Relatable types

```
contact, lead       → as today (Core tenant-scoped repositories; picked in the form)
contract, project   → NEW; validated with RecordTypeRegistry::find($type, $id, $user)
deal                → unchanged (automation only; kept read-only)
```

Validation for contract/project uses `find()`, because a follow-up should only be attached to a
record the user may **view**. That means:
- the module is available to the user;
- the user holds `contracts.view` / `projects.view`;
- the record is in the same organization and within the user's record scope.

`find()` returns null in every other case, which becomes "not available" (404 on the prefill,
422 on a tampered submit). If Contracts or Projects is disabled or absent, the option simply
doesn't exist.

Known edge case (safe direction): `find()` doesn't know Projects' membership rule. A member whose
`projects.view` scope is *own* can open a project they belong to but don't manage, yet can't attach
a follow-up to it. The panel hides the "New follow-up" button in exactly that case, so the UI never
offers what the server refuses.

### B.2 Panels on contract and project pages (Followups-owned)

`Modules\Followups\FollowUpPanels` (new) registers through `RelatedPanelRegistry`:

| Subject | Panel id | Module / permission | Shows |
|---|---|---|---|
| `contract` | `followups.contract` | `followups` / `followups.view` | open and recent follow-ups related to the contract; "New follow-up" |
| `project` | `followups.project` | `followups` / `followups.view` | same for the project |

- **Rows:** `forRelated($type, $id)`, then the caller's `followups.view` scope (OWN = owner or
  assignee), the same rule `ClientIntegrations` applies. At most 5 rows, plus "view all".
- **"New follow-up" button:** shown only when the user holds `followups.create` **and**
  `find($type, $id)` succeeds. It links to the existing route
  `/followups/create?related_type=contract&related_id=N`.
- **Hosts are untouched:** the contract and project templates already have the slot.
- **Module off:** with Followups disabled, the panel disappears. With Contracts or Projects
  disabled, their page and panel slot don't render.

### B.3 FollowUpController changes (Followups module only)

1. **`showCreate`:** also accepts `related_type=contract|project`. The record is resolved with
   `find()`; a record that isn't available returns 404, same as Projects' `?from_contract=`.
   The form then shows a read-only line, "Related to: Contract X", and posts hidden
   `related_type` / `related_id`.
2. **`withRelated()`:** the hidden pair is accepted only for `contract|project`, and it is
   **re-validated on the server** with `find()`. Hidden fields are never trusted. A failed check
   gives 422 with `followups.related_invalid` and nothing is created. The contact/lead selects
   behave exactly as today, and only one link is allowed.
3. **Editing a follow-up linked to a contract or project:** the link is shown read-only and kept,
   like `deal` today. No re-linking UI is added.
4. **`relatedLabel()`:**
   - contact and lead stay as today;
   - contract and project use the registry's `showUrl()` and the row's `title` / `name`;
   - when the record isn't available, the label is plain "Contract #N" / "Project #N" with no link;
   - `deal` keeps its current behaviour (no label).

### B.4 FollowUpService hardening (G2)

`validatedFields()` rejects a `related_type` outside a fixed allow-list:

```
contact, lead, deal, contract, project
```

It throws `ValidationException`, which becomes 422. The list covers every value used today:
- the form gives contact/lead;
- `ClientIntegrations` gives contact;
- `createAutomated()` gives deal, and does not go through `validatedFields()` anyway.

Behaviour for valid data is unchanged. The record itself is still validated by the controller,
where `find()` and the Core repositories are available.

### B.5 What does not change

- **No schema/migration, permission, route or URL change.** `/followups/create?related_type=…`
  already exists; it just accepts two more types.
- **No change to:**
  - Contracts, Projects or Sales;
  - Core `FollowUpAccess`, `ContactLinkRepository`, `Application`;
  - the rule engine or reminders.
- **Timeline:** entries on contract/project timelines come from the existing `recordTimeline()`.

### B.6 Files

- **Modified (Followups only):** `Controllers/FollowUpController.php`, `Services/FollowUpService.php`
  (allow-list), `FollowupsModule.php` (constructor wiring of `RecordTypeRegistry` +
  `FollowUpPanels::registerAll()`).
- **New:** `modules/Followups/FollowUpPanels.php`, `themes/default/templates/followups/_panel.php`.
- **Changed templates:** `themes/default/templates/followups/form.php` (read-only "related to" line +
  hidden fields), `followups/show.php` (label from B.3 only).
- **Translations:** about 6 new keys in all five languages: `followups.related_type.contract`,
  `.project`, `followups.panel_empty`, `followups.view_all`, and others.
- **Tests:** new `tests/lite/FollowUpRelatedRecordsTest.php`; `CHANGELOG.md`; this document.

### B.7 Tests

**Feature:**
1. Create from the contract page (`?related_type=contract&related_id=`) → follow-up linked; the
   `follow_up_created` entry appears on the contract's timeline. Same for project.
2. The contract and project panels list the follow-ups; "view all" appears when there are more than 5.
3. The show page links to the contract/project; edit keeps the link read-only; complete, cancel and
   reschedule write on the related timeline.

**Security / isolation:**
4. Prefill with a missing id, another organization's contract/project, or a record outside the
   user's scope → 404.
5. Tampered submit → 422 and nothing created. Cases:
   - `related_type=contract` with a foreign id;
   - `related_type=project` with an id outside scope;
   - `related_type=widget`;
   - contact **and** contract both set.
6. Service allow-list: `related_type = 'anything'` → `ValidationException`.
7. Panel rows respect `followups.view` OWN scope (owner or assignee only). Another organization's
   follow-ups never appear.

**Module states:**
8. Followups off → no panel on contract/project pages. Contracts or Projects off → that type is
   refused on create; existing follow-ups show the plain "Contract #N" label; `/followups` pages still work.
9. Standalone: with only Projects or only Contracts installed (existing standalone suites),
   nothing breaks. Followups is absent there, so no panel.

**Regression:**
10. Contact/lead create and edit, the automation `deal` link, ClientRecords follow-ups, the
    dashboard tile — unchanged. The whole suite (318) stays green.
11. Translations: new keys in all five languages; panels and form render in fa/ar/en/tr/fr with no raw key.

**Mutation checks after implementation:**
- remove the `find()` re-check on submit;
- remove the service allow-list;
- drop the OWN-scope filter in the panel.

Each must turn a test red.

## C. Decisions needed

- **D1. Validation strength for contract/project:** `RecordTypeRegistry::find()` (view permission +
  record scope, **recommended**: attaching work to a record should require being allowed to see it)
  or only "exists in the user's organization" (as in the record-support patch)? With `find()`,
  an own-scope project member who doesn't manage the project can't attach follow-ups to it (B.1).
- **D2. Panels:** contract and project pages only (**recommended**), or also a follow-ups panel on the
  deal page? Deals already get automated follow-ups, but that is a separate feature request.
- **D3. G2 allow-list in `FollowUpService`:** include it (**recommended**; small, closes an unchecked
  path) or leave the service as is?
- **Out of scope (not proposed now):**
  - automatic renewal-notice follow-ups when a contract is activated (would listen to `ContractActivated`);
  - follow-ups from project status changes;
  - G4–G6.
