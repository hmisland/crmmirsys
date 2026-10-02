# CRM Platform — Phase 0: Architecture Proposal

Status: **DRAFT — Rev. 3, decisions finalized, awaiting your sign-off to begin Phase 1**. No application code has been written. This is the same Phase 0 document carried forward from Rev. 1/Rev. 2 — all original decisions (module map, RBAC/scope model, multi-tenancy, wallet ledger design, folder structure, phase order) remain unchanged. Rev. 2 added centralized Currency Management, multi-currency, exchange rates, and crypto-readiness and left five points open for approval. Rev. 3 (this revision) resolves all five as recorded architectural decisions, integrated in place at each relevant section and summarized in *Final Architecture Decisions* below. Nothing here is a separate addendum.

---

## 1. System & Product Architecture (Summary)

A modular, multi-tenant, API-first CRM. Business logic lives in **Services**; **Controllers** (web) and **API Controllers** (REST) are thin adapters over the same Services; **Repositories** own all SQL. Every tenant-owned table carries `organization_id`, and every repository query is scoped by an `OrganizationContext` object resolved from the authenticated session — never from client input.

```
Request → Router → Middleware (Auth, Tenant, Module, RBAC) → Controller → Service → Repository → DB
                                                                      ↓
                                                              Event Bus → Listeners (other modules)
```

Two front doors (server-rendered PHP views today, REST API always) call the *same* Service layer, so no logic is duplicated between them — this is what makes the eventual React/FastAPI migration non-destructive.

---

## 2. Module Map & Classification

| Module | Type | Depends on |
|---|---|---|
| CRM Core (Org, User, Contacts, Companies, Leads, Tags, Notes, Attachments, Custom Fields, Timeline, Search, Notifications, Audit) | CORE | — |
| Auth & RBAC | CORE | Core |
| Module Manager | CORE | Core |
| Localization | CORE | Core |
| **Currency Management** (currencies, exchange rates, conversion policy) — *new, added per update* | CORE | Core |
| Sales (Deals/Pipelines/Activities/Tasks/Calendar) | OPTIONAL | Core |
| Support (Tickets) | OPTIONAL | Core, Contacts |
| Internal Mail | OPTIONAL | Core |
| Knowledge Management | OPTIONAL | Core |
| Products & Services | OPTIONAL | Core |
| Finance (Quotes/Orders/Invoices/Payments) | ADD-ON | Core, Products |
| Wallet | ADD-ON | Core |
| Marketing | ADD-ON | Core, Leads |
| Reporting | OPTIONAL | Core (reads only) |
| AI | ADD-ON | Core |
| AI Translation | ADD-ON | Localization |
| Billing/Plans/Entitlements | FUTURE | Core, Module Manager |

CRM Core stays deliberately thin: identity, relationship data, and cross-cutting infrastructure (search, tags, notes, attachments, custom fields, timeline, notifications, audit) that every other module reuses rather than reimplements.

> **UPDATE (Multi-Currency requirement):** Currency Management is classified **CORE**, not an optional/add-on module, because it is a *shared platform capability* consumed by Wallet, Finance, Sales, Reporting and any future Billing — none of those can function meaningfully without it, and per §35 of the update it must not be reimplemented per module. It is always installed and always enabled; there is nothing to toggle. **✅ DECISION (Rev. 3 — final):** confirmed non-togglable CORE. See *Final Architecture Decisions* §D1.

---

## 3–7. Module Registry, Dependencies, Activation, Entitlements, Overrides

**Resolution order enforced by middleware, in this exact sequence** (per master prompt §6):
`module installed → module enabled (global) → organization entitled → user override → role → permission → scope`. Every layer short-circuits to "denied"; none is skipped for convenience.

Schema (conceptual, refined in Phase 4):

```
modules(id, key, name, version, type[core|optional|addon|future], is_core,
        dependencies json, status[active|deprecated|disabled], installed_at, updated_at)

organization_modules(organization_id, module_id, enabled, status,
        enabled_at, disabled_at, metadata json)
        UNIQUE(organization_id, module_id)

user_module_overrides(organization_id, user_id, module_id, enabled,
        created_at, updated_at)
        UNIQUE(organization_id, user_id, module_id)

module_migrations(module_id, migration, applied_at)  -- per-module migration ledger
```

