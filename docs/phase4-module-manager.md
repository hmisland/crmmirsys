# Phase 4 — Module Manager

Status: **implemented**, scoped to Phase 0 §90: module registry, discovery, activation/deactivation, organization module state, user module overrides, dependencies, module permissions/routes/configuration/migrations/versioning, Super Admin module management.

## What this phase added

| Piece | Where |
|---|---|
| `modules`, `organization_modules`, `user_module_overrides` tables | `database/migrations/0012`–`0014` |
| `modules.manage` permission granted to the existing `org_admin` system role | `0015_grant_modules_manage_to_org_admin.php` (a new migration, not an edit to the already-applied `0010`) |
| `ModuleRepository`, `OrganizationModuleRepository`, `UserModuleOverrideRepository` | `app/Infrastructure/Repositories/` |
| The Phase 0 §6 resolution chain (installed → globally enabled → org entitled → user override), as its own small class | `app/Infrastructure/Modules/ModuleAccessResolver.php` |
| `ModuleService` — sync-from-disk, Super Admin global enable/disable (with dependency checks), Super Admin per-organization entitlement (with dependency checks) | `app/Application/Services/ModuleService.php` |
| `ModuleMiddleware` now enforces the full chain (was "installed" only, Phase 1) | `app/Core/Middleware/ModuleMiddleware.php` |
| Module bootstrapping: a module's own routes/services get registered from its `module.json`'s `bootstrap` class | `app/Core/Application.php` (`bootstrapModuleRoutes()`), `app/Infrastructure/Modules/ModuleManifest.php` |
| Super Admin UI: `/admin/modules` (sync, global enable/disable) | `ModuleAdminController`, `themes/default/templates/admin/modules.php` |
| Org Admin UI: `/org/modules` (read-only entitlement + per-user restriction) | `OrgModulesController`, `themes/default/templates/org/modules.php` |
| A working example module (not a real feature — see its own docblock) | `modules/Demo/` |
| `/system/migrate` now also runs every installed module's own migrations, tracked separately | `routes/web.php` |

## Architectural decisions made this phase

- **`modules.dependencies` is stored as JSON**, not a join table — dependency lists are small, always read as a whole list, rarely written. A join table would add a join for no real benefit.
- **A module row's `status` is never touched by `syncFromRegistry()`** once it exists — re-syncing (e.g. after a version bump on disk) updates name/version/type/dependencies but never silently re-enables a module a Super Admin explicitly disabled.
- **CORE modules never get an `organization_modules` row at all** — `ModuleAccessResolver` short-circuits to "always enabled" for `is_core = 1` without touching the table, matching Phase 0 §5's "Core stays small but what's in it is always on."
- **Dependency validation runs at both the global and per-organization level**, symmetrically: enabling requires dependencies already enabled at that same level; disabling is blocked while an enabled dependent (at that level) still needs it. Per Phase 0 §8 — "prevent invalid configurations," never a silent cascade.
- **A demo module was built** (`modules/Demo/`) purely to prove the pipeline end to end on a real deployment before any real CRM module exists to test it against. It is explicitly not a CRM feature — its own docblock says so and says when to delete it (once Sales or another real Phase 6+ module exists as the reference example instead).
- **Module templates render through the default theme**, not a module-owned `Templates/` folder — Phase 0 §63 sketches modules having their own template directory, but teaching `TemplateEngine`/`ThemeLoader` to search a module's own templates too is real, unbuilt work, called out explicitly in `DemoModule`'s docblock rather than silently skipped.
- **User-level overrides (`user_module_overrides`) have a complete data model and resolution logic, but no management UI yet** — same trim pattern as Phase 3's department renaming: the capability exists and is usable via `ModuleService`, a dedicated "override for this one user" admin screen is a fast-follow, not built this phase.

## Deliberately not done yet

- No API endpoints for module management (`/api/v1/modules/*` beyond the existing read-only diagnostic route from Phase 1).
- No plan/entitlement bundling (Phase 0 §37–38/§56–57 — "Plan → Modules → Limits") — that's explicitly a later commercial-architecture phase, not Phase 4.
- No UI for user-level module overrides (see above).
- No usage-limit enforcement (§72) — out of scope for the Module Manager itself.

## Deploying this phase

1. Upload everything (full re-upload, overwrite).
2. Run `/system/migrate?token=YOUR_TOKEN` — applies core migrations 0012–0015, and now also picks up and runs the Demo module's own migration (creates `demo_notes`), reported separately in the JSON response under `modules.demo`.
3. Log in as your Super Admin account, go to **Modules (platform)** in the nav (`/admin/modules`), click **"Sync from disk"** — this reads `modules/Demo/module.json` and inserts a row for it. Without this click, the Demo module exists on disk and its routes are registered, but `/demo` 404s for everyone (not "installed" from the database's point of view yet — the DB row is what `ModuleAccessResolver` checks).
> **Correction (post-Phase 6):** per-organization entitlement moved to Super Admin only, reached from `/admin/organizations/{id}/modules` — see the CHANGELOG entry "Architecture correction — module entitlement vs. per-user restriction" for why. `/org/modules` is now read-only for entitlement; an Org Admin's actual control is restricting specific members from an already-entitled module.

4. Log in as an Organization Admin (your second test account from Phase 3) and confirm you can see **Modules** (`/org/modules`) but it's read-only for entitlement.
5. As Super Admin, go to `/admin/organizations`, click **"Manage modules"** next to that org, and grant **Demo**.
6. Visit `/demo` as that org admin — it should render and let you add notes. As Super Admin, revoke Demo for that org from the same page and confirm `/demo` 404s again for the org admin.
6. From `/admin/modules`, disable Demo platform-wide and confirm it 404s even for an org that had it enabled — global disable overrides everything below it in the chain.

## Definition of Done checklist (per Phase 0 §108)

- [x] Functionality implemented (scope above)
- [x] Security reviewed (every module-gated route 404s rather than 403ing on denial, per Phase 0 §6; dependency checks prevent an inconsistent enable/disable state; Super Admin checks are the same explicit identity check used elsewhere, not routed through RBAC)
- [x] Permissions tested manually (see deployment steps); `modules.manage` added to `org_admin` via its own migration, not a retroactive edit
- [x] Tenant isolation — `OrganizationModuleRepository` is properly tenant-scoped; `DemoNoteRepository` (inside the demo module itself) proves a module's own data still goes through the same `TenantScopedRepository` base every Core repository uses
- [x] Module activation/deactivation/dependency tested manually (see deployment steps' step 5–6)
- [x] Database migrations tested, including a per-module migration running and tracking separately from core
- [ ] API — module management is web-only this round
- [x] UI tested — `/admin/modules`, `/org/modules`, and the Demo module's own `/demo` page all render through the real pipeline
- [x] Errors handled
- [x] Documentation updated (this file)
- [ ] Automated tests — none added this phase; `ModuleAccessResolver` is pure logic once given fake repositories and is a strong candidate, same as `AuthorizationEngine` was flagged in Phase 3
- [x] CHANGELOG updated
- [x] Deployment considerations documented (above)
