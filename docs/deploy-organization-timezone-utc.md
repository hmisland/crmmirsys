# Deployment runbook — Organization Timezone + UTC storage (migrations 0083 / 0084)

**Current status (2026-10-16): `PREFLIGHT PASS — CONDITIONAL ON Q11 VERIFICATION — NOT YET APPROVED TO DEPLOY`**
— the preflight results are recorded in §3a; Q11 passes only after the W0
change to `max_execution_time = 120` has been confirmed with a screenshot, and
the deployment itself has not been approved.

Status: **not deployed.** This runbook is the only approved procedure for
taking the Organization Timezone / UTC-storage release (core migrations
`0083_convert_timestamps_to_utc_storage`, `0084_normalize_organization_timezones`,
plus the P1 business-logic fixes) to production. Design and background:
`docs/organization-timezone-design.md`.

**Rollback policy: Option A only** — restore the verified pre-deployment
database backup *and* the previous application files (§7). Running down
migrations on production is **not** an authorized rollback path. Restoring the
backup **discards every database change made after the backup was taken**;
it is not data-preserving.

Roles: one **operator** (performs every step), working from **one fixed
internet connection** whose public IP is the "authorized IP" for the whole
window. Keep this IP stable (no mobile hotspot switching, no VPN changes).

---

## 0. Why the order matters (read once)

- Until migration 0083 commits, every database connection keeps the server's
  zone (Tehran); after it commits, **new** connections switch to UTC
  (`Connection::applyStorageTimezone()`, checked once per connection; only a
  missing `storage_timezone_state` table means "old zone" — any other error on
  that check fails the request instead of writing old-zone timestamps).
- A request, browser tab or cron worker whose connection was opened *before*
  the commit keeps the old zone for its whole life. If it writes after the
  commit it stores an old-zone value into an already converted table — a
  permanently mixed row. **Therefore all traffic and all cron jobs must be
  stopped before the backup and stay stopped until validation is complete.**
  This includes the Marketing campaign page, which sends queued messages from
  the browser while it is open.
- `upload.zip` contains **both** `.htaccess` (account root) and
  `public/.htaccess`. Extracting it **overwrites** any maintenance rule. The
  rule must be re-applied immediately after extraction (§5 step D3).
- Migration 0083 is atomic (one InnoDB transaction) **only if every table is
  InnoDB**. Migration 0084 is not atomic but is safe to re-run.
- `/system/migrate` runs pending core migrations in file order, then each
  module's pending migrations, and **stops at the first failure** (the failed
  migration is not recorded; later ones do not run).

---

## 1. Facts verified in development vs. checks required on production

### 1.1 Verified (development / disposable databases, 2026-10-15)
- Full test suite 782/782; timezone mutation checks 13/13 and 12/12 killed.
- 0083 converts exactly the 151 verified C1 columns in 111 tables plus two
  derived copies; it is idempotent; on a copy of the local database the
  down/up round trip restored identical checksums.
- On a disposable copy of the **2026-10-02** production export, all pending
  migrations including 0083/0084 ran; afterwards MySQL-stamped `created_at`
  equalled the PHP-written instants; 107 columns converted, 0 unverified.
- A fresh empty database migrates from scratch and comes up in UTC.

### 1.2 Evidence only — NOT proof of the current server
The 2026-10-02 phpMyAdmin export indicated: MariaDB 10.5.29, MySQL session
zone Tehran (+03:30), PHP writing UTC, 80 tables explicitly InnoDB and 2
tables (`notification_platform_settings`, `wallet_platform_settings`) whose
engine is missing from the export (their migrations create them as InnoDB),
no `TIMESTAMP` columns, all organizations on `default_timezone = 'UTC'`.
**The server may have changed since. Every item is re-checked in §3.**

### 1.3 Must be checked on production (§3) before anything is changed
Database zone and offset, MariaDB version, PHP `APP_TIMEZONE`, storage
engines, absence of `TIMESTAMP` columns, applied/pending migrations, the
active document root and `.htaccess`, every hostname that reaches the CRM,
the CRM cron list, Marketing campaign state, available disk space and
phpMyAdmin import size limit for backups, PHP execution time for
`/system/migrate`, and that a database backup can actually be restored on
this host.

### 1.4 Not demonstrated anywhere (accepted residual risk)
- 0083/0084 and the test suite ran on **MySQL 8.4** (local and disposable
  databases, including the imported production export). They have **not**
  been executed on a MariaDB server. The SQL used (`UNIX_TIMESTAMP`,
  `FROM_UNIXTIME`, `TIMEDIFF`, `TIME_FORMAT`, `SET time_zone`, `INTERVAL`
  arithmetic, `information_schema`) is supported by MariaDB 10.5, but this is
  documentation knowledge, not a test result. Mitigation: 0083 is a single
  transaction (§0), §6a validates the result, and §7 restores the verified
  backup.
