# Projects module: Step 3 audit and design

Status: **design approved and implemented (2026-10-08).** All six stages of B.15 done.
Baseline before this step: `php tests/lite/run.php` → 203 tests, 203 passed.
After: **303 tests, 303 passed, 0 skipped** (100 new in 9 Projects suites).

Decisions confirmed by the product owner: statuses + `reopen` (B.4); no Lead link;
Contracts untouched (Projects panel on the contract page); Deal: manual create, link
existing, optional auto-create off by default, one project per deal; links without FKs;
membership = view only, members managed with a separate permission; separate sidebar group;
four tables + grant migration; `PRJ-0001` from `number_series`; two events.

Deviations from the text below, both small and inside the approved design:

- **Link route.** "Link existing project" posts to a fixed `POST /projects/links` with
  `project_id` in the form body, instead of `POST /projects/{id}/links`. The panel lets the
  user pick the project, so the id isn't known when the form is rendered. No JavaScript needed.
  Same permission (`projects.edit` at the route, re-checked on the row by the service).
- **Icon.** `kanban` instead of `briefcase` (deals already use `briefcase` in the sidebar).

Test suites: `ProjectsRecordTypeTest`, `ProjectsPermissionsTest`, `ProjectServiceTest`,
`ProjectPagesTest`, `ProjectMatrixTest` (route × role + organization isolation),
`ProjectLinksTest`, `ProjectDealEventTest`, `StandaloneProjectsTest`, `ProjectTranslationsTest`.
One existing assertion was updated: `BackwardCompatTest` checked "no `project` type registered
yet" (a Step 1 snapshot); it now checks the type is owned by the Projects module, exactly as
Step 2.2 did for `contract`.

Mutation checks (each a deliberate break, then restored):

- **Caught by the suites:**
  - tenant scope removed from `findById`;
  - "one project per deal" guard removed;
  - the listener's entitlement check removed;
  - membership visibility removed;
  - the link-type whitelist removed.
- **Not caught, by design (each is a second guard):**
  - the `organization_id` condition inside the membership subquery (the outer `p.organization_id` still scopes);
  - the delete route's RBAC key (the service re-checks `projects.delete`).

Scope of this step: a standalone `projects` module with lifecycle, manager, members,
Core parties (contact/company), and links to deals and contracts. **Not** in this step:
tasks, milestones, timesheets, budget, invoicing, progress.

---

## A. Audit (what Projects relies on — nothing new is built)

| Need | Existing mechanism | Use |
|---|---|---|
| Module on/off, sold per org | `module.json` + `ModuleAccessResolver` + `ModuleMiddleware('projects')` | as is |
| Access checks | `ModuleGate` → resolver + `AuthorizationEngine` (own/team/department/organization) | as is |
| Permission keys | `Permission::registerModuleKeys()` (P1, step 2.3) + module grant migration | as is — same as Contracts; `Permission.php` itself is not edited |
| Expose service | `ProvidesServices` → `ModuleServiceRegistrar` | as is |
| Notes/tags/files/timeline on a project | `RecordTypeRegistry` (`project` type with finder) + `/records/{type}/{id}` + `TimelineService` | as is |
| Panels on contact/company/deal/contract pages | `RelatedPanelRegistry`; slots already exist on all four pages | as is |
| Read a deal / contract without importing them | `RecordTypeRegistry::find('deal'|'contract', …)` (both types have finders) | as is |
| Deal won | `EventBus::listen('Modules\\Sales\\Events\\DealStageChanged', …)` by class-name string (G2) | as is |
| Numbering | Core `NumberSeriesRepository::next('project', 'PRJ-')` (D2) | as is, no migration needed |
| Tenant isolation | `TenantScopedRepository` + explicit `p.organization_id` in joined searches | as is |
| Sidebar, translations | `$navGroups` in `layout.php`, `lang/*.php` ×5 | project convention |

Findings that shape the design (no architecture change needed):

