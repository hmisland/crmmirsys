# Contracts & Projects modules — audit, gap analysis and design

Status: **Phases 1–4 complete, awaiting approval.** No code has been changed.
Phase 5 (implementation) starts only after the decisions in §6 are made.

---

## 1. Phase 1 — Audit of the existing system

### 1.1 Module framework (already exists — reuse, do not rebuild)

| Piece | Where | What it does |
|---|---|---|
| Manifest | `modules/<Name>/module.json` | `key`, `type` (core/optional/addon), `dependencies`, `bootstrap` class |
| Discovery | `ModuleRegistry` | Scans `modules/*/module.json` |
| Bootstrap | `ModuleInterface` → `register()`, `routes()`, `migrationsPath()` | Called by `Application::bootstrapModuleRoutes()` for every installed module |
| Entitlement chain | `ModuleAccessResolver` | installed → globally active → **organization entitled** → per-user override |
| Request gate | `ModuleMiddleware('key')` | 404s (never 403) when the chain fails |
| Commercial control | `/admin/organizations/{id}/modules` (Super Admin) | Entitles a module to an org. Org Admin can only *restrict* members (`/org/modules`) — cannot self-enable. **This is what makes a module sellable today.** |
| Module migrations | `/system/migrate` | Runs each module's `Migrations/` independently, tracked in `module_migrations` |

Existing modules (all `dependencies: []` except Notification → Wallet):
Sales (deals, pipelines, tasks), Finance (products, proformas, invoices, payments, credit notes, tax, periods), Followups, Appointments, ClientRecords, Support, InternalMail, Knowledge, Notification, Wallet, Demo.

**Contracts and Projects do not exist anywhere** (no tables, routes, permissions, or UI). Nothing to de-duplicate on the entity level.

### 1.2 Core entities and relationships

- Core CRM: `contacts`, `companies` (contact → company FK), `leads` (free-text company, converts to contact/company).
- Shared polymorphic services (core, keyed by `type` + `id`): **Notes, Tags, Attachments, Timeline, Custom fields**.
- `currencies` is a **core** table (migration 0034) — usable by any module without Finance.
- Deals: `contact_id`, `company_id`, `value DECIMAL(19,4)`, `currency_code VARCHAR(3)`, `status open/won/lost`.
- Invoices / proformas: `contact_id` only (no company, no deal, no source link), `currency_id` FK, `DECIMAL(38,18)` money.
- Sales and Finance are **not coupled** to each other (no deal → invoice link).

### 1.3 RBAC

- Code-defined catalogue in `Permission.php` (`Permission::all()`), scopes `own/team/department/organization/all`.
- Record checks via `AuthorizationEngine::can($user, $key, $record)` using `owner_id`/`team_id`/`department_id`.
- System roles (`org_admin`, `member`) are **global** rows (`organization_id IS NULL`), so one grant migration covers every org.
- Grants today live in core migrations (e.g. `0059_grant_followups_permissions.php`).

### 1.4 Events

- `EventBus`: synchronous, in-process; listener failures are logged and swallowed. Documented as *the only sanctioned way modules react to each other*.
- Only one domain event exists: `Modules\Sales\Events\DealStageChanged` (`isWon`, `isLost`, `dealId`, `organizationId`). Followups' rule engine listens to it.

### 1.5 Optional-integration pattern (reuse)

`Modules\ClientRecords\Services\ClientIntegrations` is the reference pattern. It calls another module only when that module is:

1. installed (its service is in the container),
2. entitled for the user (`ModuleAccessResolver`), and
3. the user holds that module's permission.

### 1.6 Tenant isolation

- `TenantScopedRepository` + `OrganizationContext` auto-scope `tenantFind*`.
- Known caveat (documented in Finance/Followups repos): queries with correlated subqueries must scope `organization_id` explicitly.
- `SuperAdminContext::run()` is the only cross-org bypass.

### 1.7 Localization / UI

- 5 language files (`en` fallback; `fa`/`ar` RTL).
- Logical CSS only.
- Sidebar `$navGroups` in `layout.php`, gated by module flags.
- Icons only from `partials/icons.php`.

### 1.8 APIs, numbering, scheduling, tests

- REST API: only auth (`AuthApiController`) and ClientRecords. No general CRM API.
- Document numbering: concurrency-safe series exists **only inside Finance** (`InvoiceNumberSeriesRepository`).
- No cron/scheduler. Follow-up reminders are a data model only; nothing fires them on a timer.
- Tests: a few unit tests and an empty `tests/Feature`. **PHPUnit is not installed** in `vendor/`.