- Whether the host honours `[R=503]` in `.htaccess` (Apache or LiteSpeed) is
  not known in advance; W2 tests it before anything else changes.

---

## 2. Preparation (any time before the window; nothing is changed)

P1. Build and keep the release package: `upload.zip` from
`tools/build-upload.ps1` (verify it contains no `.env`, `.sql`, `tests/`).
P2. Note the authorized IP (e.g. from an "what is my IP" page) **from the
connection that will be used**.
P3. In cPanel → Domains, note the **document root** of `crmgroup.ir`:
  - `…/public_html/public` → the active file is **`public/.htaccess`**;
  - `…/public_html` → the active file is the **root `.htaccess`** (it then
    rewrites into `/public`).
  If unsure, the maintenance rule is added to **both** files (it is harmless
  in an inactive file).
  Also list **every hostname that reaches the CRM** (cPanel → Domains /
  aliases: `crmgroup.ir`, `www.crmgroup.ir`, any alias or temporary URL). The
  maintenance rule's host condition must cover all of them; an uncovered
  hostname would bypass maintenance.
P4. Confirm the database user can create a second database (cPanel →
MySQL Databases) for the backup-restore check, that there is enough disk
space for: one SQL export, one cPanel DB backup, one compressed copy of the
application folders and one of `storage/`, and that phpMyAdmin's import limit
(shown on the Import page) is larger than the expected SQL export — otherwise
the W7 restore check cannot be done (stop until resolved).
P5. Prepare the maintenance snippet (replace `203.0.113.10` with the
authorized IP, dots escaped):

```apache
# >>> MAINTENANCE (timezone deployment) — remove after go-live
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTP_HOST} ^(www\.)?crmgroup\.ir$ [NC]
    RewriteCond %{REMOTE_ADDR} !^203\.0\.113\.10$
    RewriteRule ^ - [R=503,L]
</IfModule>
# <<< MAINTENANCE
```

It must be placed at the **very top** of the active `.htaccess`, before the
existing rules (including the existing `mirsys.ir` pass-through rule in the
root file). It only affects `crmgroup.ir` / `www.crmgroup.ir` — other sites on
the account (e.g. `mirsys.ir`) do not match the host condition and are not
blocked; W2 verifies this. Extend the host condition if P3 found other CRM
hostnames. If the site is behind a proxy/CDN, `REMOTE_ADDR` is the proxy's
address — check in §3 (Q9) and adapt the condition (e.g.
`%{HTTP:CF-Connecting-IP}`) before relying on it. If the authorized
connection uses IPv6, `REMOTE_ADDR` is the IPv6 address — use that address
instead (W2 shows whether the operator is let through). The rule fails
closed: a wrong IP blocks the operator too, never opens the site.

---

## 3. Preflight checks on production (read-only) — all must pass

Run in phpMyAdmin → the CRM database → SQL. Record every result.

