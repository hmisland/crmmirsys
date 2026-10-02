# Changelog

All notable changes to this project are recorded here, per Phase 0 §65/§108.

## [Unreleased]

### UI/UX redesign (presentation layer only)
- New design system in `themes/default/assets/css/app.css` (tokens, buttons, forms, tables, badges, alerts, tabs, dropdowns, dialog, empty/loading states). Every pre-existing class name still works.
- New app shell: grouped sidebar with icons (module → section), collapsible on desktop, off-canvas drawer on tablet/mobile; topbar with breadcrumb, global search, language switcher (native language names) and account menu.
- One icon set (inline SVG sprite) via a new `icon()` template helper; ~210 action buttons now show icon + label. Status values render as colored pills via `badge()`.
- Delete actions are styled as danger and ask for confirmation (`data-confirm`) before submitting — the form/request itself is unchanged.
- Dashboard redesigned (KPI cards, grouped tasks, follow-ups, quick actions) using only the data the controller already provided.
- Org settings is now a settings hub grid; 404/500 pages are styled standalone documents.
- RTL fixes: date picker anchors/arrow direction, mixed-script text, mirrored chevrons. Accessibility: skip link, landmarks, focus rings, labels on unlabeled selects/file inputs.
- No controller, service, repository, route, migration, RBAC or config changes. See `docs/ui-design-system.md`.

### Phase 11 — Localization (infrastructure complete, content partial)
- Real `Translator`/`Locale` system, replacing the Phase 1 `t()` stub: 4 language files (`en`/`fa`/`ar`/`tr`), English as the required fallback for any missing key in another locale, automatic RTL/LTR direction.
- **Honest scope note**: Phase 1 set up templates to call `t('key')` specifically so this phase would be a clean swap — that convention wasn't actually followed for any template built across Phases 5–10, all of which hardcode English text directly. This phase converted only the nav/header and the login/register pages; every other template still shows English regardless of locale, and will be converted module by module in further deliveries.
- Locale resolution: logged-in user's own `users.language` → guest's session-stored choice → platform config default. A language switcher in the nav persists the choice via a new `/lang/switch` endpoint (`return_to` validated as same-origin only, never an open redirect).
- `users.language`/`organizations.default_language` validation expanded from `[fa, en]` to all 4 locales.
- **Real gap found, documented rather than silently left**: a guest's session-stored language choice on the login page doesn't carry through to their new account's default language at registration — `AuthService::register()` still defaults from platform config, not the guest's in-session pick.
- Documented in `docs/phase11-localization.md`, including exactly which templates are and aren't converted yet.

### Date picker — clickable year/month quick-jump
- The date picker's month/year header label is now clickable, opening a 12-month grid for the current year with year-stepping prev/next buttons — reaching a date years away no longer requires dozens of single-month clicks. Requested alongside Phase 11.

### Phase 10 — Knowledge Base & File Library
- Added the Knowledge module (toggleable, `modules/Knowledge/`): `kb_categories`, `kb_articles`, `file_library_documents`, `file_library_uploaders`.
- Knowledge base: categories, articles with title/body/category, search by title/body.
- File library: any file type accepted, safely — reuses `AttachmentService`'s non-web-accessible storage architecture (random hex filenames, served only through a controlled download response) rather than an extension allow-list, so an uploaded `.php`/`.exe` is inert bytes on disk, never executed.
- Tags reuse Phase 5's existing `tags`/`taggables` tables rather than a new system — added the one genuinely missing piece to Core: a reverse lookup (`TagRepository::subjectIdsWithTag()`/`TagService::subjectIdsWithTagName()`), since the existing code could only answer "what tags does X have," never "what has this tag."
- New system-wide convention: a tag-pill input widget (`tag-input.js`) — `<input class="crm-tag-input">` anywhere auto-upgrades into a type-and-press-Enter pill widget, same auto-init pattern as the date picker.
- Per-user upload/delete access via a `file_library_uploaders` allow-list, managed by org_admin from `/files/uploaders` — org_admin can always manage files; a member additionally can if granted, per the explicit "access determined and managed by org admin, for some or all users" requirement.
- **Caught in review, not after shipping**: `FileLibraryUploaderRepository::forOrganization()`'s correlated subquery (for the user's display name) would have been mis-scoped by `tenantFindMany()`'s automatic first-`WHERE`-only logic — the subquery's own `WHERE` appears earlier in the raw SQL than the outer one. Fixed with explicit org-qualification before it ever ran.
- **Real Core gap found and fixed**: `Container` had no way to resolve the app's storage directory (only `Application` had `basePath`, privately). Added a small, reusable `app.storage_root` binding rather than a one-off workaround — any future module needing its own storage subdirectory can use the same one.
- Documented in `docs/phase10-knowledge.md`, including the deliberate trims (no article versioning, no per-file granular visibility, no previews).

