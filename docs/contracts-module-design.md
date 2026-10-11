# Contracts module: Step 2.1 audit and design

Status: **design approved (2026-10-08).** Gap decisions:

- **P1**: module-registered permission keys.
- **P2 + P3**: optional `finder` on `RecordType`, and Sales registers its own `deal` type.
- **G2**: EventBus subscription by class-name string.

Progress:

- **2.2 done.** Module skeleton (`modules/Contracts`), migrations `contracts/0001` (`contract_categories`) and `0002` (`contracts`), the `contract` record type with a finder, and P2 (`RecordType` finder + `RecordTypeRegistry::find()`).
- **2.3 done.** P1: `Permission::registerModuleKeys()`; `Modules\Contracts\ContractPermission` (9 keys), registered from `ContractsModule::register()`; default grants in module migration `contracts/0003`:
  - org_admin: all 9 keys, organization scope;
  - member: view and create at organization scope, edit own.
- **2.4 done.**
  - `ContractRepository`: search with explicit organization scoping and computed-expiry filters; create, update, delete.
  - `ContractCategoryRepository`.
  - `ContractService`: record-level RBAC, list scope mirrored in SQL, validation errors as translation keys, and the lifecycle: submit, return to draft, approve, activate, cancel, terminate, renew, plus `allowedActions()`.
  - Events `ContractActivated` / `ContractTerminated`.
  - Exposed via `ProvidesServices`.
  - Core: `number_series` (migration 0076) + `NumberSeriesRepository::next()`, giving `CTR-0001`, … per organization.
- The service does not check module entitlement itself (same as every existing service). Routes are gated by `ModuleMiddleware`, and other modules must check `ModuleGate` first.
- **2.5 done.**
  - Pages: list (search, status including computed expired, category, ending within 30 days, mine; paginated), show (facts, lifecycle actions limited to `allowedActions()`, terms, renewal history, the related-panel slot for `contract`, Core notes/tags/files/timeline), create/edit form, categories page.
  - 18 routes under `/contracts`, all behind `AuthMiddleware` + `ModuleMiddleware('contracts')` + `RbacMiddleware` (+ CSRF on POST). The edit form is refused unless the user may actually save it.
  - Sidebar group "Contracts" behind the module flag; translations in all 5 languages.
- **2.6 done.** `Modules\Contracts\ContractPanels` registers "Contracts" panels for the Core subject types `contact` and `company` through `RelatedPanelRegistry`:
  - module `contracts`, permission `contracts.view`;
  - rows via `ContractService::list()`, so own/team/department scope applies; newest 5;
  - "New contract" button pre-selects the party (only with `contracts.create`);
  - "All N contracts" opens `/contracts?contact=` / `?company=`.
  - No Core or other-module file changed.
- **2.7 done.**
  - **P3:** `SalesModule::register()` registers the `deal` record type (finder, `deals.view`, `recordSupport: false`). This is the only Sales change.
  - **Links:** module migration `contracts/0004` (`contract_links`, no foreign key outside the module), `ContractLinkRepository`, and `ContractService::link()` / `unlink()` / `links()` / `linkedTo()`. Every target is validated through `RecordTypeRegistry::find()`.
  - **Listener:** `Listeners\DealStageChangedListener`, subscribed by the class-name string `ContractsModule::DEAL_STAGE_CHANGED_EVENT`. It writes "Linked deal won / lost / moved to stage …" on every linked contract's timeline, only when Contracts is active and entitled for the event's organization.
  - **Panels and pages:** a deal-page panel lists the linked contracts; the contract page lists its linked records with unlink.
  - **Architecture test:** allows exactly that one string, once, in `ContractsModule.php`.