**Dependency enforcement:** disabling a module whose `key` appears in another *enabled* module's `dependencies` is blocked with a explicit error listing the dependents, unless the admin explicitly cascades (disables dependents first) — never silent cascade.

**Deactivation semantics:** disabling a module flips `organization_modules.enabled = false`. No DDL, no data deletion, no soft-delete cascades. Re-enabling restores access immediately. Only Module Manager code checks this flag; individual modules never need to know about their own activation state.

**Versioning:** each module ships `module.json` with `version` (semver) and its own migration files under its own folder, tracked independently in `module_migrations` so modules can be upgraded out of step with each other.

---

## 8–9. Safe Deactivation & Data Ownership

Already covered above; reinforced by §58 rule: modules never write directly into another module's tables. Cross-module effects go through **Services** (synchronous, e.g. Finance asking Wallet's `WalletService::credit()`) or **Events** (asynchronous/decoupled, e.g. `DealStageChanged` triggering a Reporting cache rebuild). Finance never touches `wallet_ledger` directly.

---

## 10–11. RBAC & Permission Scope Model

```
User → (Organization) → Role → Permissions[] (each permission has a Scope)
```

- Roles are **per-organization** (org admins can clone/customize system role templates into custom roles); Super Admin is a platform-level role outside any organization.
- Permissions are fixed, code-defined keys (`deals.view`, `wallet.transfer`, …) shipped by each module — the *permission catalogue* is not admin-editable, but *role→permission assignment* is.
- Scopes: `OWN | TEAM | DEPARTMENT | ORGANIZATION | ALL`, stored per role-permission pair (`role_permissions(role_id, permission_key, scope)`).
- A single reusable `AuthorizationEngine::can(user, permission, resourceOrNull)` is the only place scope logic is evaluated — no `if (role === 'admin')` anywhere else in the codebase. It queries the resource's owner/team/department against the caller's scope.

This is deliberately generic so any future module just registers new permission keys against the same engine — no authorization code duplication per module.

---

## 12–13. Multi-Tenancy & Organizations/Users

- `organizations`, `departments`, `teams`, `users` (with `organization_id`, `department_id?`, `team_id?`, `language`, `timezone`) as Core tables.
- **Tenant isolation is enforced in the Repository base class**: every tenant-scoped repository extends `TenantScopedRepository`, which injects `WHERE organization_id = :org_id` into every query automatically from the resolved `OrganizationContext` — a developer cannot forget it because there's no code path that queries a tenant table without going through this base class. Super Admin bypasses via an explicit, audited `SuperAdminContext`, never a silently-omitted filter.
- Org profile carries language, timezone, currency defaults; individual users can override language/timezone.

---

## 14. Database Entity Map (high-level)

```
organizations ─┬─ departments ─ teams ─ users
                ├─ organization_modules ─ modules
                ├─ user_module_overrides
                ├─ roles ─ role_permissions
                ├─ contacts ─ companies ─ leads (Core)
                ├─ tags / notes / attachments / custom_field_defs / custom_field_values
                ├─ deals ─ pipelines ─ pipeline_stages ─ activities ─ tasks   (Sales)         [each deal → currency_id]
                ├─ tickets                                                    (Support)
                ├─ mail_threads ─ mail_messages ─ mail_attachments            (Internal Mail)
                ├─ kb_categories ─ kb_articles ─ kb_article_versions          (Knowledge)
                ├─ products ─ quotes ─ orders ─ invoices ─ payments           (Finance)        [each → currency_id]
                ├─ wallets ─ wallet_ledger ─ wallet_transfer_limits           (Wallet)          [wallets → currency_id, asset_type]
                ├─ currencies ─ exchange_rates ─ exchange_rate_sources                          (Currency Mgmt — new, Core)
                │  └─ organization_currencies ─ currency_settings
                ├─ campaigns ─ segments                                       (Marketing)
                ├─ notifications                                              (Core)
                ├─ audit_log                                                  (Core)
                └─ translations ─ translation_keys                            (Localization)
```

Exact column-level DDL is a Phase-4/5 deliverable per module, not Phase 0.

---

## 15–17. Wallet & Ledger Architecture

