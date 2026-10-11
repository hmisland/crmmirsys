# Phase 5 · Step 2 — Contracts module: final report (2.11)

Date: 2026-10-08. Status: **Step 2 complete; stopped before Projects.**
This step changed no code.
Detailed history: `docs/contracts-module-design.md` (design, decisions, per-sub-step notes), `CHANGELOG.md`, and `docs/module-extension-points.md`.

---

## 1. What was delivered

An independently sellable **Contracts** module (`modules/Contracts`, key `contracts`, type `addon`, `dependencies: []`). It runs with Core alone and integrates with Sales only through Core extension points.

| Sub-step | Delivered |
|---|---|
| 2.1 | Audit of Step 1 + design; gap decisions P1/P2+P3/G2 |
| 2.2 | Module skeleton, tables `contracts`, `contract_categories`; `contract` record type; `RecordType` finder + `RecordTypeRegistry::find()` |
| 2.3 | `Permission::registerModuleKeys()`; 9 `contracts.*` keys; default grants (module migration) |
| 2.4 | `ContractService` (RBAC, record scope, validation, lifecycle); Core `number_series`; events `ContractActivated` / `ContractTerminated` |
| 2.5 | Pages: list, show, create/edit, categories; 21 routes; sidebar; shared tooltip-overflow fix |
| 2.6 | "Contracts" panels on contact and company pages |
| 2.7 | Sales registers `deal` type; `contract_links`; Deal → Contract via EventBus (subscribed by class-name string); deal panel |
| 2.8 | Manual "Create contract" from a deal (prefill + server-checked link) |
| 2.9 | Organization setting: auto-create a draft contract when a deal is won (off by default, one per deal) |
| 2.10 | Full test matrix (190 tests) |

**Functional summary:**

- **Lifecycle:** draft → pending approval → active (numbered `CTR-0001`…, signed date) → terminated. Cancel is allowed before activation. Renew creates a linked draft whose dates follow on. Expiry is computed on read; there is no scheduler.
- **Contract data:** parties (contact and/or company), category, dates, exact amount + currency, auto-renew flag, renewal notice, description, terms.
- **Core features on contracts:** notes, tags, attachments and timeline, all from Core.
- **Panels:** contracts on contact, company and deal pages, plus a related-panel slot on the contract page for future modules.
- **Translations:** all five languages; Jalali dates in Persian; RTL and LTR verified.

## 2. Independence — the final architectural check

| Principle | Status | Evidence |
|---|---|---|
| Contracts installs and runs with Core only | ✅ | `StandaloneContractsTest`: a temporary copy with every other module physically absent, run in a separate process |
| Contracts imports nothing from Sales (or any module) | ✅ | Architecture test scans all module code (comments excluded) for other modules' namespaces and tables |
| Only allowed mention of Sales | ✅ | Exactly one string literal, the event class name, once, in `ContractsModule.php`, used for an `EventBus` subscription. A test checks that it still names the real class |
| Sales does not depend on Contracts | ✅ | Sales' only change registers its own `deal` record type; it knows nothing about Contracts |
| Integrations are active only when both modules are | ✅ | Module-state matrix: global off, organization off, user restricted, Sales off |
| Data survives disable/enable | ✅ | End-to-end test E9/E10 |

## 3. Every Core change (Step 1 + Step 2)

All backward compatible; existing modules unchanged.

| Area | Change | Step |
|---|---|---|
| Extension points | `ProvidesServices`, `ModuleServiceRegistrar`, `ModuleGate`, `RecordType`/`RecordTypeRegistry`, `RelatedPanel`/`RelatedPanelRegistry`, `partials/related-panels.php` (slot on contact/company/lead/deal pages) | 1 |
| `RecordSupportController` | Hard-coded type list → registry (+ view permission); honours `recordSupport` | 1, 2.7 |
| `RecordType` | Optional `finder`, optional `recordSupport` flag | 2.2, 2.7 |
| `RecordTypeRegistry::find()` | Cross-module record lookup with module, permission, organization and record-scope checks | 2.2 |
| `ModuleGate::allows()` | Optional record for own/team/department scope | 2.2 |
| `Permission` | `registerModuleKeys()`; Core list unchanged (moved to private `coreKeys()`) | 2.3 |
| Numbering | Core migration `0076_create_number_series_table` + `NumberSeriesRepository` | 2.4 |
| `layout.php` | Sidebar group "Contracts" behind the module flag (project convention) | 2.5 |
| `app.css` | Hidden tooltips no longer widen tables (affected every list with disabled row actions) | 2.5 |
| `SalesModule::register()` | Registers the `deal` record type (`recordSupport: false`) | 2.7 |

## 4. Database