### Bank accounts — layout bug, Super Admin crash, bank dropdown
Four issues reported together from real usage, all fixed:

- **Real bug, found in review**: the bank-account "add" form had
  `class="card--form"` on the `<form>` element itself, not on a
  wrapping `<section>`. The grid layout rule is `.card--form form`
  (a descendant selector) — a form that IS the `.card--form`, rather
  than being INSIDE one, never matched it, so the fields fell back to
  default inline flow and rendered jumbled (exactly the same failure
  mode as the Contact form bug fixed earlier this project, reintroduced
  here by a fresh mistake in new code). Fixed by wrapping the form in
  its own `<div class="card--form">`. A repo-wide scan confirmed this
  was the only place with the mistake.
- **Real bug, found from a live server log**: Super Admin has no
  organization, and both Bank accounts and Notification channels are
  stored in tenant-scoped tables — visiting `/profile` as Super Admin
  threw "No OrganizationContext is set" instead of loading. Both
  sections are now skipped entirely for Super Admin (they have no
  org-scoped wallet to withdraw from either), guarded in
  `AuthController` rather than in the tenant-scoped repositories
  themselves.
- **Bank name is now a dropdown**, not free text — a new platform-wide
  `banks` table (Core, like `currencies`), seeded with a solid starter
  list of major Iranian banks (not guaranteed exhaustive — bank
  licenses do occasionally change) that Super Admin can extend from
  `/admin/wallet/settings` (grouped there per an explicit request,
  reusing the existing platform wallet settings page rather than a new
  one). `BankAccountService` now resolves and validates the selected
  bank server-side rather than trusting whatever a tampered dropdown
  might submit.
- **Card number validation changed to 12 digits**, per an explicit
  instruction — flagged in code and here: real Iranian bank cards
  (Shetab network, ISO/IEC 7812 PAN format) are standard 16-digit
  numbers, so this may reject real card numbers if 12 wasn't
  intentional. Implemented as asked rather than silently overridden;
  worth confirming.

### Bank accounts for wallet withdrawals (Profile)
- Added `user_bank_accounts` (Core, not Wallet-owned — a bank account is
  profile/identity data, same reasoning as `mobile_phone`/`national_id`).
  A user can save more than one account, each with bank name, account
  number, Sheba number (stored as 24 digits, without the "IR" prefix,
  per the explicit requirement), card number, and branch code.
- New section on `/profile`: list + add/delete, with the required
  plain-text notice that an account must belong to the same national ID
  and mobile phone as the profile itself (stated to the user, not
  automatically verified — no bank-identity API is connected).
- Wallet's withdrawal request form no longer takes free-typed
  destination text — it's a dropdown of the user's own saved bank
  accounts (bank name + account number). `WalletController` looks up
  the selected account, verifies it actually belongs to the requesting
  user (never trusts the submitted ID alone), and formats it into the
  existing `wallet_withdrawal_requests.destination_info` text column —
  no schema change needed on the Wallet side.
- A module (Wallet) depending on this new Core service is the normal,
  expected direction (the same way Sales depends on CRM Core's
  Contacts) — unlike the Notification-channel fields on the same
  Profile page, this needed no lazy/optional lookup trick.