---

## 2. Phase 2 — Where Contract and Project belong

Both are **new top-level modules**, type `addon` (sellable), `dependencies: []`:

```
modules/Contracts/  key "contracts"   module.json dependencies: []
modules/Projects/   key "projects"    module.json dependencies: []
```

Each module must run with **only Core** enabled:

- **Contract** needs: contacts/companies (core), currencies (core), notes/tags/attachments/timeline (core).
- **Project** needs: contacts/companies (core), users/teams (core), its own tasks/milestones.

Neither module references another module's classes in its constructor or migrations:

- No foreign keys to `deals`, `invoices`, `proformas`, `projects` or `contracts` across module boundaries. A module's tables may not exist on an install, and an FK would force install order.
- Cross-module links are stored as `(linked_type, linked_id)` rows owned by the module that holds the link. They are validated at write time through the other module's service, and only when that module is available.

Optional integrations each live in one class per module, following the ClientIntegrations pattern:

- `Modules\Contracts\Services\ContractIntegrations` (Sales, Finance, Projects, Followups)
- `Modules\Projects\Services\ProjectIntegrations` (Sales, Contracts, Followups)

Automatic reactions use the `EventBus` only.

---

## 3. Phase 3 — Gap analysis

### 3.1 Existing capabilities to reuse (do not recreate)

- Module entitlement and sale-per-org: `ModuleAccessResolver`, Super Admin entitlement UI.
- RBAC catalogue and scopes, grant migrations.
- Notes, tags, attachments (PDF/DOCX allowed), timeline, custom fields — all polymorphic, all core.
- Currencies (core); Finance tax/line-item engine (through `ProformaService`/`InvoiceService` only).
- Follow-ups for reminders/next actions (`related_type`/`related_id`).
- `DealStageChanged(isWon)` as the trigger for "deal won → contract / project".
- Pagination, bulk actions, row-actions partial, datepicker, tag input, design tokens.

### 3.2 Missing capabilities (to build)

| Area | Missing |
|---|---|
| Contracts | entity, lifecycle (draft → active → expired/terminated, renewal/amendment chain), parties, dates, value, obligations/payment schedule, numbering, categories |
| Projects | entity, members, milestones, project tasks, progress, status |
| Events | `ContractActivated`, `ContractTerminated`, `ContractRenewed`, `ProjectCreated`, `ProjectStatusChanged`, `ProjectCompleted` |
| Scheduling | no timer, so expiry reminders cannot fire. MVP: compute "expiring/expired" on read; later: tokened cron endpoint |
| API | no REST endpoints (deferred unless requested) |

### 3.3 Duplicated or at-risk capabilities

| Risk | Recommendation |
|---|---|
| **Tasks.** Sales owns `tasks` (generic, `related_type`). Projects need tasks but must not require Sales. | Decision **D1**, see §6 |
| **Numbering.** Finance has the only number series. Contracts need `CTR-0001`. | Decision **D2**, see §6 |
| Notes/tags/attachments routes: `RecordSupportController::ALLOWED_TYPES = ['contact','company','lead']` is hard-coded. | Make the allow-list registrable (D3) instead of copying the controller into each module |

### 3.4 Incorrect dependencies and coupling problems (found in current code)

1. `Application::bootstrapModuleRoutes()` has an `instanceof` block per module (Wallet, Sales, Finance, Followups, …) to expose services. Every new module means editing core.
2. `ContactLinkRepository::SOURCES` and `ContactController::RELATED_SOURCES` hard-code module tables (invoices, deals, follow_ups, …) inside core.
3. `layout.php` hard-codes one `ModuleAccessResolver` call and nav block per module.
4. Module permission keys are listed in core `Permission::all()`, and their grants live in core migrations.
5. `FollowUpController` related types are hard-coded to contact/lead (added last week).
6. `FollowUpService::validatedFields()` accepts any `related_type` string unvalidated. Only the controller checks it.

### 3.5 Data-model problems (existing)

1. **Currency inconsistency**: deals use `currency_code` string (default `USD`) and `DECIMAL(19,4)`. Finance uses the `currency_id` FK and `DECIMAL(38,18)`. New modules should use `currency_id` and `DECIMAL(38,18)`.
2. Invoices/proformas have **no `company_id`**. A contract with a company party but no contact cannot be invoiced cleanly; contract → invoice must pick a contact.
3. No deal → invoice link; there is no Sales↔Finance trace today.
4. `follow_ups.timezone` defaults to a hard-coded `Asia/Tehran`.
5. `lang/fr.php` is ~110 keys behind `en` (falls back to English).