- **2.8 done.** The deal panel has "New contract" (`contracts.create`) → `/contracts/create?from_deal=ID`.
  - `ContractService::draftFromDeal()` builds the prefill, reading the deal only via `RecordTypeRegistry::find()`: title = deal name, contact, company, amount, and currency mapped from the deal's 3-letter code to an active Core currency. It returns null → 404 when the deal isn't available.
  - On save, `createLinked()` re-checks the source on the server and creates the contract and the link atomically. A tampered source returns 422 and creates nothing.
  - The "created from" notice and the deal URL (Sales' own `showUrl()`) are re-derived on the server, never taken from the form.
- **2.9 done.**
  - **Setting:** module migration `contracts/0005` (`contract_settings`, one row per organization, absent = off), `/contracts/settings` (`contracts.manage_settings`) with "Create a draft contract automatically when a deal is won", off by default.
  - **Auto-create:** `DealStageChangedListener` calls `ContractService::autoCreateFromWonDeal()` on won, inside the event's organization. It runs only with the setting on and Contracts entitled. The deal is read as the person who won it, through `RecordTypeRegistry::find()`.
  - **One contract per deal:** nothing is created if any contract is already linked, so there's never a second one on re-win.
  - **The draft:** prefilled from the deal (an invalid field degrades to a title-only draft); owner = the deal's owner if they are an active user of the organization, else the winner. Contract and link are created atomically, with a timeline entry.
- **2.10 done: full test matrix.** `php tests/lite/run.php` reports 190 tests, 0 failed, 0 skipped, about 4 s. Every suite runs on `crm_local` only, inside a rolled-back transaction.

  | Suite | Covers |
  |---|---|
  | `StandaloneContractsTest` (A) | a temporary copy of the app whose `modules/` holds only Contracts (Sales and all other modules physically absent), run in a separate PHP process: CRUD, full lifecycle, Core notes, contact panel, deal features degrade, old deal links shown as text, Core pages still work |
  | `ContractMatrixTest` (B, C, D) | RBAC: all 21 routes × {org_admin, member, user without a role}, plus own-draft and state rules, plus CSRF on every POST. Module states: global off, organization off, user restricted, re-enabled with data intact; Sales off. Tenant isolation: every id route with another organization's contract, category or link → 404 and nothing changed there; lists, search, lookups and the Core `/records` routes |
  | `ContractEndToEndTest` (E) | one story in order: setting on → deal won (real Sales `DealService`) → auto draft → member view-only → admin completes and submits → approve with number → note and tag → contact and deal panels → renew + terminate → module off (hidden, data kept) → on again (restored) |
  | `ContractTranslationsTest` (F) | all 99 keys present in fa/ar/tr/en/fr with matching placeholders; every page and panel, plus the 422 form, renders in all five languages with the right direction and no raw key |

  Mutation checks (each a deliberate break, then restored) confirmed that the suites catch: tenant scope removed from `findById`, a weakened route permission, the "one per deal" guard removed, the listener's entitlement check removed, the server-side deal re-check removed, and a translation key deleted.

- **Pre-existing Core observations** (not caused by Contracts, left unchanged):
  1. `ModuleAccessResolver` decides from the `modules` table only. Sync adds modules but never retires one whose code was removed from disk, so its sidebar link stays and its routes 404. A fresh install without a module is unaffected.
  2. `RecordSupportController` doesn't check that a note or attachment id belongs to the `{type}/{id}` in the URL before deleting, and adding a note to an id from another organization stores an orphan note in the caller's own organization. Nothing leaks across tenants (suite D checks this). **Resolved 2026-10-09**, see `docs/record-support-ownership-design.md`.
  3. The role editor lists every module's keys, including modules the organization isn't entitled to; holding such a key has no effect.

- **2.11 done.** Final report: `docs/contracts-step2-report.md`. **Step 2 complete; stopped before Projects.**
Projects is out of scope until Contracts is delivered and tested.

Earlier decisions in force:

- Project tasks are independent (D1).
- Numbering uses a generic Core number series (D2).
- The three Core registries are used (D3).
- Deal won → manual button plus an optional organization setting, off by default (D4).
- Expiry is computed on read; no cron (D5).

---

## 1. Audit of the Step 1 extension points (what Contract can rely on)

| Need | Existing mechanism | Status |
|---|---|---|
| Module on/off, sold per org | `module.json` + `ModuleAccessResolver` + `ModuleMiddleware('contracts')` | ✅ use as is |
| Expose services | `ProvidesServices` → `ModuleServiceRegistrar` | ✅ use as is |
| Notes, tags, attachments, timeline on a contract | `RecordTypeRegistry` + `/records/{type}/{id}` | ✅ register type `contract` |
| Contracts box on deal, contact and company pages | `RelatedPanelRegistry`. Slots already on contact, company, lead and deal pages. The host passes its own row, e.g. the deal with `contact_id`, `company_id`, `value`, `currency_code` | ✅ register panels |
| Access checks for the above | `ModuleGate` (resolver + `AuthorizationEngine`) | ✅ |
| Cross-module reaction | `EventBus::listen(string $eventClass, ListenerInterface)` | ✅ subscription is by class-name **string** (see G2) |
| Currencies, contacts, companies | Core tables and repositories | ✅ no other module involved |
| Module migrations | `modules/Contracts/Migrations`, run by `/system/migrate` | ✅ |
| Templates, translations, sidebar | `themes/default/templates/contracts/*`, `lang/*.php` (all 5), `$navGroups` in `layout.php` | ⚠️ the project's existing convention (CLAUDE.md); no extension point exists for these, and none is proposed |

### Gaps found

**G1. Permissions have no extension point.**

- `Permission::all()` is a fixed Core list.
- It is used only by the role editor (`RoleController`, lists the keys) and by `RoleService` (`Permission::exists()` when saving a role).
- Enforcement (`AuthorizationEngine`) works for any key present in `role_permissions`.
- So Contract's keys must enter that list somehow. Today the only way is editing `Permission.php`.

**G2. Deal → Contract through EventBus without importing Sales.**

- The only deal event is `Modules\Sales\Events\DealStageChanged` (`organizationId`, `dealId`, `isWon`, `actorUserId`).
- Contract can subscribe with the class name as a plain string. There is no `use` statement, and nothing loads unless Sales is installed.
- The listener then reads the event's public properties. That is a soft contract on the event's shape, guarded by tests.
- The event carries no contact, company or value, so G3 is needed as well.

**G3. Contract cannot look up a deal.**

- To validate "create contract from deal #12" (same organization, user may view it), Contract needs to read deal #12.
- To prefill from a won deal (event path), Contract needs the deal's contact, company and value.
- `RecordTypeRegistry` knows only *kinds* of records, not how to fetch one.
- Sales does not register a `deal` type yet.

## 2. Proposed resolution of the gaps (smallest, backward compatible, no redesign)

**P1. Module permission keys.**

- Add a static `Permission::registerModuleKeys(string $module, array $keys)`.
- `all()` and `exists()` include the registered keys.
- Contract calls it from its own `register()`.
- Grants (org_admin and member defaults) go in Contract's own migration.
- Nothing in Step 1 changes; existing keys and constants stay.
- *Alternative:* add constants to `Permission.php`, the current convention. This is a Core edit that names the module.

**P2. Optional finder on `RecordType`.**

- Add a new optional constructor argument `?Closure $finder` (`fn (int $id): ?array`, tenant-scoped through the owner's repository).
- Add `RecordTypeRegistry::find(string $type, int $id, ?array $user): ?array`. It returns the row only if `canView()` passes.
- Existing registrations and tests are unaffected.

**P3. Sales registers its own `deal` record type.**

- One `register()` call inside `SalesModule::register()`, with finder `DealRepository::findById` (tenant-scoped) and show path `/deals/{id}`.
- This is the only Sales change. Sales gains nothing about Contracts, and Contract never imports Sales.
- Contract asks `find('deal', …)` and links with `get('deal')->showUrl()`.
- When Sales is disabled or not installed, `find()` returns `null`. Contract hides all deal features and keeps working.

With P2 and P3, Contract's EventBus listener (G2) fetches the deal through the registry rather than any Sales class.

## 3. Contract module design

### Manifest

```json
{ "key": "contracts", "name": "Contracts", "type": "addon", "is_core": false,
  "dependencies": [], "bootstrap": "Modules\\Contracts\\ContractsModule" }
```

### Tables (module migrations)

```
contracts            id, organization_id, contract_number NULL (set on activation),
                     title, category_id NULL, contact_id NULL → contacts, company_id NULL → companies,
                     owner_id → users, team_id NULL, department_id NULL,
                     status ENUM(draft, pending_approval, active, expired, terminated, cancelled),
                     start_date, end_date NULL, signed_at NULL, terminated_at NULL,
                     value DECIMAL(38,18) NULL, currency_id NULL → currencies,
                     auto_renew, renewal_notice_days NULL,
                     parent_contract_id NULL → contracts, relation ENUM(original, renewal, amendment),
                     description TEXT, terms TEXT, created_by_user_id, timestamps
contract_categories  id, organization_id, name
contract_links       id, organization_id, contract_id, linked_type VARCHAR(50), linked_id,
                     linked_label VARCHAR(255) (snapshot), created_by_user_id, created_at
                     UNIQUE(contract_id, linked_type, linked_id)   — no FK to other modules' tables
contract_settings    organization_id PK, auto_create_on_deal_won TINYINT DEFAULT 0
```

- Numbering uses the approved generic Core `number_series` (org + document type `contract`, prefix `CTR-`), built in step 4.
- Obligations and payment schedule (Finance link) are not part of this Step 2. They come with the Finance integration later.

### Lifecycle

- `draft`/`pending_approval` are editable.
- **activate** assigns the number and `signed_at`, and fires `Modules\Contracts\Events\ContractActivated`.
- **terminate** sets `terminated_at` and fires `ContractTerminated`.
- **cancel** applies only before activation.
- **renew** creates a new draft (`parent_contract_id`, `relation = renewal`).
- **expired** is computed when `end_date < today`, shown as a badge and filter. No cron.

### Permissions (`contracts.*`)

`view`, `create`, `edit`, `delete`, `approve`, `activate`, `terminate`, `renew`, `manage_settings`.

| Role | Granted |
|---|---|
| member | view (organization), create (organization), edit (own) |
| org_admin | all |

Record scope uses `owner_id`/`team_id`/`department_id`, the same as Finance documents.

### Registrations in `ContractsModule::register()`

- RecordType `contract` (module `contracts`, `contracts.view`, `/contracts/{id}`, finder = own repository).
- Panels:
  - `deal` → "Contracts" (list plus a "Create contract" button that prefills from the `$deal` row passed by the host page).
  - `contact` and `company` → "Contracts" list.
- `ProvidesServices` → `ContractService`.
- EventBus listener on the string `'Modules\Sales\Events\DealStageChanged'`. Runs only when `isWon` is true, Contracts is available for the organization, and `auto_create_on_deal_won = 1`. Creates a draft linked to the deal, at most once per deal.
- Routes under `ModuleMiddleware('contracts')` + `RbacMiddleware`.

### Independence check

- With Core only: list, create, edit, show, lifecycle, notes, tags, files, timeline, contact/company panels all work.
- With Sales off:
  - `find('deal')` returns `null`.
  - The deal panel never renders, because the deal page doesn't exist.
  - The listener never fires.
  - Existing deal links show their snapshot label without a link.

## 4. Sub-step plan (each one delivered, tested and approved before the next)

| # | Sub-step | Contents |
|---|---|---|
| 2.1 | Audit and design | this document |
| 2.2 | Record type | module skeleton (`module.json`, `ContractsModule`), migrations for `contracts` and `contract_categories`, type `contract` registered (+ P2 finder) |
| 2.3 | Permissions | P1 + grant migration + tests |
| 2.4 | Service/Repository | `ContractRepository`, `ContractService` (RBAC, tenant, lifecycle), Core `number_series`, `ProvidesServices` |
| 2.5 | Pages | list, show, create, edit, categories settings; sidebar; translations in 5 languages |
| 2.6 | Related panels | contact and company panels now; deal panel ready, shown once 2.7's `deal` type exists |
| 2.7 | Deal → Contract via EventBus | P3 (Sales registers `deal`), `contract_links`, listener |
| 2.8 | Manual "Create contract" | button in the deal panel, prefill and validation through `find('deal')` |
| 2.9 | Auto-create setting | `contract_settings` + settings page + listener honours it |
| 2.10 | Full tests | Core only, Contracts on/off, Sales on/off, RBAC, tenant isolation, module disable/enable |
| 2.11 | Stop and report | before any Projects work |