| Migration | Creates |
|---|---|
| Core `0076_create_number_series_table` | `number_series` |
| `contracts/0001` | `contract_categories` |
| `contracts/0002` | `contracts`. Foreign keys only to Core tables (organizations, users, teams, departments, contacts, companies, currencies) and its own tables |
| `contracts/0003` | Default grants: org_admin all 9 keys; member view/create (organization) + edit (own) |
| `contracts/0004` | `contract_links` (no foreign key outside the module; label snapshot) |
| `contracts/0005` | `contract_settings` |

Core `0075` (lead description, from earlier work) is also pending on the server if it hasn't run yet. Every migration has a `down()`. Each was round-trip tested (down → up) on `crm_local`.

## 5. Quality

- **Tests:** `php tests/lite/run.php` → 190 tests, 0 failed, 0 skipped, about 4 s. Every DB test runs on `crm_local` only, inside a rolled-back transaction. Real data was compared before and after a run and was identical.
- **Mutation checks:** each breaks one safeguard on purpose, then restores it. The suites caught: tenant scope removed, a route permission weakened, the module gate removed from a panel, the "one per deal" guard removed, the listener's entitlement check removed, the server re-check of a deal removed, and a translation key deleted.
- **Real bugs found by the tests and fixed during Step 2:**
  - the edit form opened for contracts the user couldn't save;
  - a hard-coded Sales URL inside Contracts;
  - the shared tooltip overflow.
- **Size:** about 2,850 lines in the module and its templates; 104 translation keys in each of 5 languages. `contracts.number` is unused since the list columns were merged; harmless.

## 6. Deliberately not done (out of Step 2 scope)

| Item | Note |
|---|---|
| Obligations / payment schedule, invoices from a contract | Planned for the Contract + Finance integration |
| Projects module | Phase 5 Step 3, not started |
| Follow-ups on contracts | Record types now make this possible; the follow-up form still hard-codes contact/lead |
| Expiry / renewal reminders | No scheduler (decision D5); expiry is computed on read |
| Amendments UI | `relation = amendment` exists in the schema only |
| Reassigning a contract's owner | Owner = creator (or the deal owner on auto-create); no "assign" action yet |
| Configurable number prefix | Fixed `CTR-` per organization sequence |
| Contract PDF / print, templates | Not requested yet |
| REST API | Out of scope (as in the brief) |

## 7. Known limitations / pre-existing observations

1. `ContractService` doesn't check module entitlement itself, consistent with every existing service. Routes are gated by `ModuleMiddleware`, and other modules must check `ModuleGate` first.
2. **(Pre-existing, Core)** `ModuleAccessResolver` decides from DB rows only, and Sync never retires a module whose code was removed: stale sidebar link → 404. Fresh installs are unaffected.
3. **(Pre-existing, Core)** `RecordSupportController` doesn't verify that a note or attachment belongs to the `{type}/{id}` in the URL before deleting it. No cross-tenant effect (tested). **Resolved 2026-10-09** (`docs/record-support-ownership-design.md`); the same audit also found and fixed a cross-tenant tag issue.
4. **(Pre-existing, Core)** The role editor lists every module's keys, even for modules the organization isn't entitled to. No effect.
5. **(Pre-existing, data model)** Deals keep a 3-letter `currency_code` while Core/Finance use `currency_id`. Contracts bridges this by code when prefilling from a deal.

## 8. Deploy (one time, everything in `upload.zip`)

1. Back up the database and files.
2. Upload and extract `upload.zip`. It contains no `.env`, tests or `ddddd/`.
3. Run `/system/migrate?token=…`: Core `0075` (if pending) and `0076`; Contracts `0001`–`0005`.
4. Super Admin: `/admin/modules` → **Sync**.
5. Super Admin: enable Contracts for each organization that bought it.
6. Optional, per organization: `/contracts/settings` → auto-create on won deals.

Smoke check after deploy:

- the sidebar shows "Contracts";
- create and activate a contract → `CTR-0001`;
- the panel appears on contact, company and deal pages;
- "New contract" on a deal → linked;
- an organization without Contracts sees nothing of it.

## 9. Decisions before Projects (Step 3)

1. **Deploy and verify Step 2 in production first?** Recommended, so Projects builds on a confirmed base.
2. **Fix pre-existing finding 7.3 (note/attachment ownership check) now?** It's a small Core hardening, separate from Projects.
3. **Contract ↔ Project integration shape:**
   - reuse `contract_links` with `linked_type = 'project'`, and a `project_links` table on the Projects side;
   - "Create project from contract" via the contract page's related-panel slot;
   - optional reaction to `ContractActivated`.
4. **Sidebar:** keep a separate "Projects" group, or rename the group to "Contracts & Projects"?
5. **Follow-ups:** should the follow-up form switch to `RecordTypeRegistry` (contract/project selectable) as part of Step 3, or separately?
6. **Confirm D1:** Projects owns its own tasks (no dependency on Sales tasks).