---

## 4. Phase 4 — Architecture design

### 4.1 Contracts module (`modules/Contracts`, key `contracts`)

**Tables (module migrations):**

```
contracts
  id, organization_id, contract_number (NULL until activated), title,
  category_id NULL, contact_id NULL → contacts, company_id NULL → companies,
  owner_id → users, team_id NULL, department_id NULL,
  status ENUM(draft, pending_approval, active, expired, terminated, cancelled),
  start_date, end_date NULL, signed_at NULL, terminated_at NULL,
  value DECIMAL(38,18) NULL, currency_id NULL → currencies,
  auto_renew TINYINT, renewal_notice_days INT NULL,
  parent_contract_id NULL → contracts   (renewal / amendment chain)
  relation ENUM(original, renewal, amendment),
  description TEXT, terms TEXT,
  created_by_user_id, created_at, updated_at
contract_categories          (id, organization_id, name)
contract_obligations         (id, contract_id, kind ENUM(payment, deliverable, other),
                              title, due_date, amount DECIMAL(38,18) NULL,
                              status ENUM(pending, done, cancelled), done_at)
contract_number_series       (org-scoped; see D2)
contract_links               (id, organization_id, contract_id,
                              linked_type ENUM(deal, proforma, invoice, project),
                              linked_id, created_by_user_id, created_at)
```

**Lifecycle rules:**

- Only `draft`/`pending_approval` contracts are freely editable.
- Activating assigns the number, sets `signed_at` and fires `ContractActivated`.
- `expired` is computed when `end_date < today` (and persisted on read or edit). There is no scheduler.
- Renew: creates a new draft with `parent_contract_id` and `relation = renewal`.
- Terminate: sets `terminated_at` and fires `ContractTerminated`.

**Permissions:**

- `contracts.view`, `create`, `edit`, `delete`, `approve`, `activate`, `terminate`, `renew`, `export`, `manage_settings`
- Member defaults: view/create at org scope; edit at own scope. Grants go in the module's own migration (see §4.4).

**UI:**

- Pages: list (filters status/expiring/mine), form, show (parties, dates, value, obligations, links, notes/tags/attachments, timeline).
- Settings page: categories and number prefix.
- Sidebar group "Contracts & Projects".

### 4.2 Projects module (`modules/Projects`, key `projects`)

**Tables:**

```
projects
  id, organization_id, code NULL, name, contact_id NULL, company_id NULL,
  owner_id (manager) → users, team_id NULL, department_id NULL,
  status ENUM(planned, active, on_hold, completed, cancelled),
  start_date NULL, due_date NULL, completed_at NULL,
  budget DECIMAL(38,18) NULL, currency_id NULL → currencies,
  description TEXT, created_by_user_id, created_at, updated_at
project_members     (project_id, user_id, role ENUM(manager, member, viewer))
project_milestones  (id, project_id, title, due_date, status, sort_order)
project_tasks       (id, organization_id, project_id, milestone_id NULL, title,
                     description, status ENUM(todo, in_progress, review, done),
                     priority, assignee_user_id NULL, due_at NULL, completed_at,
                     sort_order, created_by_user_id ...)      — see D1
project_links       (project_id, linked_type ENUM(deal, contract), linked_id)
```

**Behaviour:**

- Progress = done tasks / all tasks (or milestones), computed on read.
- Record access = RBAC scope **plus** project membership. A member sees the projects they belong to even at `own` scope.

**Permissions:**

- `projects.view`, `create`, `edit`, `delete`, `manage_members`, `manage_tasks`, `manage_settings`
- Task status changes: assignee or `manage_tasks`.

**UI:** list, form, show (overview, milestones, task list/board, members, links, notes/files/timeline).

### 4.3 Optional integrations (active only when both modules are enabled for the user)

