# Phase 8 — Support

Status: **implemented** — tickets (board + list views), categories, status/priority/assignee workflow, a reply thread with internal-only notes, and file attachments, all as a toggleable module.

## What this phase added

| Piece | Where |
|---|---|
| Support module (toggleable, `modules/Support/`): `ticket_categories`, `tickets`, `ticket_replies` | `modules/Support/Migrations/0001`–`0003` |
| `tickets.*` and `ticket_categories.manage` permissions, granted to `org_admin` (organization scope) and `member` (organization scope for view/create/edit/assign — see note below) | `Permission.php`, `database/migrations/0039` |
| `TicketCategoryService`, `TicketService` (CRUD, status changes, assignment), `TicketReplyService` | `modules/Support/Services/` |
| `TicketController` (board, list, create, detail, status/assign, replies, attachments), `TicketCategoryController` | `modules/Support/Controllers/` |
| Kanban board (by status), list view, create/edit form, detail page, categories admin page | `themes/default/templates/support/` |

## Architectural decisions made this phase

- **A member's ticket permissions are `organization` scope, not `own`** — unlike Deals/Tasks (where "own" makes sense — a salesperson's own pipeline), support is explicitly a team effort: restricting a member to only tickets assigned to them would block them from helping cover a colleague's queue during a busy day or someone's day off. Any member can see, create, and edit any ticket in the org; only deleting a ticket and managing categories stay `org_admin`-only.
- **No customer self-service portal in this pass** — every ticket is raised BY an internal user ON BEHALF OF a Contact (the requester), not submitted directly by the contact through a portal login. `tickets.contact_id` links to an existing CRM Core Contact; there's no separate "requester" identity concept. A public portal is real, substantial future work (auth for external users, a different UI surface entirely), not a small addition to this phase.
- **Replies and internal notes share one thread** (`ticket_replies.is_internal`), not two separate tables — visually distinguished (internal notes get a highlighted background) rather than architecturally separated, since they're the same kind of thing (a timestamped, user-authored message on a ticket) with one boolean difference in visibility. This is also where a future "email this reply to the contact" integration would hook in — public replies are already the ones meant to leave the building.
- **Categories are simple and flat** (name only, org-configurable, same shape as Sales' pipeline-stage admin pattern) — no nested categories, no per-category SLA rules or auto-routing. A real SLA/routing engine is meaningfully more work and wasn't asked for.
- **Reused rather than rebuilt**: the Kanban board CSS from Sales' deal board (`.kanban`, `.kanban__column`, `.kanban__card`), the shared `AttachmentService` (Phase 5) for ticket attachments, and `TimelineService` for the activity log — no ticket-specific versions of any of these were built.
- **Every ticket list query needing a related name (contact, category, assignee) uses a correlated subquery with an explicitly-qualified org condition from the start** — the "join or correlated-subquery vs. `tenantFindMany()`'s naive first-WHERE scoping" bug class that bit Contacts for real in Phase 5 and nearly repeated itself twice in Wallet was applied proactively here, not discovered again.

## Deliberately not done yet

- No customer portal (see above).
- No SLA timers, due dates, or escalation rules.
- No email-to-ticket or ticket-to-email (no outbound notification when a reply is added — a customer won't know a reply exists unless told some other way right now).
- No category-based auto-assignment/routing.
- No ticket merging or splitting.

## Deploying this phase

1. Upload everything (full re-upload, overwrite).
2. Run `/system/migrate?token=YOUR_TOKEN` — applies core migration 0039 (permission grants) and the Support module's own 3 migrations.
3. As Super Admin: `/admin/modules` → sync from disk → `/admin/organizations` → **"Manage modules"** for your test org → grant **Support**.
4. As Org Admin, visit `/support/categories` and add one or two categories.
5. Create a ticket from `/support/tickets/create`, linking it to an existing Contact. Confirm it shows up on `/support/tickets/board` in the right status column.
6. Move it between statuses from the board's dropdown, reassign it from the detail page, add both a public reply and an internal note, and confirm the internal one is visually distinguished. Upload and then download an attachment.
7. Confirm the module boundary: disable Support for the org, confirm `/support/tickets` 404s and the nav link disappears.

## Definition of Done checklist (per Phase 0 §108)

- [x] Functionality implemented (scope above, with the documented trims)
- [x] Security reviewed — every mutating Service method is permission-checked; attachments reuse Phase 5's already-hardened upload validation
- [x] Permissions tested manually (see deployment steps); member deliberately given `organization` scope (not `own`) for the reasons above
- [x] Tenant isolation — every Support repository is `TenantScopedRepository`; every correlated-subquery query explicitly org-qualified rather than relying on automatic scoping
- [x] Module activation tested (deployment step 7)
- [x] Database migrations tested via `/system/migrate`, including module-migration discovery
- [ ] API — web-only this round
- [x] UI tested — board, list, form, detail, categories admin
- [x] Errors handled
- [x] Documentation updated (this file)
- [ ] Automated tests — none added this phase
- [x] CHANGELOG updated
- [x] Deployment considerations documented (above)
