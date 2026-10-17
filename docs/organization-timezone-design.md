# Organization Timezone — Audit & Design (infrastructure phase)

Status: **Implemented in development (2026-10-15), approved as "Foundation + UTC
storage standardization". Not deployed to production** — see §3 for the
deployment procedure (needs its own approval). §0–1 are the original audit
and design; §2–4 describe what was built.

## 0. Audit — current state (read-only, verified 2026-10-15)

### Storage
- `organizations.default_timezone VARCHAR(64) NOT NULL DEFAULT 'UTC'`
  **already exists** (core migration `0004`). All 6 local organizations have
  `UTC` (the column default — nobody ever chose it).
- It is **written but never read**: Org Settings shows it as a free-text
  `<input>`; `OrganizationService::update()` saves any non-empty string with
  **no validation** (`+03:30`, `foo` are accepted).
- New organizations (`OrganizationService::create()`) get the column default
  `UTC`.
- Related, also unused for computation: `users.timezone` (default `UTC`,
  free text on the profile page) and `follow_ups.timezone` /
  `follow_up_recurrences.timezone` (default hard-coded `Asia/Tehran`, only
  displayed next to `due_at`).

### Application (PHP) timezone
- `Application::configurePhpRuntime()` → `date_default_timezone_set(config
  app.timezone)`; `.env APP_TIMEZONE=UTC`. One global value for every
  request and every organization.

### Database (MySQL) timezone
- Connection init: `SET NAMES utf8mb4, sql_mode=…` — **no `time_zone`**, so
  the session uses the server's: locally `@@time_zone = SYSTEM` =
  *Iran Standard Time* (+03:30). Production value: **unknown**.
- MySQL named time-zone tables are **not loaded** locally
  (`CONVERT_TZ(…,'UTC','Asia/Tehran')` = NULL) — typical of shared hosting.
  ⇒ conversions to an organization's zone must be done in PHP, not SQL.
- `DEFAULT CURRENT_TIMESTAMP` / `ON UPDATE CURRENT_TIMESTAMP` on almost every
  table's `created_at` / `updated_at` → stamped in **MySQL session time**.
- `NOW()` in queries: Marketing only (`MessageRepository`, `SegmentRepository`,
  `SqlParams` — deliberately on the DB clock since the last audit),
  `ExchangeRateRepository`, `Migrator`.

### Timestamps generated in PHP
- `date('Y-m-d H:i:s')` in **63** places (completed_at, archived_at,
  deleted_at, sent_at, queued_at, …) → **PHP time = UTC**.
- `date('Y-m-d')` as **business "today"** in ~30 places: Dashboard tasks
  widget (overdue / due today), Finance (issue_date defaults, installment
  overdue, report end date, expense date), Contracts (`today` filters),
  Appointments (default day, calendar "today"), Wallet gift expiry,
  Projects (actual end date), form default values in 9 templates.
  At 00:00–03:30 Tehran time these still say "yesterday".

### Display
- Templates print raw DB strings (`e($row['created_at'])`, ~63 places) or
  `LocaleFormatter::date()` (calendar conversion Jalali/Gregorian only —
  **no time-zone conversion**). Times are therefore shown in whatever zone
  they were stored in.
- Date fields use `crm-datepicker` (calendar only, value is ISO `Y-m-d`).

### Organization context
- `Application::resolveOrganizationContext()` → `OrganizationContext::set(
  user.organization_id)` per request; workers use
  `OrganizationContext::runAs()`. Org Settings update takes the org id from
  `CurrentUser`, never from input (tenant-safe already).

### Date/time helpers
- None central. `LocaleFormatter` (calendar/digits), ad-hoc `date()`,
  `strtotime()` (38 places), Marketing `SqlParams::now()`.

### Known inconsistencies
1. **Mixed storage:** MySQL-stamped columns are in MySQL session time
   (locally Tehran), PHP-stamped columns are UTC — same table, 3.5 h apart
   (e.g. `marketing_messages.created_at` vs `sent_at`,
   `tasks.created_at` vs `completed_at`).