- **Balance is a derived/cached value; `wallet_ledger` is the source of truth.** Every operation (deposit, transfer, transfer_fee, withdrawal, refund, adjustment, reversal) inserts an immutable ledger row (`wallet_id, type, amount, currency_id, balance_after, reference_type, reference_id, created_by, created_at`). Corrections are always compensating rows, never `UPDATE`/`DELETE` on history.
- `wallets.balance` is a maintained cache updated inside the **same DB transaction** as the ledger insert, and is periodically reconciled by re-summing the ledger (background job) to catch any drift.
- **Top-up flow** (§30): `pending transaction row → redirect to gateway → gateway callback → server-side verification against gateway API (never trust client redirect) → idempotency check on provider reference → credit inside DB transaction → mark complete → audit → notify`.
- **Idempotency**: `wallet_transactions.provider_reference` has a UNIQUE constraint; the callback handler does `INSERT ... ON DUPLICATE KEY` style guard + row locking, so a replayed webhook is a no-op, not a double credit.
- **Transfers**: single DB transaction debiting A and crediting B plus fee ledger row; enforced by DB-level row locks (`SELECT ... FOR UPDATE`) to prevent concurrent-transfer race conditions; limits (min/max/daily/monthly) and fees are configurable per organization/plan, checked before the transaction commits.

> **UPDATE (Multi-Currency Wallet):** per the update's §24, one wallet = one currency. A user with balances in USD, EUR and TRY holds **three separate `wallets` rows**, each with its own `currency_id` and its own ledger stream — never a single wallet mixing currencies. `wallets` gets a unique constraint on `(user_id, currency_id)` (and later `(organization_id, currency_id)` once organization wallets are added, per §22). A transfer between two wallets of *different* currencies is a **cross-currency transfer** (§45 below) and is modeled as two linked ledger entries (a debit conversion-out and a credit conversion-in) referencing the same `exchange_rate_id`, not a single row with an implicit conversion. **✅ DECISION (Rev. 3 — final):** one wallet per currency confirmed; wallet UIs present a list of per-currency wallets rather than one balance. See *Final Architecture Decisions* §D2.
> **UPDATE (Crypto readiness, §54–55):** `wallets` gets an `asset_type` column (`FIAT | CRYPTO`, default `FIAT`). Crypto wallets reuse the same `wallets`/`wallet_ledger` tables and the same `WalletService` interface, but crypto-specific concerns (address generation, confirmations/finality, network fees) live behind a separate `CryptoAssetProviderInterface` that only a future Crypto extension implements — fiat wallet code never needs to know crypto exists. No crypto functionality is built in the MVP; this is purely a non-blocking schema/interface accommodation now. See also §D3/§D9 (precision) below — the storage precision decision was driven specifically by needing this accommodation to hold up without a later redesign.

## 18. Payment Gateway Abstraction

```
interface PaymentGatewayInterface {
    initiate(amount, currency, metadata): RedirectInstruction
    verify(callbackPayload): VerificationResult   // hits gateway's server API, not just trusts params
}
```

Concrete gateways (regional/international) are adapters registered in a `GatewayRegistry`; Wallet/Finance code depends only on the interface. Credentials live in environment-based config, never in code or logs.

---

## 18a–18f. Currency Management & Multi-Currency Architecture *(new — added per update)*

