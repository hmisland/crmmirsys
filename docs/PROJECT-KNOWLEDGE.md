# CRM GROUP (Mirsys CRM): project knowledge reference

Read this first in a new session. Use it instead of rescanning the repository and old chats.
Details live in the linked `docs/` files. Update this file at the end of every phase.
Last updated: 2026-11-18 (main = `1125ed7`, in sync with production).

## 1. What it is
- A multi-tenant SaaS CRM for any kind of business. Wording stays industry-neutral: no clinic, salon or similar terms.
- UI in 5 languages: fa and ar (RTL); en, tr and fr (LTR). en is the fallback.
- Production: https://crmgroup.ir on shared cPanel hosting, PHP **8.1** (cPanel `ea-php81` handler).
  - Production database: MariaDB 10.5.
  - The cPanel account also hosts separate sites: `mirsys.ir`, `pentazone.ir`, `pentazone.ca`. Their folders sit inside the CRM root, and the root `.htaccess` keeps them out of the CRM rewrite.
- Repository: `github.com/hmisland/crmmirsys`. **Work directly on `main`**: no feature branches and no PRs, by the owner's decision.
- Local working copy: `C:\Users\Lenovo\Desktop\crm mirsys`.
- Production snapshot (download only, never edit): `C:\Users\Lenovo\Desktop\crm mirsys live`.

## 2. Stack and architecture (Phase 0: `docs/architecture.md`)
- Plain PHP 8.1 and MySQL/MariaDB. No framework, and no Composer or Node at runtime. `vendor/` is committed outside Git and holds only mpdf, myclabs, psr and setasign.
- Request flow: `public/index.php` → `App\Core\Application::run()`.
  1. Session start (skipped for stateless API calls).
  2. `handle()`: CORS.
  3. `process()`: resolve the user (session or Bearer) → OrganizationContext → Locale.
  4. Router.
- Layers:
  - `app/Core`: Application, Router, Request, Response, Middleware.
  - `app/Application`: Controllers and Services.
  - `app/Infrastructure`: Repositories, Security, Database, Modules, Sms, Mail, Time, Theme, Localization.
- Routes: `routes/web.php` and `routes/api.php` (`/api/v1`). Each module adds its own routes.
- Multi-tenancy: `OrganizationContext` is resolved from the user row on every request, never from client input. Super Admin has no organization and crosses organizations only through `SuperAdminContext::run()`.
- RBAC: `RbacMiddleware('perm.key')`. Module access: `ModuleMiddleware` / `ModuleGate`, with per-organization entitlements in `organization_modules`. Hiding a menu item is not access control.
- Integration between modules goes only through `RecordTypeRegistry`, `RelatedPanelRegistry` and `EventBus` (by event name string). The module that adds a feature owns it, and modules stay independent (`docs/module-extension-points.md`).
- Background work: `app/bin/scheduler.php` runs every minute from cron. Tasks include `followups.reminders`, `projects.delay_alerts`, CX and WooCommerce. Further scripts: `modules/Sales/bin/task-reminders.php` and `modules/Marketing/bin/worker.php`.
- Time: **all stored instants are UTC** (since migration 0083). Each organization's zone is `organizations.default_timezone`, read only through `App\Infrastructure\Time\OrganizationTimezone` (now, today, localNow, dayRange…). Templates use `local_time()`, `local_now()` and `local_today()`. Local wall-clock values typed by users (due_at, starts_at, DATE columns) are never converted (`docs/organization-timezone-design.md`).

## 3. Modules (`modules/<Name>/`, own `Migrations/`, tracked in `module_migrations`)
| Module | Migrations | Notes |
|---|---|---|
| Sales | 13 | deals, tasks, Task Manager, project task board (with Projects) |
| Finance | 28 | invoices, proformas, credit notes, tax profiles; Stage 5.2 finalization; per-row VAT; tax-system CSV (`docs/tax-invoice-csv.md`); tax product ID from `kala.db` |
| Projects | 7 | milestones, dashboard, delay alerts, budget, profit (`docs/projects-module-design.md`) |
| Contracts | 5 | installments; links to Finance and Projects |
| Followups | 5 | rule engine, reminders by email |
| Marketing | 15 | segments, scheduled campaigns, transactional messages + delivery reports; sub-modules MarketingEmail and MarketingBale (Bale/Safir) |
| WooCommerce | 2 | shop import, per-shop contact sync, purchase conditions for segments |
| CustomerSatisfaction | 3 | surveys, public links, test invitations |
| CustomerPortal | 1 | read-only customer access with one-time codes (`docs/customer-portal.md`) |
| ClientRecords, Appointments, Wallet, Support, InternalMail, Knowledge, Notification, Demo | 4/5/7/3/6/6/6/1 | see `docs/phase*-*.md` and the module docs |

Core: 95 migrations (`database/migrations`; the latest is `0094_create_api_access_tokens_table`). Help articles are seeded by migrations from `database/help-content/`.

## 4. Auth and API (synced from production in `1125ed7`)
- Web: session cookie (SameSite Lax), CSRF through the `_csrf` field or the `X-CSRF-Token` header. Captcha (GD, 5 digits) on the web `/login` and `/register`.
- API `/api/v1`:
  - `auth/register|login|logout|me|profile|password/*` use the cookie session.
  - `POST/DELETE auth/token` issue and revoke **Bearer tokens**: prefix `crmat_`, stored as a SHA-256 hash in `api_access_tokens`, 30 days, at most 10 per user, issuing rate-limited per IP.
  - `GET auth/csrf`.
  - `GET contacts`, `GET contacts/{id}`: read-only, `contacts.view`.