2. Business "today" is UTC for every organization.
3. The organization timezone setting exists but does nothing and is not validated.
4. Three unrelated timezone fields (org, user, follow-up) with different defaults.

## 0b. Storage & production compatibility audit (for D3, read-only)

### Column types (crm_local, 125 tables)
| Kind | Count | Who writes the value |
|---|---|---|
| `DATETIME DEFAULT CURRENT_TIMESTAMP` | 101 | MySQL (insert) |
| `DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | 50 | MySQL (insert + every update) |
| `DATETIME` without default | 53 | the application (PHP `date()` / user input) |
| `DATE` | 26 | user input / PHP `date('Y-m-d')` |
| `TIME` | 2 | user input (appointment working hours) |
| **`TIMESTAMP`** | **0** | — |

No `TIMESTAMP` columns ⇒ MySQL never converts stored values; changing the
session `time_zone` changes only what `CURRENT_TIMESTAMP`/`NOW()` produce
from then on — existing values read back unchanged.
Explicit overrides of a defaulted column: only `rate_limit_hits.created_at`
(PHP, compared with PHP — consistent).

### Three storage categories (today)
- **C1 — MySQL-stamped instants** (151 columns: `created_at`, `updated_at`, …):
  stored in the **MySQL session zone**.
- **C2 — PHP-stamped instants** (~45 of the 53: `completed_at`, `sent_at`,
  `expires_at`, `enabled_at`, `last_login_at`, …): stored in **UTC**.
- **C3 — local wall-clock values typed by users** (`appointments.starts_at/
  ends_at`, `appointment_working_hours.*`, `tasks.due_at`,
  `follow_ups.due_at`, `follow_up_reminders.scheduled_at` (derived from
  due_at), `client_record_sessions.session_at`, all 26 `DATE` columns):
  **organization-local**, no zone attached. Must never be converted.

### Production (evidence: `cm1sgrup1_crmcluade.sql`, phpMyAdmin export of 2026-10-02)
- Server: **MariaDB 10.5.29**, PHP 8.4 (tables there are `utf8mb4_general_ci`).
- Same-request pairs show a constant **+3:30** between MySQL and PHP values:
  `organization_modules#1` `enabled_at` (PHP) 05:36:01 vs `created_at`
  (MySQL) 09:06:01; `auth_tokens#3` `expires_at − 24 h` 18:38:11 vs
  `created_at` 22:08:11 (all 8 tokens and 6 module rows agree).
  ⇒ production **PHP = UTC, MySQL session = Asia/Tehran (+03:30)** — the same
  mix as crm_local. Iran has had no DST since 2022, so C1 is a fixed +3:30
  for all existing data (2026). Confirm once on the server:
  `SELECT @@global.time_zone, @@system_time_zone, TIMEDIFF(NOW(), UTC_TIMESTAMP());`
- All 5 production organizations have `default_timezone = 'UTC'`.

### Existing defects found (backlog — not changed in this phase)
C3 values compared with PHP UTC "now": follow-up overdue filter
(`FollowUpRepository` `due_at < :now`), reminders (`scheduled_at <= :as_of`),
client-record next/last visit (`session_at` vs `$now`) — all ~3.5 h late;
business "today" (`date('Y-m-d')`, ~30 sites) wrong 00:00–03:30 local.

### Why switching the session to `+00:00` cannot be done without a rewrite
After a switch, a C1 column would hold old values in +03:30 and new values
in UTC. By value alone the window `[cutover, cutover + 3:30)` is ambiguous,
and `ON UPDATE` columns are rewritten in UTC on old rows while `created_at`
of the same row stays +03:30 — a per-table id cutover can't tell them apart.

### Proposed rollout (historical — superseded by §2 and the runbook `docs/deploy-organization-timezone-utc.md`)
- **T0 — this phase (no DB session change):** the resolver knows the
  storage zone of each category explicitly: C1 = `database.timezone`
  (new config; default: detected once per request by
  `TIMEDIFF(NOW(), UTC_TIMESTAMP())`, which needs no tz tables), C2 =
  `UTC`, C3 = organization-local (never converted). Nothing reinterpreted.