1. **Lead has no finder** in Core's `RecordType` registration, so another module can't validate a lead id. Linking projects to leads would need a Core change → **not included** (leads convert to contacts/companies, which Projects links directly).
2. `RecordTypeRegistry::find()` checks record scope with `owner_id/team_id/department_id` only. Project **membership** widens visibility inside Projects' own pages, but a cross-module lookup of a project (e.g. a future Contracts link) sees only the stricter RBAC scope. Safe direction; documented, not changed.
3. Contracts already has `ContractService::link()`, but no UI to link arbitrary records. Projects will **not** write `contract_links`; it owns `project_links` and shows itself on the contract page through a panel. **Zero changes to Contracts.**

## B. Design

### B.1 Files

```
modules/Projects/
  module.json                         key "projects", type addon, dependencies []
  ProjectsModule.php                  ModuleInterface + ProvidesServices; register/routes/migrations
  ProjectPermission.php               the 6 keys (below)
  ProjectRepository.php               projects (search with explicit org scope + membership)
  ProjectMemberRepository.php         project_members
  ProjectLinkRepository.php           project_links
  ProjectSettingsRepository.php       project_settings
  ProjectPanels.php                   panels for contact, company, deal, contract
  Services/ProjectService.php         all rules: RBAC + membership, lifecycle, members, links, from-deal/from-contract, auto-create
  Controllers/ProjectController.php   list, show, create/edit, delete, status actions, members, links
  Controllers/ProjectSettingsController.php
  Events/ProjectCreated.php
  Events/ProjectStatusChanged.php
  Listeners/DealStageChangedListener.php
  Migrations/0001_create_projects_table.php
  Migrations/0002_create_project_members_table.php
  Migrations/0003_grant_projects_permissions.php
  Migrations/0004_create_project_links_table.php
  Migrations/0005_create_project_settings_table.php
themes/default/templates/projects/
  index.php  show.php  form.php  settings.php  _panel.php  _helpers.php
```

Core files touched (convention only): `themes/default/templates/layout.php` (one flag + one nav group),
`lang/{en,fa,ar,tr,fr}.php`, `CHANGELOG.md`, docs. No other Core, Sales or Contracts file changes.

### B.2 Record type

```
key 'project', module 'projects', viewPermission 'projects.view',
labelKey 'projects.record', showPath '/projects/{id}', icon 'briefcase',
finder ProjectRepository::findById (tenant-scoped), recordSupport true
```
→ Core notes, tags, attachments and timeline work on projects with no Core change.

### B.3 Permissions (`ProjectPermission`, registered via `Permission::registerModuleKeys`)

| Key | Meaning | org_admin | member |
|---|---|---|---|
| `projects.view` | see projects | organization | organization |
| `projects.create` | create (incl. from deal/contract) | organization | organization |
| `projects.edit` | edit fields, change status, link/unlink | organization | own |
| `projects.delete` | delete (draft/planned/cancelled only) | organization | — |
| `projects.manage_members` | add/remove members | organization | own |
| `projects.manage_settings` | org settings (auto-create) | organization | — |

Grants in module migration `0003` (global system roles, like Contracts `0003`).

### B.4 Statuses and lifecycle

Convention check: Contracts/deals use lower-case snake_case ENUMs (`draft`, `pending_approval`, `cancelled`). The proposed set fits as is:

```
draft ──▶ planned ──▶ active ──▶ completed
  │          │          │  ▲          │
  │          │          ▼  │          └─ reopen ──▶ active
  │          └──────▶ on_hold
  └──────────┴──────────┴──────▶ cancelled   (from draft/planned/active/on_hold)
draft ──▶ active (start directly) is allowed
```

- `completed` sets `actual_end_date` (today unless given); `reopen` clears it.
- Editable fields: draft, planned, active, on_hold. `completed`/`cancelled` are read-only (except reopen).
- Deletable: draft, planned, cancelled.
- Every transition: `projects.edit` on the row, a timeline entry, and `ProjectStatusChanged`.

### B.5 Database (module migrations, each with `down()`)

