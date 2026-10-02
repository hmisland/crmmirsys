# Phase 6 — Sales

Status: **implemented**, scoped to Phase 0 §92 (Deals, Pipelines, Stages, Activities, Tasks, Calendar, Sales dashboards), with two deliberate MVP trims agreed at kickoff (see below).

## What's different about this phase

This is the **first real toggleable module** — everything before it (Auth, Organizations/RBAC, Module Manager, CRM Core) is platform/Core work that's always on. Sales lives entirely under `/modules/Sales/`, owns its own database tables and migrations (tracked separately from core, in `module_migrations`), and is gated end-to-end by the Module Manager built in Phase 4: not installed → 404, not enabled platform-wide → 404, not enabled for your organization → 404, all before RBAC is even consulted.

It replaces `modules/Demo/` as the real reference example for how a module is structured — `Demo` can now be deleted (its own docblock always said so).

## What this phase added

| Piece | Where |
|---|---|
| `pipelines`, `pipeline_stages`, `deals`, `tasks` tables | `modules/Sales/Migrations/0001`–`0004` (module-owned, not core) |
| `deals.*`, `pipelines.manage`, `tasks.*` permissions, granted to `org_admin`/`member` | `Permission.php`, `database/migrations/0027_grant_sales_permissions.php` |
| `PipelineRepository`, `PipelineStageRepository`, `DealRepository`, `TaskRepository` | `modules/Sales/` (module-owned data access) |
| `PipelineService` (incl. auto-creating a sensible default pipeline the first time an org uses Sales), `DealService` (CRUD + stage changes with won/lost handling), `TaskService` | `modules/Sales/Services/` |
| `DealController` (list, kanban board, detail, form), `PipelineController` (stage management), `TaskController` (create/toggle/delete, "My Tasks" page) | `modules/Sales/Controllers/` |
| `SalesModule` bootstrap class — wires all of the above into the container/router via the exact mechanism Phase 4 built (`register()`/`routes()`), proving that mechanism works for something real | `modules/Sales/SalesModule.php` |
| Kanban board, deal list/form/detail, pipeline settings, "My Tasks" page | `themes/default/templates/sales/` |

## Architectural decisions made this phase