- **T1 — later, separate approval:** pin the session explicitly to the
  current zone (`+03:30`) via `DB_SESSION_TIMEZONE`, so a host change can't
  silently shift new data. No behaviour change.
- **T2 — later, separate approval, maintenance window:** the real UTC switch:
  backup → stop cron → one deterministic, reversible migration shifting C1
  columns by −3:30 (down = +3:30; C2/C3/DATE untouched) → enable session
  `+00:00` in the same deploy → resolver C1 = UTC. This **is** an explicit
  rewrite of historical C1 values, so it needs its own approval; the
  alternative is to stay on T0 permanently (deterministic, documented).

### Migration convention
Core migrations: `database/migrations/NNNN_description.php`, anonymous class
implementing `MigrationInterface` (`up`/`down`), tracked in `migrations`;
latest is `0082`. `0083` does **not** exist yet. D2 needs one data-only
migration (`0083_normalize_organization_timezones`); no schema change.

## 1. Design

### D1 — Storage column: reuse `organizations.default_timezone`
No new column. It already exists, is `NOT NULL`, and is what Org Settings
edits. (Alternative: add `organizations.timezone` and leave the old column —
rejected: two sources of truth.)

### D2 — Default value (needs your decision)
- **D2-a (recommended):** existing organizations whose value is still the
  never-chosen `UTC` (or invalid/empty) → `Asia/Tehran`; new organizations →
  `config app.organization_timezone` (`.env ORGANIZATION_TIMEZONE`, default
  `Asia/Tehran`). Reason: the product is Iran-first (Kavenegar, Jalali,
  follow-ups already default to `Asia/Tehran`), and `UTC` was never a choice.
- **D2-b:** keep `UTC` for existing organizations (zero visible change; each
  Org Admin picks their zone); invalid values → `UTC`.

The migration only rewrites values that are **invalid or empty** (D2-b) or
additionally the default `UTC` (D2-a). It never touches any timestamp.

### D3 — Storage timezone (architecture item — needs your decision)
To show a stored time in an organization's zone we must know the zone it
was stored in. Today it is mixed (inconsistency 1).
- **D3-a (recommended):** declare **UTC as the storage zone** for *new*
  writes: PHP stays `UTC` (already), and the connection sets the **session**
  `time_zone = '+00:00'` (session only — not global, not an organization
  zone, works without tz tables). After this, `CURRENT_TIMESTAMP`, `NOW()`
  and PHP `date()` all write UTC.
  *Effect on old rows:* MySQL-stamped values written before the switch are
  in the old server zone (locally +03:30) and are **not converted** (no
  bulk rewrite, per scope); they will be read as UTC, i.e. up to 3.5 h off
  in display/relative filters for history only. Before deploying, check
  production with `SELECT @@global.time_zone, @@system_time_zone;` — if it
  is already UTC there is no shift at all.
- **D3-b:** don't touch the DB session; conversions treat MySQL-stamped and
  PHP-stamped columns differently (fragile; every caller must know the
  source of each column). Not recommended.

### D4 — Resolver: `App\Infrastructure\Time\OrganizationTimezone`
The single source; nothing else reads the column or config for this.
- `forOrganization(int $id): \DateTimeZone` — per-request memo; invalid →
  fallback default.
- `current(): \DateTimeZone` — `OrganizationContext` org (works in
  requests and `runAs()` workers), else the configured default.
- `now(): \DateTimeImmutable` (in the org zone), `today(): string` (Y-m-d).
- `toLocal(?string $stored): ?\DateTimeImmutable` — storage (UTC) → org zone.
- `toStorage(\DateTimeInterface|string $local): string` — org zone → UTC `Y-m-d H:i:s`.
- `dayRange(string $ymd): array{0: string, 1: string}` — start/end of that
  organization day in storage time (for `>= start AND < end` queries; handles DST days of 23/25 h).