| # | Check | Expected | Stop if |
|---|---|---|---|
| Q1 | Run both statements together: `SET time_zone = @@global.time_zone; SELECT @@global.time_zone, @@system_time_zone, TIMEDIFF(NOW(), UTC_TIMESTAMP()), VERSION();` (the application's connections use the global zone, so the offset is measured in it) | `SYSTEM` (or `+03:30`), an Iran zone name (e.g. `+0330`, `IRST`), `03:30:00`, MariaDB 10.5.x | offset ≠ `03:30:00`, or global zone is neither `SYSTEM` nor `+03:30` → **stop and report** (the conversion offset would differ). A different server product/major version → **stop and report** (§1.4) |
| Q2 | `SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND ENGINE <> 'InnoDB';` | empty | any row → **stop** (0083 would not be atomic) |
| Q3 | `SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'timestamp';` | empty | any row → **stop** |
| Q4 | `SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'storage_timezone_state';` | `0` | `1` → **stop** (a previous attempt ran; investigate) |
| Q5 | `SELECT migration FROM migrations ORDER BY migration;` | every applied name is one of the package's core files (`database/migrations/0001…0084`); **0083 and 0084 not applied**. Write down the **pending core list** = package files not in the table. | 0083/0084 already applied, or an applied name that is not in the package → **stop** |
| Q6 | `SELECT module_key, migration FROM module_migrations ORDER BY module_key, migration;` | every applied name exists in that module's `Migrations/` folder of the package (package counts: appointments 5, client_records 4, contracts 5, demo 1, finance 24, followups 5, internal_mail 6, knowledge 6, marketing 12, notifications 6, projects 5, sales 7, support 3, wallet 7). Write down the **pending module list**. | an applied name not in the package, or a module key not in the package → **stop** |
| Q5/6-A | **Approval of the pending lists.** Send the pending core list and pending module list to the developer before the window. Pending core migrations run **before** 0083 (in the old zone; 0083 converts what they write); pending module migrations run **after** 0084. | written confirmation that every pending migration is part of this release and reviewed | any pending migration not confirmed → **stop**; D5 must apply exactly these lists |
| Q7 | `SELECT id, status FROM marketing_campaigns WHERE status IN ('preparing','queued','sending');` (skip if the table does not exist) | empty | rows → wait until they finish or cancel them in the UI **before** §4; never start the window with an active campaign |
| Q8 | cPanel → File Manager → CRM `.env`: value of `APP_TIMEZONE` (if present) | `UTC` or absent | any other value → **stop and report** (historical PHP-written timestamps would not be UTC) |
| Q9 | Proxy/CDN in front of the site? (DNS points to the host directly, or to a CDN) | known | unknown → resolve before §4 (affects the maintenance rule) |
| Q10 | cPanel → Cron Jobs: **list every job** that runs anything inside the CRM folder (at least `modules/Marketing/bin/worker.php`, normally every minute). Record command and schedule. | complete list | a job whose purpose is unknown → clarify first |
| Q11 | cPanel → Select PHP Version (CloudLinux PHP Selector) → Options (PHP 8.3): `max_execution_time` | ≥ 60 s | lower → raise it to ≥ 60 s before the window; if it cannot be raised → **stop** (a timed-out migration run is an unknown state, §6) |

Baseline values to record (no pass/fail; used by §6a):
- B1 `SELECT default_timezone, COUNT(*) FROM organizations GROUP BY 1;`
- B2 Rows whose MySQL stamp is exactly 3:30 after the PHP stamp written in the
  same request (expected > 0 before the deployment, 0 after it):
  `SELECT COUNT(*) FROM organization_modules WHERE enabled_at IS NOT NULL AND TIMESTAMPDIFF(SECOND, enabled_at, created_at) BETWEEN 12599 AND 12601;`
- B3 Same for e-mail verification tokens (default TTL 1440 minutes; if `.env`
  sets `AUTH_EMAIL_VERIFICATION_TTL_MINUTES`, use that number instead of 1440):
  `SELECT COUNT(*) FROM auth_tokens WHERE type = 'email_verification' AND TIMESTAMPDIFF(SECOND, DATE_SUB(expires_at, INTERVAL 1440 MINUTE), created_at) BETWEEN 12599 AND 12601;`

**Proceed only if Q1–Q11 and Q5/6-A all pass.**

---

## 3a. Production preflight results (recorded 2026-10-16, read-only)

Source: read-only SQL run by the operator in phpMyAdmin on
`cm1sgrup1_crmcluade` (files `mariadb-preflight-0083-0084.sql` round 1 and
`mariadb-preflight-round2.sql` round 2, SELECT only), cPanel screenshots, and
public HTTP/DNS requests. Nothing was changed on production.

**Status: `PREFLIGHT PASS — CONDITIONAL ON Q11 VERIFICATION — NOT YET APPROVED TO DEPLOY`**

| Check | Result | Production value |
|---|---|---|
| Q1 / M0 | PASS | `@@global.time_zone = SYSTEM`, `@@system_time_zone = +0330`, `TIMEDIFF = 03:30:00`, `10.5.29-MariaDB`, `max_statement_time = 0`, `innodb_lock_wait_timeout = 50`, default engine InnoDB |
| M1 — 0083 conversion expression on MariaDB | PASS | `03:30:00`, `1792053000`, `2026-10-15 08:30:00`, `2026-03-20 20:40:00`, `2026-09-22 18:38:11`, guard kept `1970-01-01 03:30:00`, `03:30` — all exactly as expected |
| M2 — 0083 target columns missing / wrong type | PASS | empty |
| M3 — 0083 target columns present | PASS | 151 |
| R10 (M4) — auto-stamped DATETIME columns outside the 0083 list | PASS | empty |
| R1 (Q2) — non-InnoDB tables | PASS | empty |
| R2 (Q3) — `TIMESTAMP` columns | PASS | empty |
| R3 / R4 / R5 — triggers / generated columns / system-versioned tables | PASS | empty / empty / empty |
| R6 (Q4) — `storage_timezone_state`, `organization_timezone_normalized` | PASS | 0 |
| R7 — `organizations.default_timezone` | PASS | `varchar(64)`, `NOT NULL`, default `'UTC'`, `utf8mb4` / `utf8mb4_general_ci`, no comment; table collation `utf8mb4_general_ci` |
| R8 — data volume | PASS | largest tables: `timeline_events` ≈ 690, `contact_import_batch_items` ≈ 623, `contacts` ≈ 617 rows (InnoDB estimates) |
| R9 — campaign snapshots to convert | PASS | 0 |
| R11 / R12 (Q5) — core migrations | PASS | applied = package `0001…0082` exactly (82); pending core: only 0083, 0084 |
| R13 / R14 (Q6) — module migrations | PASS | applied = all 96 package module migrations; pending module migrations: none |
| Q5/6-A — pending lists | PASS | pending = `0083_convert_timestamps_to_utc_storage`, `0084_normalize_organization_timezones`; no module migrations |
| Q7 — active campaigns | PASS | none in `preparing` / `queued` / `sending` |
| Q8 — `APP_TIMEZONE` | PASS (operator's report) | `UTC` |
| Q9 — proxy/CDN | PASS | none: `crmgroup.ir`, `www.crmgroup.ir` and `mirsys.ir` resolve directly to `92.114.17.230` (reverse DNS `ps-hcp-ir-002.pouyasazan.org`); no proxy headers |
| P3 — document root | PASS | `crmgroup.ir` (main domain) → `/public_html` ⇒ the **active file is the root `.htaccess`** (it rewrites into `/public`). `mirsys.ir` → `/public_html/mirsys.ir` — inside the CRM folder, so the root `.htaccess` is also on its path: the maintenance rule's host condition is required (present in P5) and W2 must confirm `mirsys.ir` stays open. `www.crmgroup.ir` is not listed separately on the Domains page; it answers HTTP 200 publicly and is covered by the rule's `(www\.)?` |
| Q10 — cron jobs | see below | 2 jobs |
| Q11 — `max_execution_time` | **CONDITIONAL** | currently **30** — below the required 60. Decision: raise to **120** in W0 before the window, restore 30 in G4 / R9. Passes only after W0 is confirmed with a screenshot. |
| P4 — phpMyAdmin import limit | PASS | 300 MiB (the database holds a few thousand rows) |
| PHP version | CONFIRMED | `crmgroup.ir`: **PHP 8.3 via CloudLinux PHP Selector** (account default; cPanel PHP Selector → Per Domain Settings), matching `X-Powered-By: PHP/8.3.35`. `mirsys.ir`: PHP 8.1 via cPanel MultiPHP Manager (PHP-FPM). The CRM version is not set by `.htaccess`, and the package contains no handler, `php.ini` or `.user.ini`, so extracting it does not change the PHP version. Cron worker: `ea-php81` CLI (see C2). |
| Web server | INFO (inferred, not verified) | `alt-svc: h3` suggests LiteSpeed; `[R=503]` support is confirmed only by W2 |
| cPanel → Backup tool | NOT VERIFIED | no screenshot; the phpMyAdmin export (W6 first copy) is available. The second copy (cPanel MySQL backup) depends on the host offering that tool — check in P4 |

**Cron jobs (Q10)** — both disabled in W3 and restored in G2 / R7:

| # | Schedule | Command | What is known |
|---|---|---|---|
| C1 | `* * * * *` (every minute) | `wget -q -O- https://crmgroup.ir/crm/cron/index` | **Purpose not confirmed.** The path `/crm/cron/index` is not a route in the current CRM code (code inspection); with the current code it should receive a 404 (and a 503 during maintenance). Its response was not requested from production. It may be left over from an earlier system — this is an assumption, not a fact. It goes through the web server, so maintenance would block it, but it is disabled in W3 like every CRM job. Whether to delete it permanently is outside this deployment. |
| C2 | `* * * * *` (every minute) | `/usr/local/bin/ea-php81 /home/cm1sgrup1/public_html/modules/Marketing/bin/worker.php >/dev/null 2>&1` | The Marketing queue worker (sends queued campaign messages, then polls SMS delivery reports; ≤ 50 s per run by default). Runs on the CLI with **PHP 8.1**, while the website runs PHP 8.3.35. A static check of the new code found no PHP 8.2+ syntax; it was not linted on PHP 8.1. **CLI: maintenance mode does not stop it — it must be disabled in W3.** |

**Baselines for §6a (keep them):**

| Baseline | Before deployment | Required after migration |
|---|---|---|
| B1 — organization zones | **15 organizations, all exactly `UTC`** (no empty, padded or alias values) | all 15 `Asia/Tehran` (V3); `organization_timezone_normalized` = 15 rows (V3b) |
| B2 — `organization_modules` rows 3:30 apart | **70** | **0** (V4, HARD) |
| B3 — e-mail verification tokens 3:30 apart (TTL 1440 min) | **17** | **0** (V5, HARD) |
| M3 — 0083 targets present | 151 | V1: `unverified_columns = []`, `legacy_offset = 03:30` |

**Expected migration run (D5):** core `0083_convert_timestamps_to_utc_storage`,
then `0084_normalize_organization_timezones`; modules: none.

**Open items before deployment approval:** W0 confirmation (Q11 → 120);
whether the cPanel Backup tool is available (second backup copy); explicit
deployment approval.

**Not testable read-only (accepted, low impact):** whether MariaDB skips
`ON UPDATE` when a column is assigned to itself (`updated_at = updated_at` in
0084 for the 15 organizations; 0083's snapshot rewrite has 0 rows). Worst case:
those 15 `organizations.updated_at` values become the migration time (UTC) —
no mixed-zone data.

---

## 4. Window start: maintenance, cron stop, backups

W0. **Raise the PHP time limit (before the window opens).** cPanel → Select
PHP Version (CloudLinux PHP Selector) → Options (PHP 8.3) → `max_execution_time`: **30 → 120** → Apply. Reload
the page, confirm the field shows **120**, and keep a screenshot. Q11 passes
only after this confirmation; without it → **stop**. This affects web requests
only (including `/system/migrate`); the cron worker is CLI and disabled in W3.
W1. **Back up both `.htaccess` files** (File Manager → copy) to
`.htaccess.pre-tz` and `public/.htaccess.pre-tz`.
W1b. **Compare before anything is overwritten.** Compare the W1 copies with
the package versions (`.htaccess` and `public/.htaccess` inside `upload.zip`;
identical to the repository files). The package files contain only rewrite
rules — no PHP handler, and the package has no `php.ini` / `.user.ini`. If a
production file contains any line the package version lacks (e.g. an HTTPS
redirect, another site's pass-through rule, or a cPanel-generated block),
**stop here** — nothing has been overwritten yet — and prepare an approved
merged file before the window; D3 then puts the merged file in place instead
of the plain package file.
W2. **Enable maintenance:** add the §2 P5 snippet at the top of the active
`.htaccess` (or both). Verify:
  - from the authorized IP the CRM still opens;
  - from another network (e.g. a phone on mobile data) `https://crmgroup.ir`
    returns **503**;
  - `mirsys.ir` (and any other site on the account) still opens normally.
  If any of the three fails → fix the rule; do not continue.
W3. **Disable every CRM cron job** from Q10 (comment out / delete, keep the
exact lines to restore later).
W4. **Wait at least 5 minutes** (the Marketing worker runs ≤ 50 s by default
and at most 300 s). Close every CRM browser tab except one on the
authorized machine; do **not** keep a Marketing campaign page open.
W5. Re-run Q7 — still empty.
W6. **Database backup (two independent copies):**
  - phpMyAdmin → the CRM database → Export → Custom → all tables, structure +
    data, **"Add CREATE DATABASE / USE statement" unchecked** →
    `crm-pre-tz-YYYYMMDD-HHMM.sql` → download to the local computer;
  - cPanel → Backup → *Download a MySQL Database Backup* of the CRM database.
W7. **Verify the database backup by restoring it:**
  1. cPanel → MySQL Databases → create `<prefix>_tzcheck`, add the CRM DB user
     with all privileges.
  2. Open the `.sql` file in a text editor and confirm it contains **no**
     `CREATE DATABASE` and **no** `USE ` line (otherwise the import would write
     into the live database) — if it does, stop and re-export per W6.
     Then phpMyAdmin → select `<prefix>_tzcheck` → Import the `.sql` from W6.
  3. Compare live vs. restored (run in each database):
     - `SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE();`
     - `SELECT COUNT(*) FROM organizations;` `… users;` `… contacts;`
       `… invoices;` `… tasks;` `… follow_ups;` `… organization_modules;`
       `… migrations;` `… module_migrations;`
     - `CHECKSUM TABLE organizations, users, contacts, organization_modules, migrations;`
     - `CHECK TABLE organizations, users, contacts;` → `OK`
  4. Every number and checksum must match. **Any difference → stop**: do not
     continue without a verified backup (re-export, re-check).
  5. Drop `<prefix>_tzcheck`.
W8. **Application backup (two archives):**
  - **Code archive** — exactly what `upload.zip` replaces: the folders `app`,
    `config`, `database`, `lang`, `modules`, `public`, `routes`, `themes`,
    `vendor`, plus the root `.htaccess` and `.env` → File Manager → Compress →
    `crm-app-pre-tz-YYYYMMDD-HHMM.zip` → download a copy.
  - **`storage/` archive** — uploaded files (logos, attachments, file library),
    logs and sessions → `crm-storage-pre-tz-YYYYMMDD-HHMM.zip` → download.
    The package only contains empty placeholders for `storage/`; extraction
    does not touch existing files there, and a rollback leaves `storage/` in
    place (§7 R5). This archive is a safety copy only.
  Do **not** include other sites' folders (e.g. the `mirsys.ir` folder) or
  unrelated large files in the CRM folder.
W9. Write down the time W6 was taken. From here until §6 is complete,
**nobody except the operator works in the CRM**.

---

## 5. Deployment

D1. Upload `upload.zip` to the CRM folder (File Manager → Upload).
D2. Extract it **over** the existing files. **Keep the existing `.env`**
(the package does not contain one — confirm `.env` is unchanged afterwards).
D3. **Immediately re-apply maintenance:** the extraction replaced `.htaccess`
and `public/.htaccess`. Add the P5 snippet again at the top of the active
file(s) and re-verify all three W2 checks (operator through, 503 from another
network, `mirsys.ir` still opens). Until this is done, the site is open — do
this within one minute of D2 (have the snippet ready in the clipboard).
Exposure in that minute: requests run the new code against the not yet
migrated database; their timestamps are still written consistently in the old
zone (the UTC session only starts after 0083 commits), but anything written
then is after the W6 backup and is lost by a rollback.
D3b. Confirm the extracted `.htaccess` / `public/.htaccess` (below the
snippet) are exactly the package versions — or the merged files approved in
W1b. Anything else → keep maintenance on and **roll back (§7)**. Delete the
uploaded `upload.zip` from the server.
D4. In `.env`: set `MIGRATE_TOKEN=<long random value>` (only for this step).
Optional: `ORGANIZATION_TIMEZONE=Asia/Tehran` (the default; only needed to
change it). `APP_TIMEZONE` is no longer read (storage is always UTC).
D5. Do not use the CRM in any other tab. From the authorized IP, open **once**:
`https://crmgroup.ir/system/migrate?token=<MIGRATE_TOKEN>`
Wait for the page to finish; **never reload**. Save the full response.
- **Success** is only a JSON response of the form
  `{"data":{"applied":{"core":[…],"modules":{…}}}}` whose `core` list is
  exactly the approved pending core list ending with
  `0083_convert_timestamps_to_utc_storage`, `0084_normalize_organization_timezones`,
  and whose `modules` lists are exactly the approved pending module lists
  (empty lists for modules with nothing pending).
- **Failure** looks like the application's generic error page ("Internal
  server error", HTTP 500) — the migration name is **not** shown on screen.
  It is written to `storage/logs/app-YYYY-MM-DD.log` (UTC date) as
  `Migration failed: <name>`. A 404 means the token was not accepted (nothing
  ran — fix `.env` and open the URL once more; this is the only allowed retry).
- **Unknown** (browser timeout, blank page, connection reset): the PHP run may
  still be in progress — wait 5 minutes, do not reload, then determine the
  state with §6 "unknown result".
D6. Immediately remove `MIGRATE_TOKEN` from `.env` (blank value), save.

---

## 6. Stop conditions (during §4–§5) and what to do

| Situation | Meaning | Action |
|---|---|---|
| Any §3 check fails | environment differs from the tested one | stop before W1; nothing changed |
| Backup verification (W7) differs | no trustworthy backup | stop; do not deploy |
| W1b: a production `.htaccess` has lines the package lacks | production-specific rules would be lost by extraction | stop before D1 (nothing overwritten yet); continue only with an approved merged file |
| D3b: extracted `.htaccess` is not the package (or approved merged) version | unexpected file state | keep maintenance on; **Rollback (§7)** |
| D5 returns the error page; log says `Migration failed: <name>` with a migration **before** 0083 | 0083 did not run; the database is entirely in the old zone, but the earlier pending migrations may be partly applied | keep maintenance on; **Rollback (§7)** |
| … log says `Migration failed: 0083_convert_timestamps_to_utc_storage` | 0083 rolled back its transaction; only an empty `storage_timezone_state` table may exist; connections stay in the old zone | keep maintenance on; **Rollback (§7)** |
| … log says `Migration failed: 0084…` or a **module** migration | storage is already UTC; the remaining migrations did not run | keep maintenance on; **Rollback (§7)** |
| D5 succeeded but the `core` / `modules` lists differ from the approved lists | unreviewed migrations ran | keep maintenance on; **Rollback (§7)** |
| D5 result unknown (timeout / blank) | unknown state | keep maintenance on; after 5 min record: Q4, `SELECT * FROM storage_timezone_state;`, `SELECT migration FROM migrations ORDER BY id DESC LIMIT 5;`, `SELECT module_key, migration FROM module_migrations ORDER BY id DESC LIMIT 10;` and the day's log; then **Rollback (§7)** |
| Any HARD check in §6a fails | conversion or code not as expected | keep maintenance on; **Rollback (§7)** |
| Maintenance rule found missing at any time after D2 | users may have written data | re-apply immediately; report; decide on rollback before going live |

In every failure case the **only** authorized recovery is §7 (database
backup restore **and** previous application files). Re-uploading the old
package alone, re-running `/system/migrate`, or running down migrations is not
a recovery.

### 6a. Post-migration validation (maintenance still on)

**HARD** = must pass, otherwise §7. **INFO** = record only.

SQL (phpMyAdmin, CRM database):
- V1 HARD `SELECT legacy_zone, legacy_system_zone, legacy_offset, unverified_columns, converted_at FROM storage_timezone_state;`
  → exactly one row; `legacy_offset` = `03:30`; `unverified_columns` = `[]`;
  `converted_at` = the D5 time in UTC.
- V2 HARD `SELECT migration FROM migrations WHERE migration IN ('0083_convert_timestamps_to_utc_storage', '0084_normalize_organization_timezones');`
  → both rows.
- V3 HARD `SELECT default_timezone, COUNT(*) FROM organizations GROUP BY 1;` →
  compared with B1: every organization that had `UTC`, empty or an invalid
  value is now `Asia/Tehran`; every other valid zone from B1 is unchanged;
  the total count is unchanged.
- V3b INFO `SELECT COUNT(*) FROM organization_timezone_normalized;` → the
  number of organizations V3 changed.
- V4 HARD — B2 query again → `0` (it was > 0 in B2 if any module had been
  enabled at creation; if B2 was already 0, V4 is INFO).
- V5 HARD — B3 query again → `0` (if B3 was already 0, V5 is INFO).
- V6 HARD — no stored instant in the future (would mean a value was left in
  the old zone): `SELECT COUNT(*) FROM contacts WHERE created_at > UTC_TIMESTAMP() + INTERVAL 1 MINUTE;`
  and the same for `users`, `organization_modules`, `marketing_campaigns`
  (if present) → `0` each.
- V7 INFO `@@session.time_zone` in phpMyAdmin is phpMyAdmin's own session and
  says nothing about the application; the application's UTC session is proven
  by S2.

Smoke tests (from the authorized IP, as an Org Admin):
- S1 HARD Organization Settings → "Timezone" is a dropdown showing
  `(UTC+03:30) Asia/Tehran` (Persian UI: "Asia/Tehran (UTC+03:30)") — this
  also proves the new code is being served; saving the page works.
- S2 HARD Add a note to a test contact → the shown time equals the current
  Tehran time (not 3:30 off).
- S3 HARD Dashboard opens without error; "My tasks" groups (overdue / due
  today / upcoming) are plausible.
- S4 HARD if follow-ups exist: Follow-ups list opens; an item due earlier today
  (Tehran) shows "Overdue". Otherwise INFO (page opens).
- S5 HARD if Appointments is enabled: the calendar opens on today's Tehran
  date; no free slot earlier than the current Tehran time is offered for today.
- S6 HARD if Finance is enabled: a new expense form defaults to today's Tehran date.
- S7 HARD if Marketing is enabled: the Marketing dashboard and campaigns list
  open (do not start any sending).
Smoke-test data is real data: it remains after go-live (and is lost by a
rollback).

### 6b. Go-live (only when every HARD check passed and D6 is done)
G1. Remove the maintenance snippet from the active `.htaccess` file(s); verify
the CRM opens from another network.
G2. Re-enable every cron job recorded in W3, exactly as it was.
G3. Within ~5 minutes: the Marketing cron runs without errors (cPanel cron
e-mail / logs); re-check V6.
G4. Restore the PHP time limit: Select PHP Version (CloudLinux PHP Selector) →
Options (PHP 8.3) → `max_execution_time`: **120 → 30** → Apply; reload, confirm
**30**, keep a screenshot. Keep the W6/W8 backups at least until the next
release.

---

## 7. Rollback — Option A (the only authorized procedure)

**Warning:** restoring the backup puts the database back to the moment of W6.
**Every change written after that moment is discarded** — during the window
that is only the migration and the operator's smoke tests; **after go-live it
includes real user data.** A rollback after go-live therefore needs an
explicit business decision first.

R1. **Maintenance on** (re-apply the snippet if missing; verify 503 from
another network) and **all CRM cron jobs disabled**. Both stay so until R7.
R2. Record the current state for the incident report:
`SELECT * FROM storage_timezone_state;` (if it exists) and
`SELECT migration FROM migrations ORDER BY id DESC LIMIT 5;`.
R3. **Restore the database** from the verified W6 backup:
  - preferred: cPanel → Backup → *Restore a MySQL Database* with the W6 cPanel
    backup; or
  - phpMyAdmin → CRM database → select all tables → Drop (uncheck "Enable
    foreign key checks" so the drop completes) → then Import the W6 `.sql`
    file.
  The restore must **replace the whole database** (including
  `storage_timezone_state`, `organization_timezone_normalized` and the
  `migrations` rows of 0083/0084, which did not exist before).
R4. **Verify the restore** with the W7 queries: table count, row counts and
checksums must equal the numbers recorded in W7, and
`SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'storage_timezone_state';` = `0`.
R5. **Restore the previous application files exactly** from the W8 archive.
Extracting the old archive *over* the new files is **not enough**: files that
exist only in the new release would stay — in particular
`database/migrations/0083_…` and `0084_…`, which a later `/system/migrate`
would run against the old code. Do:
  1. move exactly the code items of the W8 code archive — `app`, `config`,
     `database`, `lang`, `modules`, `public`, `routes`, `themes`, `vendor` and
     the root `.htaccess` — into `_failed-tz-YYYYMMDD` (keep `.env` aside);
     **do not move or delete `storage/`** (it holds the uploaded files, also
     any uploaded after the backup — files whose database rows were discarded
     by R3 remain as unused files) and do not touch other sites' folders;
  2. extract the W8 **code** archive into the CRM folder;
  3. confirm `.env` is the original one and `database/migrations/0083_*` /
     `0084_*` and `app/Infrastructure/Time/` do **not** exist.
R6. Re-apply the maintenance snippet (the W8 archive contains the old
`.htaccess`; re-check the three W2 checks), then smoke-test the old version
from the authorized IP: login, dashboard, a contact page, Organization
Settings — which must show the **old free-text** timezone field (proves the
old code is served).
R7. Only after R4 and R6 pass: remove the maintenance snippet, re-enable the
cron jobs from W3, monitor for 15 minutes.
R8. Keep the failed release folder and all outputs for analysis; do not
re-attempt the deployment before the cause is understood.
R9. Restore the PHP time limit: Select PHP Version (CloudLinux PHP Selector) →
Options (PHP 8.3) → `max_execution_time`: **120 → 30** → Apply; reload, confirm
**30**, keep a screenshot.

**Not authorized:** running the down methods of 0083/0084, deleting rows from
`migrations` by hand, re-uploading only the old package without restoring the
database (old code + converted UTC data would write mixed timestamps), or
re-running `/system/migrate` to "finish" a failed run.

---

## 8. Known items outside this deployment

- **Follow-up reminders are not sent.** No scheduler, cron job or request
  calls `FollowUpReminderRepository::duePending()` or `markSent()`; rows in
  `follow_up_reminders` are created and stay `pending`. This is pre-existing,
  unchanged by this release, and **out of scope** for the timezone deployment
  (it needs its own phase).
- **Standalone test failure (pre-existing).** `FollowUpRelatedRecordsTest`
  run alone fails R3 (`followups.flash.updated` shown as a raw key). The same
  failure occurs on the code from before the timezone phase, on the code from
  before the P1 fixes, and on the current code (three-version comparison,
  2026-10-15). Cause: the test file sets a UI language only inside R3, so the
  flash message written by R2's request is stored untranslated. In the full
  suite an earlier test sets a language first, so it passes. It is unrelated
  to the timezone changes and is not fixed in this release.
- Remaining P2/P3 timezone items: `docs/organization-timezone-design.md` §4.

---

## 9. One-page checklist

Preparation: ☐ P1 package ☐ P2 IP ☐ P3 document root + CRM hostnames ☐ P4 DB/disk/import limit ☐ P5 snippet
Preflight: ☐ Q1 zone/offset/version ☐ Q2 InnoDB ☐ Q3 no TIMESTAMP ☐ Q4 no state table ☐ Q5 core migrations ☐ Q6 module migrations ☐ Q5/6-A pending lists approved ☐ Q7 no active campaign ☐ Q8 APP_TIMEZONE ☐ Q9 proxy ☐ Q10 cron list ☐ Q11 execution time ☐ B1–B3 baselines recorded
Window: ☐ W0 max_execution_time 120 confirmed (screenshot) ☐ W1 .htaccess copies ☐ W1b .htaccess compared with the package (before extraction) ☐ W2 maintenance verified (operator OK, 503 elsewhere, mirsys.ir OK) ☐ W3 cron off ☐ W4 waited 5 min ☐ W5 Q7 again ☐ W6 two DB backups (no USE/CREATE DATABASE) ☐ W7 restore-verified ☐ W8 code + storage archives ☐ W9 time noted
Deploy: ☐ D1 upload ☐ D2 extract, .env kept ☐ D3 maintenance re-applied + W2 checks ☐ D3b extracted .htaccess = package/approved version, zip deleted ☐ D4 token ☐ D5 migrate once (JSON = success) ☐ D6 token removed
Validate: ☐ V1 ☐ V2 ☐ V3 ☐ V4 ☐ V5 ☐ V6 (HARD) ☐ S1–S7
Go-live: ☐ G1 maintenance off ☐ G2 cron on ☐ G3 cron OK ☐ G4 max_execution_time back to 30 (confirmed) + keep backups
Rollback (if needed): ☐ R1 maintenance + cron off ☐ R2 record ☐ R3 restore DB ☐ R4 verify ☐ R5 clean app restore ☐ R6 smoke ☐ R7 reopen ☐ R8 keep evidence ☐ R9 max_execution_time back to 30 (confirmed)