- **Simple Kanban (dropdown "move to stage"), not drag-and-drop** — per the kickoff decision, for speed. The stage-change action is a normal form POST either way, so drag-and-drop can be added later as a JS enhancement over the same endpoint without a backend change.
- **`deals.currency_code` is a plain 3-letter placeholder column**, not a `currency_id` FK into a real currencies table — per the kickoff decision, the full Currency Management system (Phase 0's Rev. 3 decisions: `DECIMAL(38,18)`, centralized `currencies` table, exchange rates) is its own future phase. This column is explicitly a placeholder that gets migrated to `currency_id` once that phase exists, not a permanent design choice — flagged in the migration's own docblock so it isn't mistaken for a real currency system later.
- **"Calendar" trimmed to an upcoming-tasks list** (`/tasks`), not a full calendar UI — per the kickoff decision, consistent with "do not over-engineer the MVP." **"Sales dashboards" trimmed to per-stage totals on the Kanban board** (deal count + value sum per stage) rather than a separate BI-style dashboard page.
- **Tasks are NOT wired into Contact/Company/Lead detail pages**, even though the `tasks` table's `related_type`/`related_id` columns support it (same polymorphic pattern as notes/tags/attachments) — Core controllers (`ContactController`, etc.) must never depend on an optional module's service, or Contacts would break the moment Sales is disabled. Tasks show only on Deal detail pages (which live inside the Sales module itself, so no boundary violation there) plus the standalone "My Tasks" page. Extending this to Core entities needs a proper extension-point mechanism (e.g. an event Core dispatches that an enabled module can listen for), which doesn't exist yet — noted as a real gap, not silently worked around.
- **Sales templates render through the default theme**, not a module-owned `Templates/` folder — the same known limitation already flagged in `modules/Demo/`'s docblock, now affecting a real module instead of just the proof-of-concept. Still not built.
- **Permission grants happen at core-migration time, regardless of whether Sales is enabled anywhere yet** — per Phase 0 §6, RBAC and Module Manager are independent systems; holding `deals.view` means nothing if `ModuleMiddleware` 404s the route first. This mirrors exactly how `modules.manage` (Phase 4) and the CRM Core permissions (Phase 5) were granted.
- **Three bug classes already learned from Phase 5 were checked proactively this time**, not discovered by a crash: correlated-subquery-vs-`tenantFindMany`'s naive WHERE-injection (`DealRepository::search()` builds its own qualified org condition, same fix pattern as `ContactRepository::search()`), duplicate named PDO placeholders in one query (avoided from the start in every Sales query), and the closure-variable-scoping bug in route group closures (swept and confirmed clean before delivery, not caught by testing after).

## Deliberately not done yet

- No drag-and-drop Kanban (see above).
- No full calendar UI (see above).
- No Tasks on Contact/Company/Lead pages (see above — needs an extension-point mechanism).
- No deal-to-currency real integration (placeholder column only).
- No CSV import/export for deals.
- No email/activity logging beyond what Notes (Phase 5, reusable) already provides if someone wants to log a call as a note on a linked Contact.
- No Sales-specific reporting beyond the Kanban board's per-stage totals (full Reporting is its own later phase, Phase 13 per the roadmap).

## Deploying this phase

1. Upload everything (full re-upload, overwrite).
2. Run `/system/migrate?token=YOUR_TOKEN` — applies core migration 0027 (permission grants) AND, per the module-migration discovery already built in Phase 4, the Sales module's own 4 migrations (`pipelines`, `pipeline_stages`, `deals`, `tasks`), reported under `applied.modules.sales` in the JSON response.
3. As Super Admin, go to `/admin/modules`, click **"Sync from disk"** — Sales should now appear in the list.
4. As Super Admin, go to `/admin/organizations`, click **"Manage modules"** next to your test organization, and grant **Sales**. (Per the post-Phase-6 entitlement correction — see CHANGELOG — Org Admin can no longer self-serve this; it's a Super Admin-only "what has this org paid for" decision.)
5. The nav should now show **Deals** and **Tasks**. Visit `/deals/board` — a "Default Pipeline" with 7 stages (New → ... → Won/Lost) is created automatically the first time.
6. Create a deal, optionally linked to a Contact/Company. Move it between stages from the board. Add a task on its detail page, then check it off from `/tasks`.
7. As Org Admin, visit `/sales/pipeline` and add a custom stage — confirm it appears on the board.
8. Confirm the boundary works: as Super Admin, revoke Sales for the org from `/admin/organizations/{id}/modules`, confirm `/deals` and `/tasks` 404 immediately for that org's users, and the nav links disappear.

## Definition of Done checklist (per Phase 0 §108)

- [x] Functionality implemented (scope above, with the three documented MVP trims)
- [x] Security reviewed (module-gate + RBAC both checked; a role holding deals.* means nothing if the module is disabled; stage-change validates the target stage actually belongs to the deal's pipeline)
- [x] Permissions tested manually (see deployment steps); grants via a new migration, not a retroactive edit
- [x] Tenant isolation — every Sales repository is `TenantScopedRepository` (or, for `DealRepository::search()`'s correlated-subquery query, an explicitly-qualified equivalent — same fix already applied in Phase 5)
- [x] Module activation/deactivation/dependency tested (see deployment step 8) — this is the first real end-to-end test of Phase 4's Module Manager against something more than the Demo module
- [x] Database migrations tested, including the module-migration discovery path
- [ ] API — Sales is web-only this round
- [x] UI tested — board, list, form, detail, pipeline settings, My Tasks
- [x] Errors handled
- [x] Documentation updated (this file)
- [ ] Automated tests — none added this phase; `DealService::changeStage()`'s won/lost handling and `PipelineService::ensureDefaultPipeline()` are good candidates later
- [x] CHANGELOG updated
- [x] Deployment considerations documented (above)