```
projects
  id INT UNSIGNED PK, organization_id → organizations CASCADE,
  code VARCHAR(50) NOT NULL            -- PRJ-0001 from Core number_series, assigned on create
  name VARCHAR(255) NOT NULL, description TEXT NULL,
  status ENUM('draft','planned','active','on_hold','completed','cancelled') DEFAULT 'draft',
  owner_id → users RESTRICT            -- the project manager
  team_id → teams SET NULL, department_id → departments SET NULL   -- RBAC scope, as contracts
  contact_id → contacts SET NULL, company_id → companies SET NULL  -- Core only
  start_date DATE NULL, expected_end_date DATE NULL, actual_end_date DATE NULL,
  created_by_user_id → users RESTRICT, created_at, updated_at
  UNIQUE (organization_id, code); KEY (organization_id, status); KEY owner_id/contact_id/company_id

project_members
  id, organization_id → organizations CASCADE, project_id → projects CASCADE,
  user_id → users CASCADE, added_by_user_id → users SET NULL, created_at
  UNIQUE (project_id, user_id); KEY (organization_id, user_id)

project_links                         -- same shape as contract_links; NO FK outside the module
  id, organization_id, project_id → projects CASCADE,
  linked_type VARCHAR(50)  ('deal' | 'contract' — whitelisted in the service),
  linked_id INT UNSIGNED, linked_label VARCHAR(255), created_by_user_id, created_at
  UNIQUE (project_id, linked_type, linked_id); KEY (organization_id, linked_type, linked_id)

project_settings
  organization_id PK → organizations CASCADE, auto_create_on_deal_won TINYINT DEFAULT 0,
  updated_by_user_id, updated_at
```

No `budget`, `currency`, tasks or milestones (out of this step).

### B.6 Access model and organization isolation

- Routes: `AuthMiddleware` + `ModuleMiddleware('projects')` (404 when not available) + `RbacMiddleware(key)` + CSRF on POST.
- **View a project** = holds `projects.view` and (scope organization/all, **or** the row matches own/team/department, **or** the user is a member). Lists apply the same rule in SQL, so the list never shows a row `find()` refuses.
- **Edit / status / delete / members** = `AuthorizationEngine::can()` on the row (membership never grants editing).
- Owner and members must be **active users of the same organization**; contact/company must be in the organization (tenant-scoped repositories).
- Every repository query is tenant-scoped; joined searches add `p.organization_id = :org_id` explicitly (Contracts pattern). Another organization's project/member/link id → 404, nothing changed.

### B.7 Service, controllers, routes

`ProjectService`: `list/count/find`, `create/update/delete`, `plan/start/hold/resume/complete/cancel/reopen`, `allowedActions()`,
`members/addMember/removeMember`, `link/unlink/links/linkedTo/countLinkedTo`, `draftFromDeal/draftFromContract/createLinked`,
`settings/saveSettings/autoCreateFromWonDeal`. Exposed via `ProvidesServices`.

Routes (`/projects`, fixed paths before `/{id}`):

```
GET  ''                     view          POST ''                       create
GET  /create                create        (?from_deal=ID | ?from_contract=ID)
GET  /settings              manage_settings   POST /settings            manage_settings
GET  /{id}                  view          GET  /{id}/edit               edit
POST /{id}                  edit          POST /{id}/delete             delete
POST /{id}/status           edit          (action = plan|start|hold|resume|complete|cancel|reopen)
POST /{id}/members          manage_members    POST /{id}/members/{memberId}/delete   manage_members
POST /links                 edit          (link existing project to a deal/contract; project_id in the body)
POST /{id}/links/{linkId}/delete         edit
```

### B.8 Templates

`index` (search, status, mine/member-of, paginated), `show` (facts, status actions from `allowedActions()`,
members, linked deal/contract, related-panel slot `project`, Core notes/tags/files/timeline), `form` (create/edit, `crm-datepicker`),
`settings`, `_panel`. Design-system components only; all text via `t()`; RTL-safe.

### B.9 Related panels (`ProjectPanels`, permission `projects.view`, order 55)