| Pair | Behaviour | Mechanism |
|---|---|---|
| **Contract + Sales** | Contract form: "Source deal" picker. Deal won → optional org setting "create draft contract". Contract page shows the deal. | `ContractIntegrations` + listener on `DealStageChanged(isWon)`; `contract_links(deal)` |
| **Contract + Finance** | On a `payment` obligation: "Create proforma/invoice" (prefilled through `ProformaService`/`InvoiceService`). Contract shows billed / paid / balance by reading the linked documents. Finance stays unaware of contracts. | `contract_links(proforma/invoice)`; Finance services only |
| **Contract + Project** | "Create project from contract" (copies parties, dates, budget). Each side shows the other. | `contract_links(project)` + `project_links(contract)`; both through `*Integrations` |
| **Sales + Project** | Deal won → optional "create project". Manual "link deal" on a project. | Listener on `DealStageChanged`; `project_links(deal)` |
| **+ Followups** | Follow-ups can relate to `contract`/`project`; renewal-notice follow-up is created on activation when `renewal_notice_days` is set. | Extend related types (D3) |

Rules:

- Disabling a module hides its integration UI immediately: every call checks entitlement at request time.
- Link rows stay and reappear if the module is re-enabled.
- No data is deleted on disable.

### 4.4 Small core extension points (recommended, see D3)

These remove the coupling listed in §3.4 for the two new modules, without rewriting anything:

1. **`ProvidesServices`** — an optional interface a module implements. `Application` registers whatever it returns, replacing new `instanceof` blocks. Existing blocks can stay as they are.
2. **Record type registry** — a module registers `contract`/`project` with a view-permission check. Used by `RecordSupportController` (notes/tags/attachments) and by the follow-up form's related picker.
3. **Related-panel slot** — a module registers a panel for a subject type (`deal`, `contact`, `contract`, `project`). Show pages render the registered panels. This is how Contracts appears on a deal page without Sales knowing Contracts.

Permission constants stay in `Permission.php` (the project convention). Grants for the new modules go in each module's own `Migrations/`, so a module's install is self-contained.

---

## 5. Phase 5 — Implementation plan (after approval)

Each step ships as its own `upload.zip`, runs its migrations, and is tested before the next one starts.

1. **Core extension points** (§4.4) and a lightweight test runner (plain PHP integration scripts against the local `crm_local` DB, since PHPUnit is not installed).
2. **Contracts standalone**: migrations, permissions and grants, service and repositories, CRUD, lifecycle, numbering, categories, notes/tags/attachments, timeline, sidebar, translations in all 5 languages.
3. **Projects standalone**: migrations, permissions, CRUD, members, milestones, tasks, progress, sidebar, translations.
4. **Integrations**: Contract+Project → Contract+Sales → Sales+Project → Contract+Finance → Followups.
5. **Docs and CHANGELOG.**

## 6. Decisions needed before Phase 5

- **D1 — Project tasks.**
  - (a) *Recommended:* Projects owns `project_tasks`. Fully independent; "task" exists twice conceptually (project work items vs. Sales personal to-dos).
  - (b) Move Sales `tasks` into Core and reuse them. Less duplication, but a larger change to existing Sales code and data.
- **D2 — Numbering.**
  - (a) *Recommended:* extract a generic core `number_series` (keyed by org + document type) for Contracts/Projects only, and leave Finance's series untouched.
  - (b) Copy the Finance pattern into Contracts.
- **D3 — Core extension points (§4.4).**
  - (a) *Recommended:* add the three small registries.
  - (b) Follow the current hard-coded convention: edit `Application`, `layout`, `RecordSupportController` and `ContactController` for each module. Faster now, more coupling.
- **D4 — Deal-won automation.** Should "deal won" auto-create a draft contract and/or project (org setting, off by default), or offer a manual button only?
- **D5 — Expiry reminders.** Computed-on-read only (MVP), or also a cPanel cron URL that creates renewal follow-ups and notifications?

## 7. Phase 6 — Test matrix (to run after implementation)

| Scenario | Checks |
|---|---|
| Contract only (Core + Contracts) | CRUD, lifecycle, numbering, attachments; no Sales/Finance/Project UI anywhere |
| Project only | CRUD, members, tasks, progress; no Contract/Sales UI |
| Contract + Sales | deal picker, deal-won listener, panel on deal page |
| Contract + Project | create project from contract, two-way links |
| Contract + Finance | proforma/invoice from obligation, billed/paid totals |
| Sales + Project | deal-won → project, link deal |
| Full integrated | deal won → contract → project → invoice, all links and timelines |
| Enable/disable | disable each module: routes 404, panels and buttons disappear, links survive; re-enable restores them |
| RBAC | member vs org_admin, `own` scope, project membership visibility, user override restriction |
| Tenant isolation | org B cannot read/link org A's contracts/projects/links by id (direct URL and link-picker tampering) |