- **Caught before shipping**: `BankAccountService::requireOwnAccount()`
  throws `NotFoundException` for a missing/mismatched account, not
  `ValidationException` — the withdrawal controller's error handling
  only caught the latter at first, which would have surfaced a raw 404
  error page instead of a friendly flash message for that one case.

### Profile consolidation — personal settings move out of admin panels
- Per-user settings that were scattered across nav items and an
  admin-adjacent page now live on `/profile`, where they actually
  belong: mobile phone, national ID (new core `users` columns), and
  (when the Notification module is installed) Bale/Telegram chat IDs
  plus the "send test Bale message" button — all previously on a
  standalone `/notifications/settings` page.
- `users.mobile_phone` is now the single source of truth for SMS —
  `NotificationDispatchService` reads it directly instead of keeping
  a second, separate copy in `notification_recipients.phone_number`
  (that column stays in the schema, unused, rather than a risky
  `DROP COLUMN`).
- Added `notification_recipients.telegram_chat_id` — groundwork only,
  same pattern as the SMS phone number before a provider existed; no
  Telegram gateway is implemented yet.
- `/profile` (Core) reads the optional Bale/Telegram data via the same
  lazy, non-hard-dependency container lookup Internal Mail already
  uses to trigger Notification's dispatch — Core still never directly
  references a module's classes; it degrades to showing nothing if
  Notification isn't installed.
- Also consolidated: the main nav no longer lists Team/Roles/
  Departments/Modules/Custom fields/Org Wallet/Org notifications
  individually — they're reachable from one "Org settings" hub page
  now, grouped by area and only shown when the relevant module is
  actually enabled for the org.

