# Phase 3 — Organizations / RBAC

Status: **implemented**, scoped to Phase 0 §89: Organizations, Users (org relationship), Departments, Teams, Roles, Permissions, Scopes, Authorization engine, Tenant isolation.

## What this phase added

| Piece | Where |
|---|---|
| `organizations`, `departments`, `teams`, `roles`, `role_permissions` tables; users gets department_id/team_id/role_id/is_super_admin + the organization FK deferred from Phase 2 | `database/migrations/0004`–`0011` |
| System role templates seeded (`org_admin`, `member`) | `0010_seed_system_roles.php` |
| Pre-Phase-3 accounts promoted to Super Admin | `0011_backfill_legacy_users_to_super_admin.php` |
| `Scope` enum, `Permission` catalogue (code-defined, per Phase 0 §16) | `app/Infrastructure/Authorization/` |
| The single reusable `AuthorizationEngine` (per Phase 0 §10–11/§17) | `app/Infrastructure/Authorization/AuthorizationEngine.php` |
| `OrganizationRepository`, `DepartmentRepository`, `TeamRepository`, `RoleRepository`, `RolePermissionRepository`; `UserRepository` extended with org-aware methods | `app/Infrastructure/Repositories/` |
| `OrganizationService`, `UserManagementService`, `RoleService`, `OrgStructureService` — each permission-checked via `AuthorizationEngine` | `app/Application/Services/` |
| `AuthMiddleware`/`RbacMiddleware` fully real now; `TenantMiddleware` retired to a documented no-op | `app/Core/Middleware/` |
| `OrganizationContext` now resolved **globally** every request (not per-route) | `app/Core/Application.php` |
| `AuthService::register()` now creates a new organization per signup | `app/Application/Services/AuthService.php` |
| Org admin UI: settings, team members, roles, departments/teams | `app/Application/Controllers/{Organization,UserManagement,Role,OrgStructure}Controller.php`, `themes/default/templates/org/*`, routed under `/org/*` |

## Architectural decisions made this phase

- **Self-service registration creates a new organization**, and the registrant becomes that org's Organization Admin — the standard SaaS pattern (HubSpot/Pipedrive-style: one signup = one new tenant). Inviting a teammate into an *existing* org is a separate flow (`UserManagementService::create()`, used from `/org/users`, called by an already-logged-in admin).
- **Pre-Phase-3 accounts were promoted to Super Admin**, not enrolled into an auto-created organization — they were, by definition, using the platform before multi-tenancy existed, i.e. its sole operator. One-time, non-reversible migration (0011); every registration from here on goes through the new organization-creation path instead.
- **Single role per user** (`users.role_id`), not many-to-many. Matches how Phase 0 §9's example roles are described (one role each), keeps `AuthorizationEngine` simple, and is not a redesign to move to a pivot table later if that's ever needed — the engine's public interface (`can()`/`scopeFor()`) wouldn't change.
- **`is_super_admin` is a separate boolean**, not "just another role" — Super Admin is explicitly platform-level, outside any organization (Phase 0 §15), and every `roles` row is either a system template or belongs to one specific org; there's no natural "no organization at all" role row.
- **`ALL` and `ORGANIZATION` scopes are equivalent** from `AuthorizationEngine::can()`'s point of view — tenant isolation (`TenantScopedRepository`) already confines any ordinary query to the caller's own organization, so the distinction only matters for Super Admin's explicit `SuperAdminContext::run()` cross-org bypass, which is a repository-level concern, not this check's.
- **`OrganizationContext` is now resolved globally per request** (in `Application.php`), not via a per-route `TenantMiddleware` attachment — the same fix already applied to `CurrentUser` after the Phase 2 "homepage doesn't know I'm logged in" bug, applied here proactively instead of waiting to hit the same failure mode again. `TenantMiddleware` itself is now a documented no-op.
- **RBAC is checked twice, deliberately**: once as a fast route-level pre-check (`RbacMiddleware`, only on the primary "view" route of each admin area) and again, authoritatively, inside every Service method. This is defense in depth (Phase 0 §65), not redundant — the Service-level check is what actually matters and is what a future API consumer or a differently-routed call still goes through.
- **A minimal Super Admin panel** (`/admin/organizations`, list-only: every organization + its user count) was added right after initial delivery, once real-world testing showed a Super Admin account otherwise has nowhere to go (no organization, so no `/org/*` links). Still deliberately thin — see below.
- **Departments/teams have no dedicated edit page** — one combined list + quick-add page per Phase 0 §75's admin capability list, not a polished CRUD UI. Deleting is one click; renaming isn't exposed in this phase's UI (the Service supports it — `renameDepartment`/`renameTeam` — a form just isn't wired up yet).
- **Role permission editing has one page**: create or edit (via `?edit=<id>`), with a checkbox + scope dropdown per permission. System role templates are never editable (create your own custom role instead, optionally copying permissions from a template by hand for now — an actual one-click "clone" button is a natural fast-follow, not built this phase).

## Deliberately not done yet

- No "clone a system template into a custom role" one-click action (the data model supports it; the UI doesn't expose it yet).
- No department hierarchy (flat only).
- No multi-role-per-user.
- No Super Admin *action* UI — `/admin/organizations` is read-only (list + user counts). Editing/suspending an organization, module enable/disable, plans — all Phase 4 territory.
- Admin-action failures (role assignment, activate/deactivate, delete) currently fall through to the generic error page rather than redirecting back with an inline flash message — functionally correct and secure, just not as polished as the auth pages' inline field errors.

## Deploying this phase

1. Upload the new/changed files (full re-upload, overwriting, is safest).
2. Visit `/system/migrate?token=YOUR_TOKEN` — applies migrations 0004–0011. Migration 0011 will promote your existing account (and any other pre-Phase-3 account) to Super Admin.
3. Log out and back in (so the session picks up the new `is_super_admin`/`role_id` fields via `CurrentUser`).
4. As a Super Admin, `/org/*` pages won't show in the nav (you have no organization) — that's expected. Instead you'll see an "All organizations" link (`/admin/organizations`), a read-only list of every organization on the platform. To see the org admin UI itself, register a **second, new account** — that one gets its own organization and becomes its Organization Admin, and will see Team/Roles/Departments/Org settings in the nav.

## Definition of Done checklist (per Phase 0 §108)

- [x] Functionality implemented (scope above)
- [x] Security reviewed (every Service method checks `AuthorizationEngine::can()` before acting; roles/permissions from another organization are rejected even if their id is guessed; a user can't deactivate their own account)
- [x] Permissions tested manually (see deployment steps above); no automated RBAC test suite yet — a good Phase 3.5/4 addition
- [x] Tenant isolation — `OrganizationContext` now resolved globally; `RoleRepository`/`OrganizationRepository` document why they're deliberately not auto-scoped and how their callers enforce boundaries instead
- [x] Module activation — N/A, this phase is Core, not a toggleable module
- [x] Database migrations tested via `/system/migrate`
- [ ] API — Phase 3's admin UI is web-only this round; `/api/v1/org/*` endpoints are a natural fast-follow, not built this phase
- [x] UI tested — settings/team/roles/departments pages render through the real pipeline
- [x] Errors handled (validation, authorization, not-found all produce a normal response)
- [x] Documentation updated (this file)
- [ ] Automated tests — none added this phase beyond what already exists; `AuthorizationEngine` is a strong candidate for a focused unit test suite (it's pure logic once given a fake `RolePermissionRepository`)
- [x] CHANGELOG updated
- [x] Deployment considerations documented (above)