| Host page | Shows | Actions |
|---|---|---|
| contact, company | projects with that party (scoped list) | "New project" (prefilled party) |
| deal | projects linked to the deal | "New project" (`?from_deal=`), "Link existing project" (select of projects the user may edit) |
| contract | projects linked to the contract | "New project" (`?from_contract=`), "Link existing project" |

Host pages and Sales/Contracts are not edited; panels appear only when Projects is available to the user.

### B.10 Project ↔ Contract

- Link stored only in `project_links (linked_type='contract')`; validated via `RecordTypeRegistry::find('contract', …)`.
- From contract: prefill name = contract title, contact, company, start date, expected end = contract end date.
- Contracts disabled/absent → contract features hidden, stored links show as plain label. Projects works fully without Contracts.
- No listener on Contracts' events in this step.

### B.11 Project ↔ Deal (decision D4, exactly as for Contracts)

- Manual: "New project" on the deal panel (independent of any setting) and "Link existing project".
- Listener on `'Modules\\Sales\\Events\\DealStageChanged'` (string, once, in `ProjectsModule.php`), shape-checked with `property_exists`:
  - every project linked to the deal gets a timeline entry (won / lost / stage);
  - on **won**: auto-create a **draft** project linked to the deal **only if** the organization's `auto_create_on_deal_won` is on (off by default) and Projects is active + entitled for that organization; at most one project per deal; owner = deal owner if an active org user, else the winner; deal read via the registry as the winner.
- Independent of Contracts' own auto-create (each module has its own setting); no chaining between them.
- Sales absent → nothing fires; deal features hidden.

### B.12 Events added

- `Modules\Projects\Events\ProjectCreated(organizationId, projectId, code, actorUserId)`
- `Modules\Projects\Events\ProjectStatusChanged(organizationId, projectId, fromStatus, toStatus, actorUserId)`

Dispatched by `ProjectService`; no listeners are added in other modules.

### B.13 Sidebar

`$projectsEnabled` via `ModuleAccessResolver::resolvedForUser('projects', …)` and a separate "Projects" group
(icon `briefcase`), like Contracts' group.

### B.14 Tests (`tests/lite`, `crm_local` only, rolled-back transactions)

| Suite | Covers |
|---|---|
| `ProjectsRecordTypeTest` | registry/record type + finder; architecture: no other module namespace or table referenced; event string exactly once |
| `ProjectsPermissionsTest` | key registration, grant migration, route × role matrix, CSRF |
| `ProjectServiceTest` | CRUD, validation, numbering, lifecycle transitions (allowed/refused), members |
| `ProjectIsolationTest` | other organization's project/member/link/contact/user ids → 404 / 422, lists and search, `/records` routes |
| `ProjectLinksTest` | deal/contract links, from-deal/from-contract, panels on 4 pages, module off → hidden + data kept |
| `ProjectDealEventTest` | listener shape guard, timeline, auto-create on/off, one per deal, entitlement off, event-name string = real class |
| `StandaloneProjectsTest` | temporary copy with only Projects installed (Sales and Contracts absent) |
| `ProjectTranslationsTest` | every key in all 5 languages; pages render in fa/ar/en/tr/fr |

All 203 existing tests must keep passing.

### B.15 Implementation order (each stage tested before the next)

1. Skeleton, migrations 0001/0002, record type, permissions + grants (0003).
2. Repositories + `ProjectService` (CRUD, lifecycle, members, events).
3. Controllers, routes, templates, sidebar, translations.
4. Links (0004), panels, from-deal/from-contract.
5. Settings (0005), listener, auto-create.
6. Full test matrix, docs, CHANGELOG, `upload.zip`.

## C. Decisions to confirm

1. Statuses and transitions as in B.4 (including `reopen` from completed).
2. No Project ↔ Lead link (would need a Core finder for `lead`).
3. Projects owns its links (`project_links`); Contracts is not changed, so the contract page shows projects through the Projects panel, not in its own "Linked records" list.
4. Separate "Projects" sidebar group.
5. Membership grants visibility only (not editing), and only inside Projects' own pages.