- `daysAgoStart(int $n): string` — start of "last N days" in storage time.
- `isValid(string $id): bool`, `choices(): array` — IANA identifiers from
  `DateTimeZone::listIdentifiers()` only; offsets like `+03:30` / `GMT+3:30` rejected.
- Uses explicit `DateTimeImmutable` + `DateTimeZone`; **never** calls
  `date_default_timezone_set()`; PHP's global zone stays the storage zone.
- Template helper `local_datetime(?string $stored, string $part = 'datetime')`
  in `Theme/functions.php` for future display use (formats via `LocaleFormatter`).

### D5 — Lifecycle
Nothing to "apply" globally: the resolver reads `OrganizationContext`
lazily, so each request/worker run gets its own organization's zone and two
organizations never affect each other. The memo is keyed by organization id.

### D6 — Validation & UI
- `OrganizationService::update()`: `default_timezone` must be a valid IANA id
  (`org.error.timezone_invalid`); empty → keep the current value.
- Org Settings: the text input becomes a `<select>` grouped by region
  (`<optgroup>`), each option `(UTC+03:30) Asia/Tehran`, current value
  selected, `field-error` below. Existing form pattern, no new CSS.
- Translation keys in **all five** language files (CLAUDE.md rule):
  label `org.timezone` ("Timezone" / "منطقه زمانی"), hint, error.

### D7 — Adoption scope in this phase
Foundation only. **No existing module call site is changed** (Finance,
Tasks, Contracts, Projects, Appointments, Marketing keep their current
code). The ~30 business-"today" sites and ~63 raw display sites are listed in
the final report as the adoption backlog, to migrate module by module later
with your approval. *(Optional, if you want one visible proof: the dashboard
tasks widget "overdue / due today" — one line, `today()` from the resolver.)*

### D8 — Out of scope (reported, unchanged)
`users.timezone` (personal zone) and `follow_ups.timezone` keep working as
today; whether a user zone should override the organization zone is a later
decision.

### Tests (after approval)
New suite `OrganizationTimezoneTest`: Tehran vs Baku isolation (incl.
`runAs`), valid/invalid/offset/empty values, existing organizations valid
after migration, same stored instant displayed differently per
organization, today / yesterday / start & end of day / last N days in the
org zone independent of PHP's zone, a DST zone (`Europe/Berlin` 23-h and
25-h days), cross-tenant: a user cannot change another organization's zone
(id from input ignored; other org row unchanged). Regression: full suite;
plus all migrations from scratch on a throw-away database `crm_tz_test`
(created and dropped locally).

### Migration (original plan — superseded by §2)
Core `0083_normalize_organization_timezones`: data only — fix invalid/empty
(and per D2-a the default `UTC`) values. Down: no-op (values stay valid).
No schema change; no timestamp touched.
*(Approval then extended the phase to UTC storage: the UTC conversion became
0083 and the organization normalization 0084 — see §2.)*

## 2. What was implemented