This entire block is a **system-level capability**, not a Wallet feature (per the update's §35): Wallet, Finance, Products, Sales/Deals, Reporting, and future Billing/Plans all consume the *same* central tables and services below — none of them own their own currency list.

**Central entities** (added to the Core schema, not duplicated per module):

```
currencies(id, code[ISO 4217, e.g. USD/EUR/TRY/IRR], name, symbol,
           decimal_precision, is_active, is_default_platform_currency,
           created_at, updated_at)

exchange_rate_sources(id, key, name, type[manual|external_api], config json)

exchange_rates(id, base_currency_id, quote_currency_id, rate,
               source_id, effective_at, created_by, created_at)
               -- immutable rows; a "current rate" is the latest effective_at
               -- per (base,quote) pair, never an UPDATE on an old rate

organization_currencies(organization_id, currency_id, is_enabled,
               is_base_currency, is_default_currency,
               is_reporting_currency)

currency_settings(scope[platform|organization], organization_id nullable,
               default_currency_id, display_format, updated_at)
```

Every monetary column elsewhere (`wallets.currency_id`, `deals.currency_id`, `quotes.currency_id`, `invoices.currency_id`, `payments.currency_id`, …) is a foreign key into this single `currencies` table — never a free-text `varchar(3)` duplicated per module (per the update's §51–52). **✅ DECISION (Rev. 3 — final):** `currency_id` FK confirmed as the architectural principle everywhere money is stored; earlier drafts' `currency` fields are reworked accordingly before Phase 5/6/7/12 implementation. See *Final Architecture Decisions* §D4.

**Decimal precision & rounding — reassessed and finalized (§41, §49):** the original `DECIMAL(19,4)` proposal was **re-examined against fiat, future crypto, fees and exchange rates as requested, and revised**:

- *Fiat is not the limiting case.* No real-world fiat currency uses more than 4 decimal places (most use 2, some — e.g. IQD, BHD — use 3), so scale 4 would have been fine for fiat alone.
- *Crypto is the limiting case, even though it isn't built yet.* Bitcoin's native unit (the satoshi) requires **8 decimal places**; Ethereum and most ERC-20 tokens use **18 decimal places** (wei). A scale-4 column would truncate any future crypto amount to the point of being wrong, and — per the requirement that crypto must be addable *"without redesigning Wallet"* — the amount columns must already have enough scale headroom today, or adding crypto later would force an `ALTER TABLE` on every historical ledger row, which is precisely the kind of retroactive schema change this architecture is meant to avoid.
- *Exchange rates and fees are also undersized at scale 4.* A rate between a fiat currency and a low-value token, or a fractional/percentage fee, can legitimately need more than 4 decimal digits to avoid compounding rounding error across many transactions.

**Final decision: `DECIMAL(38,18)`** for every monetary amount column (`wallet_ledger.amount`, `wallets.balance`, `invoices.amount`, `deals.value`, `payments.amount`, etc.) and for `exchange_rates.rate`. Rationale: MySQL/MariaDB's `DECIMAL` supports up to 65 total digits, so `(38,18)` is comfortably inside that ceiling; scale 18 covers wei-level crypto precision (and Bitcoin's 8-decimal satoshi with headroom to spare) as well as any fiat currency; the remaining 20 integer digits are far beyond any realistic monetary magnitude, fiat or crypto. `currencies.decimal_precision` continues to govern **display/rounding only** (e.g., show 2 places for USD, 8 for BTC) — it never constrains storage width, so a currency's display precision can change without touching stored values. Rounding remains "round half to even," applied only at display/settlement, never on intermediate calculations. This is now the fixed platform-wide standard; it does **not** get revisited per module. See *Final Architecture Decisions* §D3.

**Exchange rate abstraction:**

```
interface ExchangeRateProviderInterface {
    getRate(baseCurrency, quoteCurrency, atTimestamp): RateResult
}
```

`ManualRateProvider` (Super Admin/org-admin entered rates, per §43) ships in MVP; `ExternalRateProvider` (a scheduled cron job per §44) is a Phase 12+ addition behind the same interface — Wallet/Finance code never calls a provider directly, only `CurrencyService::convert(amount, from, to, atTimestamp)`, which resolves the applicable rate and records which `exchange_rate_id` was used.

**Immutability guarantee (§37, §42, §45):** every cross-currency transaction (wallet cross-currency transfer, a converted invoice, a deal's reporting-currency rollup) stores the `exchange_rate_id`, the source/destination amounts, and the rate timestamp **at the moment of the transaction** — changing the platform's or an organization's default currency later, or a rate being updated tomorrow, never rewrites historical records. This is enforced the same way wallet ledger immutability is (§15–17): compensating entries only, never `UPDATE` on a settled conversion.

**Cross-currency aggregation policy (§48):** Reporting never sums raw amounts across currencies. Every report that aggregates money either (a) filters to one currency, or (b) explicitly converts every line to the organization's `reporting_currency` at a **stated, documented conversion point**. **✅ DECISION (Rev. 3 — final): the MVP default is transaction-time rate** — i.e., each historical line is converted using the `exchange_rate_id` that was actually recorded against it at the time (per the immutability guarantee above), not the latest rate. Reasoning: this matches the historical-cost principle already used in standard accounting practice, keeps report totals stable and reproducible (re-running the same report tomorrow gives the same number, since it doesn't depend on today's rate), and is consistent with the immutability already mandated for wallet ledger and cross-currency transactions elsewhere in this document — introducing a rate-dependent, re-runnable report would be the one place that breaks that consistency. Report-time rate remains available as an explicit alternative for "current value" / mark-to-market style views, selected per report rather than being the default. To keep this changeable without an architecture change, the policy is stored as a **configuration value** (`currency_settings`-level `reporting_conversion_policy` enum: `transaction_time | report_time`, settable per organization and overridable per report definition in the Reporting module), consumed by the Reporting query layer — not hard-coded into any query or schema. See *Final Architecture Decisions* §D5.

**API shape (§53):** money is always returned as a **string** (never a JSON number, to avoid client-side float precision loss) with an explicit currency code, and converted values add `base_amount`/`base_currency`/`exchange_rate`/`exchange_rate_timestamp` fields — exactly as specified in the update.

**Settings surfaces (§50):** Platform-level Currency Settings (Super Admin) control the currency catalogue, the platform default currency, and the default/​fallback exchange-rate source. Organization Settings (Org Admin, permission-gated) select which currencies are enabled for that org, its base/default/reporting currency. User Settings hold only a *display* preference (§39) — changing it never touches stored transaction currency, exactly mirroring how user language/timezone preferences work elsewhere in this document.

---

## 19. Finance Module

Quotes → Orders → Invoices → Payments, each with status state machines, each carrying its own `currency_id` (§46) rather than assuming the organization's base currency. Finance requests Wallet credits/debits only through `WalletService` methods (never direct SQL), keeping the two modules' data ownership separate per §58/§64 — a "pay invoice with wallet" flow is `FinanceService` calling `WalletService::debit(wallet, amount, currency)` and recording the resulting `payments` row, with `CurrencyService` invoked if the invoice and wallet currencies differ.

## 20. Internal Mail

Standard inbox/sent/drafts/trash/threads model, each message optionally polymorphically linked (`linkable_type`, `linkable_id`) to a Contact/Company/Lead/Deal/Ticket/KB article, surfaced in that entity's Timeline via the shared Core timeline service.

## 21. Knowledge Management

`kb_articles` with `kb_article_versions` for full version history/rollback; visibility enum (`PUBLIC|ORGANIZATION|DEPARTMENT|TEAM|PRIVATE`) checked by the same `AuthorizationEngine`; workflow states (`draft→submitted→under_review→approved→published`) enforced by a small state machine per module, with `knowledge.approve`/`knowledge.publish` as distinct permissions.

---

## 22. Localization Architecture

- All user-facing strings referenced by translation key (`crm.customers.title`), never hard-coded.
- `translation_keys(key, module)`, `translations(key_id, locale, value, status, reviewed_by)`.
- Persian is default locale (RTL); English is LTR; a `LocaleService` resolves `org.default_language → user.language → fallback`, and the theme layer switches `dir="rtl|ltr"` purely from that.
- Localization is a Core service consumed by every module; it has no dependency on business logic in the other direction.

## 23. AI Translation & AI Architecture

- `AiProviderInterface` (translate/summarize/generateTitle/generateTags/…) with pluggable concrete providers — no module hard-codes a vendor SDK call.
- Workflow: `Source → AI draft → Human review → Edit → Approve → Publish`. AI output is written with `status = ai_draft`; it is **never** auto-promoted to `approved`/`published` — a human permission (`translations.approve`, `knowledge.approve`) is required.
- AI module (optional, add-on) calls the same Services/AuthorizationEngine as any user-triggered action — it cannot bypass tenant boundaries, permissions, or perform destructive writes without an explicit, permission-checked action.

---

## 24–26. Notifications, Search, Audit

- **Notifications**: Core module; other modules emit domain Events (`DealStageChanged`, `TicketAssigned`, …) → a `NotificationListener` subscribes and creates in-app + optionally email notifications, decoupling notification logic from each module's business logic.
- **Search**: a reusable `SearchIndex` abstraction (`index(entity)`, `query(term, filters, tenant)`), backed initially by MySQL `FULLTEXT`/`LIKE` indexes with pagination/sorting/filtering; the interface is designed so a future Elasticsearch/pgvector/semantic backend can be swapped in without changing calling code.
- **Audit**: a single `AuditLogger::log(actor, action, entity, before, after, meta)` called from Services (not controllers) so every mutation is captured consistently; explicit deny-list excludes passwords/secrets/payment credentials from ever being logged.

---

## 27. Theme / Presentation Architecture

```
/themes/default/{templates,assets/{css,js,images},theme.json}
```

Rules enforced by code review / lint, not just convention: no SQL or business logic in templates, no translation strings hard-coded, no direct DB access from views. Controllers pass **View Models** (plain data) to templates; templates never call Services directly.

## 28. API Architecture

`/api/v1/...`, JSON:API-ish consistent envelope (`data`, `meta`, `errors`), same auth/authorization middleware stack as the web app, standard pagination (`page[number]`, `page[size]`), filtering (`filter[field]=value`), sorting (`sort=-created_at`). The API is versioned from day one because it becomes the permanent contract during the React/FastAPI migration (§56/§80).

---

## 29. Proposed Folder Structure

```
/app
  /Core            (framework glue: Router, Container, Middleware, EventBus)
  /Domain          (entities/value objects, framework-agnostic)
  /Application     (Services — orchestrate use cases)
  /Infrastructure
    /Repositories
    /Auth
    /Localization
    /Currency        (new: CurrencyService, ExchangeRateProviderInterface + providers — Core, used by every monetary module)
    /Search
    /Notifications
/modules
  /Sales/{module.json,Config,Controllers,Services,Repositories,Models,Routes,Permissions,Migrations,Events,Listeners,Templates,Assets,Tests}
  /Wallet/...
  /Finance/...
  /Support/...
  /InternalMail/...
  /Knowledge/...
  /Marketing/...
  /AI/...
/api/v1
/database/migrations   (core) + per-module migrations under each module
/themes/default
/public
/storage/{uploads,cache,logs}
/tests/{Unit,Integration,Feature,Security}
/docs
/config
```

## 30. Database Schema Proposal

Full column-level DDL is deferred to Phase 4/5 per-module design so each module's schema is reviewed alongside its actual implementation rather than speculatively now; §14's entity map is the Phase 0-level agreement.

## 31. Module Migration Strategy (DB)

Each module owns its migration files; a `module_migrations` ledger (per module) tracks applied migrations independently of Core's own `migrations` table, so modules can be installed/upgraded/rolled back without touching Core's migration history.

---

## 32. Security Architecture

PDO prepared statements everywhere; `password_hash`/`password_verify`; secure, `HttpOnly`, `SameSite` session cookies with server-side expiry; CSRF tokens on all state-changing forms/API calls; output escaping at the template layer; centralized input validation per request DTO; authorization middleware in front of every route (web and API); upload validation (MIME sniffing, extension allow-list, no executables, randomized storage filenames, served from a non-executable path); rate limiting on auth and payment endpoints.

## 33. Testing Architecture

`/tests/{Unit,Integration,Feature,Security}` per module (mirrors `/modules/<Name>/Tests`). Minimum coverage per §76–78: auth, authorization/RBAC/scopes, tenant isolation, module activation/deactivation/dependency, CRUD+API+validation, import/export, translation workflow, wallet suite (§77, 18 scenarios), and the security suite (§78: IDOR, privilege escalation, tenant escape, disabled-module access, CSRF/XSS/SQLi, malicious uploads).

## 34. cPanel Deployment Architecture

Single PHP/Apache/MySQL deployable via standard file upload + cPanel MySQL setup; cron-based background jobs (no Redis/queue daemon required for MVP — a simple `jobs` DB table polled by a cron-triggered PHP script); `.env`-style config outside the public web root where the hosting model allows it, otherwise protected via `.htaccess` deny rules.

## 35–36. Migration Strategy (PHP→Python, Templates→React)

Staged per §80: `PHP+templates → PHP+REST API → React+PHP API → React+FastAPI+MySQL/Postgres → React+FastAPI+Postgres`. The API contract (§28) is the fixed boundary — each stage swaps what's *behind* the API without breaking the frontend, and swaps the frontend without breaking the backend. Nothing is rewritten destructively; both stacks can coexist during a transition.

## 37–38. Commercial SaaS Readiness / Plans

Module Manager's `organization_modules` table already *is* the entitlement mechanism; Plans (§71) will later be a layer that bulk-sets `organization_modules` + numeric limits (§72) rather than a separate access-control system — so billing can be added later without touching the authorization path modules already use.

---

## 39. Risks

- **Scope creep on shared hosting**: cPanel constraints (no Redis, no queue workers, limited cron granularity) could force compromises later — mitigated by designing the queue/job/search abstractions now so swapping the backend implementation doesn't touch calling code.
- **Wallet correctness**: financial bugs are the highest-severity risk in this system; mitigated by ledger-as-source-of-truth + mandatory transaction locking + the 18-case test suite before Phase 7 is considered done.
- **Module boundary discipline**: without enforcement, modules will be tempted to query each other's tables directly under time pressure — mitigated by code review checklist + the `TenantScopedRepository`/Service pattern making the "correct" path also the easy path.
- **Translation debt**: hard-coding strings is the most common shortcut under deadline pressure — mitigated by a lint check for raw strings in templates during CI (to be set up in Phase 17).
- **PHP→Python migration risk**: an unstable API contract would make the eventual migration painful — mitigated by versioning `/api/v1` from Phase 1, not retrofitting it later.
- **Currency retrofit risk** *(new)*: because Currency Management is being added after Wallet/Finance/Sales were already drafted, those modules' `currency` fields must be reworked to `currency_id` before implementation — flagged above at each affected section rather than silently patched.
- **Exchange-rate staleness/manipulation** *(new)*: manual rates (MVP default) can be entered incorrectly or go stale; mitigated by requiring `exchange_rates` rows to be immutable + audited (§21) and by surfacing rate age in any UI/API that displays a converted amount, so staleness is visible rather than hidden.
- **Cross-currency reporting ambiguity** *(resolved in Rev. 3)*: default policy is now transaction-time rate (D5), removing the ambiguity; report-time remains available as a configured alternative rather than a second undefined default.

## 40. Dependency Graph (module level)

```
Core ← Auth/RBAC ← Module Manager ← Localization
Core ← Sales
Core ← Support (needs Contacts)
Core ← Internal Mail
Core ← Knowledge
Core ← Products ← Finance (needs Wallet via service calls, not a hard dependency)
Core ← Wallet
Core ← Leads ← Marketing
Core ← Reporting (reads all enabled modules' data, depends on nothing structurally)
Core ← AI
Localization ← AI Translation
Currency Management ← Wallet, Finance, Sales, Reporting, (future) Billing   -- new: consumed, not "depended on" in the activation sense, since Currency is always-on Core
```

## 41. Development Roadmap

Follows the master prompt's Phase 0–21 sequence exactly (Foundation → Auth → Org/RBAC → Module Manager → CRM Core → Sales → Wallet → Support → Internal Mail → Knowledge → Localization/AI Translation → Finance → Reports → Marketing → AI → Security Hardening → QA → cPanel Deployment → Python Prep → React → FastAPI Migration). No phase reordering is proposed at this stage.

---

## Final Architecture Decisions

These are the recorded, final Phase 0 decisions — not open questions. Phase 1+ implementation should treat these as settled unless a future phase surfaces a concrete reason to revisit one, in which case it goes through the same "explain → flag → approve" process as any architecture change, per the ground rules.

- **D1 — Currency Management is CORE and non-togglable.** It is a shared platform capability (not a Wallet feature) consumed by Wallet, Finance, Sales, Reporting, and future Billing. It is always installed and always enabled; there is no Super Admin toggle for it, because disabling it would break every monetary module simultaneously.
- **D2 — Wallets are one-wallet-per-currency.** A user (and later an organization) holds one `wallets` row per currency/asset they hold, each with its own `currency_id`, `asset_type`, and independent ledger stream — never a single wallet with multiple balances. Enforced by a `UNIQUE(user_id, currency_id)` constraint (extended to `organization_id` for org wallets later).
- **D3 — Monetary storage precision is `DECIMAL(38,18)`,** for both amount columns and `exchange_rates.rate`, replacing the earlier `DECIMAL(19,4)` proposal. Chosen specifically because scale 4 would not survive future crypto (BTC needs 8 decimals, ETH/ERC-20 tokens need 18) without a retroactive schema change — see the full reasoning in §18a–18f. `currencies.decimal_precision` remains a separate, per-currency *display* setting and never constrains storage.
- **D4 — All monetary values reference the central `currencies` table via `currency_id`.** Free-text currency fields are disallowed platform-wide; every earlier module section that sketched a plain `currency` column (Wallet, Finance, Sales) is reworked to `currency_id` before its implementation phase.
- **D5 — Reporting's default cross-currency conversion policy is transaction-time rate**, for consistency with the immutability principle already used for wallet ledgers and cross-currency transactions, and because it keeps report totals reproducible. Report-time rate is supported as an explicit, selectable alternative (not a default) via a `reporting_conversion_policy` configuration value at organization/report level — switching the default later is a config change, not an architecture change.
- **D6 — Multi-Currency is defined at the System Settings level**, with Platform Currency Settings (Super Admin: catalogue, platform default, default rate source), Organization Currency Settings (enabled currencies, base/default/reporting currency), and User display-currency preference as three distinct, cascading layers — not a Wallet-only setting.
- **D7 — The Currency Management catalogue's required fields** are: ISO 4217 code, name, symbol, decimal precision (display), active/inactive flag, platform default flag; plus, at the organization layer: enabled currencies, base currency, default currency, reporting currency; plus exchange-rate sources, manual rate entries, immutable rate history with effective timestamp, and full audit trail on every rate and settings change — all as specified in §18a–18f.
- **D8 — Every monetary module uses the central Currency Management system exclusively**: Wallet, Finance, Products, Services, Deals, Quotes, Orders, Invoices, Payments, Reports, Plans, Fees, Limits, and future Billing. None of them defines its own currency list or conversion logic.
- **D9 — Crypto is architecture-only in Phase 0/MVP, not implemented.** `wallets.asset_type` (`FIAT | CRYPTO`) and the `DECIMAL(38,18)` precision decision (D3) exist specifically so BTC/USDT/USDC/etc. can be added later behind a `CryptoAssetProviderInterface` without redesigning the Wallet schema or ledger. No crypto code, gateway, or UI is built now.

---

## Open Decisions / Future Configuration

Deliberately left open for later phases — these are *not* unresolved architecture, they are configuration or scope decisions that this architecture is designed to accommodate without a redesign whenever they're made:

- Which specific currencies ship enabled by default in the platform catalogue (beyond USD/EUR/IRR/TRY as examples used in this document) — a Phase-4/5 configuration task, not an architectural one.
- Which external exchange-rate API/provider to integrate first for `ExternalRateProvider` (Phase 12+) — MVP uses `ManualRateProvider` only.
- Whether/when to enable `reporting_conversion_policy = report_time` as an option in the Reporting UI (Phase 13) — the config field exists from Phase 0; turning it on is a later product decision.
- Exact Commercial Plan definitions (Basic/Professional/Enterprise or otherwise) and their module/limit bundles (§37–38, §56–57 of the update) — the entitlement mechanism (`organization_modules` + limits) is architected now; the specific plans are a Phase 21+/commercial decision.
- Which specific crypto assets (BTC, USDT, USDC, others) to support first, and which blockchain/custody approach to use — entirely out of scope until a dedicated Crypto phase is proposed; D9 only guarantees the schema won't need to change when that happens.
- Fee schedule specifics (fixed vs. percentage, who pays, per-currency overrides) for wallet transfers/withdrawals — the `wallet_transfer_limits`/fee fields exist structurally (§28–33 of the update); actual numbers are an operational/business decision for Phase 7.

---

## Development Roadmap

No change to phase order (§41 above). Still following the master prompt's Phase 0–21 sequence exactly.

---

## Next Step

Phase 0 is now complete and internally consistent, with all previously open points resolved and recorded in *Final Architecture Decisions* above. No code has been written. Please confirm Phase 0 as final, or flag anything you want revisited — otherwise Phase 1 (Foundation) begins next, scoped exactly as described in §87/§1 of the original master prompt (bootstrap, config, DB connection, routing, base classes, template engine, API foundation, error handling, logging, security foundation, event foundation, Module Manager foundation — nothing else).
