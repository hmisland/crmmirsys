# Phase 1 — Foundation

Status: **implemented, per the Phase 0 (Rev. 3) approved architecture.** Scope is exactly what Phase 0 §87 listed — no CRM modules, no auth, no RBAC. Those are Phase 2 onward.

## What this phase built

| Piece | Where | Notes |
|---|---|---|
| Bootstrap / kernel | `app/Core/Application.php` | Wires config, container, router, error handling, module discovery per request |
| Autoloading | `app/autoload.php` | Hand-rolled PSR-4-style, zero Composer dependency at runtime (shared-hosting constraint) |
| Config | `app/Infrastructure/Config/` | `.env` + `config/*.php`, dot-notation `Config::get()` |
| Database | `app/Infrastructure/Database/` | PDO singleton (`Connection`), `Migrator` + `MigrationInterface` |
| Routing | `app/Core/Router.php`, `Request.php`, `Response.php` | Static + `{param}` + `{path*}` routes, groups, per-route middleware |
| Middleware pipeline | `app/Core/Middleware/` | `AuthMiddleware`/`TenantMiddleware`/`RbacMiddleware` are documented placeholders (Phase 2/3); `ModuleMiddleware` and `CsrfMiddleware` are fully functional now |
| Controller/Service bases | `app/Application/` | `BaseController`, `BaseApiController` (consistent `/api/v1` envelope), `BaseService` |
| Repositories | `app/Infrastructure/Repositories/` | `BaseRepository` (prepared statements only), `TenantScopedRepository` + `OrganizationContext` + `SuperAdminContext` — the tenant-isolation mechanism from Phase 0 §12–13 |
| Logging | `app/Infrastructure/Logging/FileLogger.php` | Daily-rotated file log, defensive redaction of password/token/card-number-shaped context keys |
| Error handling | `app/Infrastructure/ErrorHandling/ErrorHandler.php` | Full detail to log only; safe HTML or JSON to the client, matching Phase 0 §32/§61 |
| Security | `app/Infrastructure/Security/` | `Session` (secure/HttpOnly/SameSite + expiry), `Csrf`, `PasswordHasher` (Argon2id → bcrypt fallback) |
| Theming | `app/Infrastructure/Theme/`, `themes/default/` | Plain-PHP templates, `e()`/`t()` helpers, layout support, RTL/LTR aware baseline theme |
| Module Manager foundation | `app/Infrastructure/Modules/` | Filesystem discovery of `module.json` only (see below) |
| Tests | `tests/` | PHPUnit, dev-only |

## What's deliberately NOT here yet

- **Auth, sessions-with-identity, RBAC, permission scopes** — Phase 2/3. `AuthMiddleware`/`TenantMiddleware`/`RbacMiddleware` exist as pipeline placeholders so routes are already wired through the correct chain shape; they don't check anything yet.
- **`organization_modules` / `user_module_overrides` tables, enable/disable UI** — Phase 4. `ModuleRegistry` only answers "is this module's `module.json` present on disk"; `ModuleMiddleware` 404s a route whose module isn't installed, but does not yet check "enabled for this org."
- **Any CRM module** (Contacts, Sales, Wallet, ...) — Phases 5+.
- **A migration CLI** — no SSH is assumed on the target host; migrations run via `Migrator::run()` from a script (dev) or a future authenticated endpoint (once Phase 2/3 exist to protect it). See `database/migrations/README.md`.

## Running it locally

1. Point a MySQL/MariaDB database at the values in a real `.env` (copy `.env.example`).
2. Point your webserver's document root at `/public`, or use PHP's built-in server for a quick check:
   ```
   php -S localhost:8000 -t public
   ```
3. Visit `/` (renders the theme + lists discovered modules — none yet, expected) and `/api/v1/health`.

## Deploying to shared cPanel hosting

- Upload everything except `.git`; set the domain's Document Root to `/public` if the control panel allows it (preferred), otherwise the root `.htaccess` rewrites into `/public` for you.
- Create the MySQL database + user via cPanel, fill in a real `.env` (never commit it).
- No `composer install`, no Node, no build step is required to run the application — `composer.json` exists only for optional local dev tooling (PHPUnit).

## Testing

```
composer install   # local dev only — pulls PHPUnit
composer test
```

## Definition of Done checklist (per Phase 0 §108)

- [x] Functionality implemented (scope above)
- [x] Security reviewed (sessions, CSRF, password hashing, prepared statements, safe error output)
- [ ] Permissions tested — N/A yet, no RBAC exists (Phase 3)
- [x] Tenant isolation tested (`OrganizationContextTest`)
- [x] Module activation tested where applicable (`ModuleManifestTest`; full enable/disable is Phase 4)
- [x] Database migrations tested (`Migrator` exercised implicitly via its tracking-table bootstrap; no business migrations exist yet to test against)
- [x] API tested (`/api/v1/health`, `/api/v1/modules` manually verified; `RouterTest` covers the routing layer they depend on)
- [x] UI tested (home page + 404/500 error pages render through the real pipeline)
- [x] Errors handled (`ErrorHandler`)
- [x] Documentation updated (this file)
- [x] Tests added (`tests/Unit/*`)
- [x] CHANGELOG updated
- [x] Deployment considerations documented (above)