### Storage model (from migration 0083 on)
| Category | Columns | Stored as | How to use |
|---|---|---|---|
| **C1** MySQL-stamped instants | 151 `DATETIME DEFAULT CURRENT_TIMESTAMP [ON UPDATE]` columns in 111 tables (frozen list in 0083) + derived copies `contact_import_batch_items.stamp`, `marketing_campaigns.audience_snapshot` `cutoff` / `as_of` | **UTC** (converted once by 0083; new rows UTC because the session is `+00:00`) | display with `local_time()`; filter by calendar day with `OrganizationTimezone::dayRange()` |
| **C2** PHP-stamped instants | `completed_at`, `sent_at`, `expires_at`, `closed_at`, … (~45) | **UTC** (unchanged; PHP's global zone is always UTC) | same as C1 |
| **C3** user-entered local values | `tasks.due_at`, `follow_ups.due_at`, `follow_up_reminders.scheduled_at`, `appointments.starts_at` / `ends_at`, working hours (`TIME`), `client_record_sessions.session_at`, `contracts.signed_at` (see backlog 6), all `DATE` columns | **organization-local wall clock**, never converted | compare with `OrganizationTimezone::localNow()` / `today()` |

### UTC enforcement
- **PHP:** `Application::configurePhpRuntime()` always sets `UTC` (`OrganizationTimezone::STORAGE`); `APP_TIMEZONE` is no longer read (`config/app.php`).
- **MySQL:** `Connection::pdo()` calls `Connection::applyStorageTimezone()`, which runs `SET time_zone = '+00:00'` on every application connection **once `storage_timezone_state` has its row** (written by 0083 in the same transaction as the conversion). Before that (fresh install, or new code uploaded but not yet migrated) the server zone is kept, so a table never mixes zones. An offset, not a named zone: no MySQL time-zone tables, no dependence on the host's zone. Cost: one tiny query per request. Only "table does not exist" (SQLSTATE 42S02 / error 1146) is treated as "not converted yet"; any other error on that check is rethrown, so a request fails rather than writing old-zone timestamps into converted tables (`OrganizationTimezoneTest` TZ12/TZ13).
- Global MySQL settings and the server's zone are never changed.

### Organization time zone
- `organizations.default_timezone` (existing column): strict IANA id (`DateTimeZone::listIdentifiers()`), default `Asia/Tehran` (`ORGANIZATION_TIMEZONE`). 0084 changed `UTC` / empty / invalid values to `Asia/Tehran`, kept explicit non-UTC zones and `updated_at`, and recorded what it changed (`organization_timezone_normalized`) so `down()` can restore it.
- `App\Infrastructure\Time\OrganizationTimezone` is the single source: `forOrganization`, `current` (follows OrganizationContext, also in `runAs()` workers), `now`, `today`, `localNow`, `toLocal`, `toStorage`, `dayRange` (DST-correct 23/25-hour days), `daysAgoStart`, `isValid`, `choices`, `offset`, `forget`. It never changes PHP's global zone.
- Org Settings: grouped dropdown "(UTC+03:30) Asia/Tehran", server-side validation (`org.timezone_invalid`), tenant-safe (organization id from the session + `assertCanAccess`); 4 new keys in all five languages.
- Display: `local_time()` helper; 51 display sites of C1/C2 instants (49 `local_time()` calls) in 39 templates show organization-local time. C3 sites untouched.
- Dashboard tasks widget: "overdue / due today" use the organization's `today()`.
- Marketing segments: `created_at` before / after / on use the organization's day (`dayRange`); "last N days" stays a rolling window (`NOW() - INTERVAL`).

### Migrations
- **0083_convert_timestamps_to_utc_storage**: creates `storage_timezone_state`; converts C1 value by value, by its own instant under the legacy zone `@@global.time_zone` (DST-safe, no tz tables); all C1 columns of a table in ONE UPDATE so ON UPDATE never fires (also for the derived snapshot rewrite); one transaction; idempotent; values outside 1970-01-02 … 2038-01-18 untouched; unverified C1-like columns are listed, not converted. `down()` converts back with the recorded zone and drops the state.
- **0084_normalize_organization_timezones**: column default `Asia/Tehran`; value normalization as above; `down()` restores values and the `UTC` default.
- The `down()` methods are for development and testing only. They are **not** an authorized production rollback; production rollback is restoring the verified pre-deployment backup and the previous application files (`docs/deploy-organization-timezone-utc.md` §7).

### Verification
- Disposable copy of crm_local: up converted 154 columns (incl. derived), rerun was a no-op, down restored the table checksums exactly.
- Fresh empty database: all core + module migrations; 0083 converted the 58 core columns; module tables were then created in UTC; a new connection comes up at `+00:00`.
- Disposable copy of the production export (2026-10-02, MariaDB dump): all pending migrations incl. 0083/0084; afterwards MySQL `created_at` equals the PHP-written instants exactly (`auth_tokens`, `organization_modules`); 107 columns converted, 0 unverified, 5 organizations → `Asia/Tehran`. All disposable databases were dropped.
- `OrganizationTimezoneTest` (13), `MarketingSegmentsAuditTest` updated; full suite 771/771; 13/13 mutations killed.

## 3. Production deployment procedure

Superseded by the dedicated runbook **`docs/deploy-organization-timezone-utc.md`** (preflight checks on the server, maintenance mode with an authorized-IP exception, `.htaccess` re-applied after extraction, cron stop, verified backups, single `/system/migrate` run, stop conditions, validation, go-live). The earlier short procedure in this section did not stop concurrent traffic and offered a down-migration rollback; both are withdrawn. **Rollback is Option A only:** restore the verified pre-deployment database backup and the previous application files; this discards all database changes made after the backup.

## 4. Remaining timezone backlog (business logic not yet on the resolver)

Not changed in this phase; each needs its own audited change. **Application-wide timezone correctness must not be assumed until these are done.**

**P1 — DONE (2026-10-15, development; `TimezoneBusinessLogicTest`, 11 tests, 12/12 mutations killed)**

Rule used everywhere: C3 values (local wall clock / business dates) are compared with `OrganizationTimezone::localNow()` / `today()` of the organization that owns them; nothing is converted, no data changed. Templates use the new helpers `local_now()` / `local_today()`.

1. Follow-ups overdue: `FollowUpRepository::search` (`overdue_only`) and the "Overdue" marks in `followups/index.php`, `followups/show.php`, `client-records/tabs/followups.php` use the organization's local now.
2. Follow-up reminders: `FollowUpReminderRepository::duePending()` (no caller yet — the sweep is not built) now joins the follow-up's organization and compares `scheduled_at` with that organization's local now (cross-organization safe; DST-correct). The rule engine (`FollowUpRuleEngineListener`) computed an automatic follow-up's `due_at` as UTC + N hours and stored it as a local value (3.5 h early in Tehran) — now "N hours from the organization's local now", and the reminder offset is DST-aware.
3. Client records: next visit (`ClientRecordRepository::search`), last/next visit summary (`ClientRecordController::summary`), the "upcoming in 7 days" KPI, the appointments tab grouping (`client-records/tabs/appointments.php`) and the new-session date/time defaults (`ClientTreatmentController`) use the organization's local time.
4. Appointments: `AvailabilityService::freeSlots` cuts past slots at the organization's local now; `CalendarService::week` "today", the calendar's default day and "Today" button, list and form defaults use the organization's date. Appointment times themselves are untouched.
5. Business "today": `ContractRepository` (active/expired/expiring filters), `ContractService::effectiveStatus`, `InstallmentService::contractStatus` and installment "overdue", default issue dates in `InvoiceService`, `ProjectInvoiceService`, `ContractBillingService` (×2), `CreditNoteService`, expense date defaults (`ExpenseService`, `ExpenseController`), report end date (`ReportController`), project actual end date (`ProjectService`), Wallet gift expiry (`WalletGiftService`, the redeeming organization's date), and the date-field defaults in 7 templates (`finance/expenses/form|show`, `finance/invoices/form|show` ×2, `finance/proformas/form`, `contracts/show`, `projects/show`, `client-records/_upload`).
6. (was P2) `contracts.signed_at` without a typed date is now the organization's local now — consistent with the typed-date case. Rows activated before this keep their old (UTC) value; only the date part is shown.

Intentionally unchanged (not business dates): file names of CSV exports and log files (P3 below), session/rate-limit/token timers (`time()` — instants), Marketing worker timestamps (UTC instants, C2).

Known limitation: Super Admin cross-organization lists (no organization context) evaluate "overdue" / "today" in the default zone (`ORGANIZATION_TIMEZONE`).

**P2**
6. *(moved to P1 item 6 — fixed for new activations.)*
7. JSON API (`ClientRecordApiController`) returns raw UTC strings without a zone — should become ISO 8601 with an offset.
8. `users.timezone` and `follow_ups.timezone` exist but are unused; decide whether a personal zone overrides the organization's.
9. Super Admin screens (no organization) show all organizations' data in the default zone.

**P3**
10. CSV export file names and log file dates use UTC dates (cosmetic).
