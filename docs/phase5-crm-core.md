# Phase 5 — CRM Core

Status: **implemented**, scoped to Phase 0 §91: Contacts, Companies, Leads, Tags, Notes, Attachments, Custom Fields, Timelines, Search.

## What this phase added

| Piece | Where |
|---|---|
| `tags`, `taggables`, `notes`, `custom_field_definitions`, `custom_field_values`, `timeline_events`, `attachments`, `companies`, `contacts`, `leads` tables | `database/migrations/0016`–`0025` |
| CRM Core permissions granted to `org_admin` (full) and `member` (view/create at organization scope, edit at own scope) | `0026_grant_crm_core_permissions.php` |
| Shared infrastructure services, reusable by any future module (Sales, Support, ...): `TimelineService`, `TagService`, `NoteService`, `CustomFieldService`, `AttachmentService` | `app/Application/Services/` |
| Entity services: `CompanyService`, `ContactService`, `LeadService` (incl. lead→contact/company conversion), `SearchService` | `app/Application/Services/` |
| `Request` extended with `file()` for uploaded-file access | `app/Core/Request.php` |
| Controllers: `ContactController`, `CompanyController`, `LeadController`, shared `RecordSupportController` (notes/tags/attachments for all three entity types), `CustomFieldAdminController`, `SearchController` | `app/Application/Controllers/` |
| Full web UI: list/create/edit/detail pages for each entity, tags/notes/attachments/timeline on every detail page, custom-field admin page, cross-entity search | `themes/default/templates/crm/`, `themes/default/templates/org/custom-fields.php` |

## Architectural decisions made this phase

- **Tags, Notes, Timeline, and Attachments are polymorphic and shared**, not per-entity tables — `taggable_type`/`notable_type`/`subject_type`/`attachable_type` use short string keys (`'contact'`, `'company'`, `'lead'`) rather than PHP class names, so the schema stays stable across future refactors. This is exactly the Phase 0 §18 "CRM Core is shared cross-cutting infrastructure" principle — Sales (Phase 6), Support (Phase 8), and anything else can attach notes/tags/attachments to their own records for free.
- **One shared `RecordSupportController`** handles notes/tags/attachments for all three entity types via a `{type}` route segment validated against an allow-list, instead of tripling that logic across three controllers.
- **Custom field values are always stored as TEXT**, regardless of `field_type` — the definition governs validation/rendering, not storage. Simpler for the MVP; revisit only if filtering/querying by custom field value at scale becomes a real need (explicitly out of scope now).
- **Timeline writes are direct service calls, not EventBus-routed** — Phase 0 §59 would prefer decoupled events, but only CRM Core writes to the timeline right now; a direct call is simpler and sufficient. Revisit once multiple modules need to react to the same action independently.
- **Attachments use a real MIME-type check** (`mime_content_type()` on the actual uploaded file) rather than trusting the browser-supplied `Content-Type` header, which is fully client-controlled. Extension allow-list, 10MB cap, and a randomized stored filename (never the user-supplied name) round out the Phase 0 §44 requirements.
- **Search is SQL `LIKE`-based**, per Phase 0 §46 — full-text/semantic/vector search is explicitly future work, not required for MVP. Each entity type is only searched if the caller has that type's view permission, so results never leak what they couldn't otherwise see.
- **Lead conversion keeps the lead row** (marks it `converted`, links `converted_contact_id`/`converted_company_id`) rather than deleting it — the lead becomes a historical record of where a contact came from.
- **A real bug was caught and fixed at the root during this phase**: a contact-list query joining `contacts` to `companies` (to show the company name) would have hit the exact same "ambiguous `organization_id` column" problem `BaseRepository`'s automatic tenant-scoping is vulnerable to whenever two tenant-scoped tables are joined. Fixed by using a correlated subquery instead of a join for that one query — flagged here because the same trap is waiting for any future join across two tenant-scoped tables; the fix is local (avoid the join, or qualify explicitly), not systemic yet.

## Deliberately not done yet

- No CSV import/export (Phase 0 §47) — listed for a later phase, not Phase 5's four named entities/infrastructure items.
- No full-text/semantic search.
- No custom field validation beyond "does the type exist" — a `select` field, for instance, doesn't yet enforce that a submitted value is actually one of its defined options.
- No bulk actions (bulk delete, bulk tag, bulk owner reassignment).
- No company "unlink" from a contact's edit form — you can set a company when creating/editing, but there's no explicit "remove company" control (the underlying field just doesn't get touched if left as-is).
- Company deletion is blocked if it still has linked contacts (a deliberate guard), but there's no UI hint about *which* contacts before you find out from the error.

## Deploying this phase

1. Upload everything (full re-upload, overwrite).
2. Run `/system/migrate?token=YOUR_TOKEN` — applies migrations 0016–0026.
3. Log in as an Organization Admin (or Member). New nav links: Contacts, Companies, Leads, Search, and (org admin only) Custom fields.
4. Create a Company, then a Contact linked to it. Add a tag, a note, and an attachment on the contact — confirm they show up and the Timeline records each action.
5. Create a Lead with a company name, then convert it — confirm it creates (or reuses) a Company and creates a Contact, and the lead shows "View converted contact."
6. As an org admin, visit `/org/custom-fields`, add a field for Contacts, then confirm it appears on a contact's detail page and saves correctly.
7. Try `/search?q=...` with a term matching something in each entity type.

## Definition of Done checklist (per Phase 0 §108)

- [x] Functionality implemented (scope above)
- [x] Security reviewed (permission checks on every mutating Service method; file upload validated by real content type, not client header; randomized storage filenames; CSRF on every state-changing form)
- [x] Permissions tested manually (see deployment steps); `org_admin`/`member` scopes set via a new migration, not a retroactive edit
- [x] Tenant isolation — every entity/shared-infrastructure repository is `TenantScopedRepository`; the one place a join would have broken that (contact list showing company name) was caught and fixed with a correlated subquery instead
- [x] Module activation — N/A, CRM Core is Core, not a toggleable module
- [x] Database migrations tested via `/system/migrate`
- [ ] API — Phase 5 is web-only this round
- [x] UI tested — list/create/edit/detail pages for all three entities, tags/notes/attachments/timeline, custom fields admin, search
- [x] Errors handled
- [x] Documentation updated (this file)
- [ ] Automated tests — none added this phase; `CustomFieldService`'s slugify/validation logic and `LeadService::convert()`'s company-matching logic are good candidates for focused unit tests later
- [x] CHANGELOG updated
- [x] Deployment considerations documented (above)