### Notification module (Bale + SMS)
- Added the Notification module (toggleable, `modules/Notification/`, **depends on Wallet** — the first real use of the module dependency system): a Wallet-funded credit balance, delivery log, per-user Bale chat ID/phone storage, Super Admin pricing.
- Real `BaleGateway` verified against the official docs.bale.ai documentation (fetched directly, not assumed) — confirmed there is no documented way to message a Bale user by phone number alone, only by a `chat_id` obtained after they've started a chat with the bot (same restriction as Telegram bots). `NullSmsGateway` is an explicit, honest placeholder — no real SMS provider connected yet, per instruction.
- `NotificationCreditService`: "charging the module" is a real transfer — the org's Wallet is debited and this module's own balance is credited by the same amount, with rollback-on-failure.
- Added an opt-in "notify via SMS/Bale" checkbox to Internal Mail's compose and reply forms — sends a short, fixed announcement only (never the message body), per the explicit requirement.
- **A real cross-module wiring bug was caught and fixed before shipping**: `MailController` is built before `NotificationModule` registers (alphabetical module order), so eagerly resolving the dispatch service at construction time would always have failed. Fixed by resolving it lazily, at request-time, via the container — Internal Mail works identically whether Notification is installed or not.
- Documented in `docs/phase9b-notifications.md`, including the deliberate trims (no SMS provider yet, no way around Bale's chat_id-only messaging).

### Phase 9 — Internal Mail
- Added the Internal Mail module (toggleable, `modules/InternalMail/`): `messages` (self-referencing `thread_id` for threading), `message_recipients` (per-recipient read status), `contact_broadcasts` (a send log).
- Added `mail.view`/`mail.send` (everyone) and `mail.broadcast_team`/`mail.broadcast_customers` (org_admin only) permissions.
- `MessageService`: inbox, sent, compose (multi-recipient), reply (recipients inherited from the root message), thread access control that checks every message in a thread, not just the root.
- `BroadcastService`, per the explicit "send to all" requirement: broadcasting to the **team** reuses `MessageService::compose()` (everyone already has an inbox here); broadcasting to **customers** sends a real email via the existing `MailerInterface` (Phase 2), since Contacts have no login/inbox. No queue system exists yet, so a customer broadcast sends synchronously — documented plainly as a real scaling limitation, not glossed over.
- Documented in `docs/phase9-internal-mail.md`, including the deliberate trims (no thread-grouped inbox, no delivery/open tracking, no background sending yet).

### Phase 8 — Support
- Added the Support module (toggleable, `modules/Support/`): `ticket_categories`, `tickets`, `ticket_replies`.
- Added `tickets.*`/`ticket_categories.manage` permissions — member granted `organization` scope (not `own`), a deliberate departure from Deals/Tasks: support is a team effort, and restricting a member to only their own assigned tickets would block them from covering a colleague's queue.
- `TicketService` (CRUD, status changes, assignment), `TicketReplyService` (public replies and internal-only notes share one thread, distinguished by a boolean, not separate tables), `TicketCategoryService`.
- Kanban board (reusing Sales' existing `.kanban` CSS, not rebuilt), list view, detail page with a reply thread and file attachments (reusing Phase 5's `AttachmentService`, not rebuilt).
- Every list query needing a related name (contact/category/assignee) used an explicitly org-qualified correlated subquery from the start — the join/scoping bug class that bit Contacts for real and nearly repeated in Wallet was applied proactively this time, not caught after the fact.
- Documented in `docs/phase8-support.md`, including the deliberate trims (no customer portal, no SLA/routing, no email integration yet).

### System-wide date picker (Gregorian / Jalali / Hijri)
- New standing rule, documented in `docs/conventions.md`: every date field
  in the app uses `<input type="text" class="crm-datepicker">`, never the
  native `<input type="date">`. Auto-initialized on every page via a
  bootstrap script in `layout.php` — no per-page JS needed.
- `themes/default/assets/js/datepicker.js`: a dependency-free widget
  supporting Gregorian, Jalali (Shamsi), and Hijri (Qamari) calendars,
  switchable by the user (remembered in `localStorage`), defaulting to
  the organization's language (`fa` -> Jalali, else Gregorian). Storage
  is always Gregorian ISO underneath, via a hidden sibling input — the
  calendar system only changes what's displayed.
- Replaced every existing native date input (Contact's date of birth,
  Deal's expected close date, every Task due-date field) with the new
  pattern.
- **The conversion math was genuinely wrong twice before shipping**,
  caught by actually testing it rather than trusting a plausible-looking
  formula: a Gregorian<->day-count formula sourced from a real published
  article failed round-trip verification (thousands of dates converted
  to the wrong date and back) when numerically tested. Replaced entirely
  with JavaScript's own `Date` object (spec-guaranteed-correct proleptic
  Gregorian arithmetic) as the pivot, with the Hijri epoch constant
  recalibrated to match. Re-verified after the fix: zero round-trip
  failures across tens of thousands of random dates, known reference
  anchors (e.g. 2024-03-20 = 1 Farvardin 1403; 1992-10-15 = 17 Rabi
  al-Thani 1413 AH), and a full simulated calendar-grid render (every day
  of 100 sampled months in both calendars, checking for gaps or
  duplicate days) before being considered safe to ship.
- Hijri uses the standard tabular/civil ("Kuwaiti algorithm") Islamic
  calendar — a fixed arithmetic cycle, not real moon sightings, accurate
  to within about 1-2 days of the observation-based Umm al-Qura calendar
  used in Saudi Arabia. Documented plainly so it's never mistaken for
  moon-sighting-exact.

### Contacts — date of birth and interests
- Added `date_of_birth` (plain date field) to Contacts' default fields.
- Added Interests — a new `contact_interests` table (multi-value, its own table rather than reusing the general Tags feature, since it's a distinct concept scoped to the Details tab of the contact form). Entered as a comma-separated field, stored as discrete rows, and displayed as pill-styled tags on the contact detail page — matching the request that it look and behave like tags both ways.
- Both wired through the same `full_contact_form` sentinel already protecting phones/emails/social from the contact detail page's small "save custom fields" quick-form.

### Phase 7 — Wallet
- Added a minimal `currencies` reference table (IRR/USD/EUR) and `wallet_platform_settings` (Super Admin's platform-wide top-up cap) to core.
- Added the Wallet module (toggleable, `modules/Wallet/`): `wallets`, an append-only `wallet_ledger`, `wallet_transactions` (gateway top-up lifecycle), `wallet_withdrawal_requests`, `wallet_org_settings`.
- `WalletService` is the sole path to every balance/ledger write, row-locked and transaction-wrapped (`bcmath` throughout — money is never a float).
- Real ZarinPal v4 REST API integration (verified against current official docs, not memory), behind a `PaymentGatewayInterface`/`GatewayRegistry` so Mellat/Saman Kish can be added later as additional drivers.
- Idempotent top-up callback handling: a DB unique constraint plus an atomic "claim" step (new `processing` status, migration 0006) close both the simple double-submit case and a real concurrent-callback race that was caught and fixed before delivery.
- User-to-user transfers (with optional fixed+percent fee), withdrawal requests (debited on request, refunded on rejection), and org-admin manual credit/debit adjustments (always require a reason, always land on the ledger).
- Two-level top-up limits exactly as requested: Super Admin sets a platform-wide cap; each Org Admin sets their own organization's min/max, always clamped into the platform's range even if the platform cap changes later.
- `BaseRepository::transaction()` widened from `protected` to `public` (app-wide, backward-compatible) — a Service orchestrating a transaction across more than one repository needed this and would otherwise have hit a PHP fatal error.
- Two more near-misses of the Phase-5-established "correlated subquery vs. naive first-WHERE tenant scoping" bug were caught and fixed before shipping (`WalletLedgerRepository`, `WalletWithdrawalRepository`).
- Documented in `docs/phase7-wallet.md`, including the deliberate trims (single platform-wide currency, no Mellat/Saman Kish yet, no automated payout, no fee revenue account).

### Contacts — extended profile fields
- Added `gender`, `province`, `description` columns to `contacts`, plus three new multi-value child tables: `contact_phones` (mobile/landline, one marked primary), `contact_emails` (one marked primary), `contact_social_profiles` (Facebook/Instagram/YouTube/Twitter/LinkedIn/Telegram/WhatsApp, or a user-typed custom network).
- `contacts.phone`/`contacts.email` are kept as a synced "primary" cache — existing search, CSV import/export, and duplicate detection keep working unchanged; the multi-value tables are additive.
- Added `IranProvinces` (all 31 provinces, confirmed against current sources) — City is a free-text field with a `<datalist>` of well-known cities as suggestions, deliberately NOT a closed dropdown, since a reliable, complete database of Iran's 1000+ cities isn't something to guess at; extend `IranProvinces::PROVINCE_CITIES` freely as better data becomes available.
- Contact form reworked into three tabs: Details (incl. title/Mr./Ms., province, city, description), Phones & Emails (add any number of each, one marked default via radio buttons), Social Networks (add any number, built-in platforms or a custom-named one) — plain vanilla JS for the tabs and dynamic add/remove rows, no library.
- **Real bug caught before delivery**: the contact detail page's small "save custom fields" quick-form only ever submits `first_name` + `custom[]` — since phone/email are now managed as full-replace collections, that quick-save would have silently wiped every phone and email on the contact. Fixed with a "was this section actually part of the submitted form" sentinel (hidden `phones_submitted`/`emails_submitted`/`social_submitted` fields on the real edit form, absent from the quick-save form) — a request that doesn't mention a section no longer touches it.
- CSV import/export intentionally NOT extended to the new multi-value fields in this pass (still exports/imports only the primary phone/email) — a natural next step, not done now.

### Contacts — CSV import/export
Per Phase 0 §47 (explicitly deferred out of Phase 5's initial scope,
now built): full CSV import/export for Contacts, including round-tripping
custom field values and tags.

- Added `contacts.import` permission (granted to `org_admin` only, same
  tier as `contacts.export`) via a new migration.
- Added `ContactImportExportService`: CSV export (with a UTF-8 BOM for
  correct non-ASCII rendering in Excel); a stateless two-step import —
  `parsePreview()` reads the uploaded file once and validates every row
  (required fields, email format, duplicate detection by email for
  upsert instead of creating duplicates, company-name resolution) without
  writing anything; the browser renders an **editable** preview table;
  `commit()` receives that same (possibly hand-corrected) data back from
  the resubmitted form and performs the actual writes. No server-side
  session state or staging table — the round trip is carried entirely by
  the form itself, consistent with the rest of the app's stateless-per-request design.
- Added `ContactImportController` (`/contacts/import`, `/contacts/import/preview`,
  `/contacts/import/commit`, `/contacts/export`) and three new templates.
- Routing note: `/export` and `/import` had to be registered *before*
  the `/{id}` wildcard route in the `/contacts` group — otherwise the
  wildcard would have greedily matched them as if they were a numeric
  contact id, the same class of ordering concern already handled
  correctly for `/create`.
- XLSX (Excel) import/export intentionally deferred — building a
  dependency-free XLSX reader/writer (no Composer at runtime, per the
  hosting constraints) is real, untestable-here work; CSV opens fine in
  both Excel and Google Sheets in the meantime. Revisit once on a VPS
  where installing a proper library becomes practical.

### Architecture correction — module entitlement vs. per-user restriction
A genuine design mistake from Phase 4, caught by real usage rather than
by review: Org Admin was able to enable/disable a module for their own
organization via `/org/modules`. That's wrong for a commercial product
where a module (e.g. Sales) is something an organization is **sold** —
per Phase 0 §6's own resolution chain, "organization entitled" sits
between "globally enabled" and "user override," and entitlement should
never be a decision the customer's own admin can make unilaterally.

- **`ModuleService::setOrganizationEnabled()` removed**, replaced by
  `setOrganizationEntitlement()` — Super Admin only, takes an explicit
  `$organizationId` (temporarily pinning `OrganizationContext` to that
  org via a new `OrganizationContext::runAs()` helper, since a Super
  Admin has no organization of their own).
- Added `OrganizationModuleAdminController` and
  `/admin/organizations/{id}/modules` — the real commercial "grant/
  revoke" control, reachable from a new "Manage modules" link on
  `/admin/organizations`.
- **`OrgModulesController` reworked**: `/org/modules` is now read-only
  for entitlement. What Org Admin actually controls is new: per-user
  restriction on an already-entitled module (`/org/modules/{id}/access`),
  using the `user_module_overrides` table and repository that existed
  since Phase 4 but had no write path until now (`ModuleService::userAccessForModule()`/`setUserAccess()`).
- No new permission migration needed — `modules.manage` (already
  granted to `org_admin` since Phase 4) is repurposed in code to mean
  "manage per-user access within my org," not "manage my org's
  entitlement." Super Admin's entitlement actions bypass RBAC entirely
  (a direct `is_super_admin` check, same pattern as `/admin/modules`).

### Phase 6 — Sales (Tasks enhancement)
- Tasks now support multiple assignees (new `task_assignees` pivot table, module-owned) instead of a single assignee — `tasks.assigned_to_user_id` is kept as a denormalized "primary assignee" for simple queries, but authorization's OWN scope now checks the full assignee list (plus the task's creator).
- Tasks now have a dedicated detail page (`/tasks/{id}`) with an editable description, comments, and attachments — comments and attachments deliberately reuse Phase 5's shared `NoteService`/`AttachmentService` (subject type `'task'`) rather than inventing new concepts or tables.
- Both "add task" forms (My Tasks page, Deal detail page) updated to a multi-select assignee picker.

### Phase 6 — Sales
- Added the first real toggleable module (`modules/Sales/`): `pipelines`, `pipeline_stages`, `deals`, `tasks` tables (module-owned, tracked in `module_migrations` separately from core).
- Added `deals.*`, `pipelines.manage`, `tasks.*` permissions granted to `org_admin`/`member` via a new core migration.
- Added `PipelineService` (with auto-created default pipeline), `DealService` (CRUD + stage changes with won/lost handling), `TaskService`, and their controllers, wired through the exact `ModuleInterface::register()`/`routes()` mechanism Phase 4 built — the first real proof that mechanism works end to end, not just for the Demo module.
- Added Kanban board (simple "move to stage" dropdown, not drag-and-drop), deal list/form/detail, pipeline/stage settings, and a "My Tasks" page (the agreed calendar trim for this phase).
- Nav links for Deals/Tasks only appear when Sales is actually enabled for the viewer, via `ModuleAccessResolver` (a convenience check — `ModuleMiddleware` is what actually enforces it).
- Proactively avoided, rather than re-discovered by a crash, the three bug classes Phase 5 surfaced: correlated-subquery vs. `tenantFindMany`'s WHERE-injection, duplicate named PDO placeholders, and closure-variable-scoping in route groups — all swept clean before delivery.
- Documented in `docs/phase6-sales.md`, including the three MVP trims agreed at kickoff (simple Kanban, placeholder currency column, tasks list instead of a calendar) and the still-open gap around wiring Tasks into Core entity pages (needs a proper extension-point mechanism, not built yet).

### Phase 5 — CRM Core
- Added `tags`, `taggables`, `notes`, `custom_field_definitions`, `custom_field_values`, `timeline_events`, `attachments`, `companies`, `contacts`, `leads` tables; granted the new permissions to `org_admin`/`member` via a new migration.
- Added shared, reusable infrastructure (usable by any future module): `TimelineService`, `TagService`, `NoteService`, `CustomFieldService`, `AttachmentService` (secure upload — real MIME check, extension allow-list, randomized filenames, 10MB cap).
- Added `CompanyService`, `ContactService`, `LeadService` (with lead→contact/company conversion), `SearchService` (SQL LIKE-based, permission-filtered).
- Extended `Request` with `file()` for uploaded-file access.
- Added `ContactController`, `CompanyController`, `LeadController`, a shared `RecordSupportController` (notes/tags/attachments across all three entity types), `CustomFieldAdminController`, `SearchController`, and the full web UI for all of it.
- Fixed a real bug caught during development: a contacts↔companies join would have hit the same "ambiguous organization_id" trap as any join across two tenant-scoped tables — fixed with a correlated subquery instead, and flagged for future joins.
- Documented in `docs/phase5-crm-core.md`.

### Phase 4 — Module Manager
- Added `modules`, `organization_modules`, `user_module_overrides` tables; added `modules.manage` permission to the `org_admin` system role via a new migration (not an edit to the already-applied seed migration).
- Added `ModuleAccessResolver` (the Phase 0 §6 chain: installed → globally enabled → org entitled → user override), shared by `ModuleMiddleware` and `ModuleService` so the logic exists in exactly one place.
- Added `ModuleService`: sync-from-disk, Super Admin global enable/disable, Org Admin per-organization enable/disable — both with dependency validation (§8).
- `ModuleMiddleware` now enforces the full chain (Phase 1 only checked "installed").
- Modules can now register their own routes/services via a `bootstrap` class named in `module.json`, wired up in `Application::bootstrapModuleRoutes()`.
- Added Super Admin (`/admin/modules`) and Org Admin (`/org/modules`) UI.
- Added a working example module (`modules/Demo/`) — not a real feature, built to prove the whole pipeline (discovery → DB sync → global/org toggle → dependency checks → per-module migration → module-owned tenant-scoped data) on the live deployment before any real CRM module exists.
- `/system/migrate` now also discovers and runs every installed module's own migrations, tracked separately from core (`module_migrations`).
- Documented in `docs/phase4-module-manager.md`.

### Phase 3 — Organizations / RBAC
- Added `organizations`, `departments`, `teams`, `roles`, `role_permissions` tables; extended `users` with department/team/role/is_super_admin and the organization FK deferred from Phase 2.
- Seeded system role templates (`org_admin`, `member`); promoted pre-Phase-3 accounts to Super Admin (one-time backfill).
- Added `Scope` enum, `Permission` catalogue, and the single reusable `AuthorizationEngine`.
- Added `OrganizationService`, `UserManagementService`, `RoleService`, `OrgStructureService`, each permission-checked.
- `AuthMiddleware`/`RbacMiddleware` are now fully real; `TenantMiddleware` retired to a documented no-op now that `OrganizationContext` is resolved globally per request in `Application.php` (same fix pattern as Phase 2's `CurrentUser` global-resolution fix).
- `AuthService::register()` now creates a new organization per signup (self-service = new tenant); a separate `UserManagementService::create()` flow invites teammates into an existing org.
- Added org admin web UI: `/org/settings`, `/org/users`, `/org/roles`, `/org/structure`.
- Fixed a real bug caught during development: `OrganizationRepository::update()` originally had a signature incompatible with its parent class (fatal PHP error) — renamed to `updateOrganization()`.
- Cleaned up leftover `use PDO;` imports in three Phase 2 migration files for consistency with the Phase 2 `ErrorHandler`/import fix.
- Documented in `docs/phase3-organizations-rbac.md`, including this phase's decisions (org-per-signup, Super Admin backfill, single role per user, `is_super_admin` as a separate flag, ALL/ORGANIZATION scope equivalence, global tenant-context resolution, defense-in-depth RBAC checks).
- Post-delivery fixes from real-device testing: made the admin pages/header responsive (wrapping nav, scrollable tables, a mobile breakpoint), fixed a CSS cascade bug (`.btn` was accidentally redefined twice), and added a minimal read-only Super Admin panel (`/admin/organizations`) so a Super Admin account isn't a dead end.

### Phase 2 — Authentication
- Added `users`, `auth_tokens`, `login_attempts` core migrations.
- Added `AuthService`: registration, login, logout, email verification, password reset, profile updates.
- Implemented `AuthMiddleware` for real (was a Phase 1 placeholder); added `CurrentUser` per-request context mirroring `OrganizationContext`.
- Added brute-force login throttling (`LoginThrottle`), a minimal `Validator`, and a `MailerInterface` (log-file driver by default, `php_mail` driver for real delivery on shared hosting).
- Added web auth pages (login/register/forgot-reset password/email verification/profile) and `/api/v1/auth/*` endpoints.
- Added a token-gated `/system/migrate` route as a shared-hosting-without-SSH stopgap for running migrations.
- Added `config/auth.php`, `config/mail.php`; extended `.env.example`.
- Documented in `docs/phase2-authentication.md`, including the decisions made this phase (nullable `organization_id` now, platform-wide-unique email, session-based API auth for now).

### Phase 1 — Foundation
- Added application bootstrap/kernel (`App\Core\Application`).
- Added zero-dependency autoloader, config loader, and `.env` support.
- Added PDO connection wrapper and migration runner (`Migrator`).
- Added router (static/param/wildcard routes, groups, middleware), request/response objects.
- Added middleware pipeline with `AuthMiddleware`/`TenantMiddleware`/`RbacMiddleware` placeholders, and functional `ModuleMiddleware`/`CsrfMiddleware`.
- Added `BaseController`, `BaseApiController`, `BaseService`.
- Added `BaseRepository`, `TenantScopedRepository`, `OrganizationContext`, `SuperAdminContext` (tenant isolation mechanism).
- Added file logger with defensive secret redaction, and a safe global error handler.
- Added session/CSRF/password-hashing security foundation.
- Added theme loader + template engine, default baseline theme (RTL/LTR aware).
- Added Module Manager foundation: `module.json` manifest parsing and filesystem discovery (`ModuleRegistry`). No modules exist yet.
- Added PHPUnit test suite (dev-only) covering the router, container, tenant context, and module manifest parsing.
- Added Phase 1 documentation (`docs/phase1-foundation.md`) and deployment notes for shared cPanel hosting.

### Phase 0 — Architecture
- Rev. 1: initial architecture proposal (module map, RBAC/scope model, multi-tenancy, wallet ledger, folder structure, migration strategy, roadmap).
- Rev. 2: added centralized Currency Management, multi-currency, exchange-rate architecture, crypto-readiness.
- Rev. 3: finalized all previously open approval items (Currency Management as CORE, one-wallet-per-currency, `DECIMAL(38,18)` precision, `currency_id` FK principle, transaction-time default reporting policy) as recorded architecture decisions.