- A Bearer request is authenticated by the token only (an invalid token is a 401, never a fall-back to the session) and is exempt from CSRF.
- CORS for `/api/*` only, from `CORS_ALLOWED_ORIGINS`. Production currently allows the Replit PWA origins.
- Open points: no Captcha on the API register, login and token endpoints; a password change from the profile does not revoke tokens; the token path does not check the organization's status; `docs/phase0-multi-client-api.md` is not written yet.

## 5. Working rules (owner's standing decisions)
- **Phase workflow**: Audit + Design doc in `docs/` → stop and wait for approval of the decisions (D1, D2…) → staged implementation. After each stage: `tests/lite`, security and regression tests, mutation checks. Then update CHANGELOG and the design doc.
- Never decide on architecture, schema, permission or other-module changes alone: stop and report. Report anything outside the scope; do not fix it. Never change financial calculations unless the task asks for it.
- Replies to the owner are in **Persian**. Code, docs and commits are in English.
- UI: follow `CLAUDE.md` and `docs/ui-design-system.md`. Every action is an icon + text, no inline styles, logical CSS for RTL/LTR. Every new text key goes into all 5 `lang/*.php` files.
- **Lang files have mixed line endings**: never `sed -i` them. Edit them byte-exactly (PHP or Edit).
- `kala.db` (1.8 GB national goods list, SQLite): read-only, path in `KALA_DB_PATH`.
- Never touch Production directly. Never commit or upload `.env`, `.dev-credentials`, `ddddd/`, `backups/`, `chats/` or SQL dumps.

## 6. Local development and tests
- PHP: `C:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe` (not on PATH). Production is 8.1, so do not use 8.2+ features.
- MySQL: Laragon 8.4 on 127.0.0.1:3306, database `crm_local`. The tests refuse anything else (`APP_ENV=local`).
- Tests: `php tests/lite/run.php [Filter]`. Back up first: `mysqldump … crm_local > backups/…sql`.
- Local migrations: the same steps as `GET /system/migrate` (core, then each module's `Migrations/`), only against `crm_local`.
- **Baseline at `1125ed7`**: 1134 tests, 1047 passed, 26 failed, 61 skipped. All failures already exist on main; they are listed in the CHANGELOG sync entry:
  - ModuleCatalog, CustomerPortal, IntegrationHelp, ProjectTasksPhase2: most likely the local `modules` table is not synced.
  - TimezoneBusinessLogic and the skips: an earlier suite, probably TaxInvoiceTest, leaves a transaction open in the full run.

## 7. Deployment (cPanel)
- `powershell -ExecutionPolicy Bypass -File tools\build-upload.ps1` builds `upload.zip`. It contains .htaccess, app, config, database, lang, modules, public, routes, themes and an empty storage skeleton, but no .env, docs or tests.
- Upload and extract the zip on the server, then run the migrations (`/system/migrate?token=…` with `MIGRATE_TOKEN`) and clear the token afterwards. Tell the owner which migrations a release brings.
- ⚠️ The zip contains the repository `.htaccess`, which does **not** have the cPanel `ea-php81` handler block that exists only on the server. Keep the server's copy of `.htaccess`, or add the handler back after extracting. Also never overwrite `.user.ini` or `php.ini` on the server.

## 8. History (by date)
- **Oct 2–3:** baseline, then the UI/UX redesign of the default theme; Client Records; remaining tasks.
- **Oct 8–9:** contacts changes; leads (tags, free notes, follow-up); internal mail and bulk delete; help system; file migration into the CRMgroup file library; Vazir font in PDF.
- **Oct 11:** Finance Stage 5.2; Projects Phase 5.
- **Oct 15–17:** kala.db review; UTC storage and organization time zone; Marketing core; search buttons on list search fields; Bale (Safir) messaging; manual email verification.
- **Oct 18–19:** Task Manager audit and upgrades; integrations Phase 1: WooCommerce, public links, customer satisfaction.
- **Oct 19–21** (cloud sessions):
  - Marketing transactional delivery, WooCommerce contact sync and segments, scheduled campaigns.
  - CX test survey; module catalog and activation requests; platform tickets; help categories.
  - Customer Portal V1; tax product ID and per-row VAT; tax-system CSV.
  - Projects Phases 1–3, reminder emails and delay alerts.
- **Oct 21:** offline editions on the branches `windowscrm` (portable PHP + MariaDB, LAN) and `crmlaragon` (Laragon / Apache). Both add only new files on top of `5854aec`, with a signed RSA license (install code, expiry, user limit). The license private key is only in `offline/mirsys-license-kit.zip`. **Out of scope for now.**
- **Up to Nov 15:** multi-client API, Captcha, CORS and PWA icons, built in a separate chat and deployed straight to production.
- **Nov 18:** synced into main as `1125ed7`.

## 9. Next steps (agreed order)
1. Fix the current bugs on the online CRM. The pre-existing test failures above stay deferred unless the owner asks.
2. Mobile app on Replit, using the Bearer API and the CORS allowlist.
3. Later: update the offline editions from main, keeping their own install and license files.
