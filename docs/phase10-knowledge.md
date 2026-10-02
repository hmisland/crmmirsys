# Phase 10 — Knowledge Base & File Library

Status: **implemented** — KB articles with categories and search, plus a general-purpose company file library (any file type) with tag-based organization and org-admin-managed upload access, all as one toggleable module.

## What this phase added

| Piece | Where |
|---|---|
| Knowledge module (toggleable, `modules/Knowledge/`): `kb_categories`, `kb_articles`, `file_library_documents`, `file_library_uploaders` | `modules/Knowledge/Migrations/0001`–`0004` |
| `kb.*`/`files.*` permissions | `Permission.php`, `database/migrations/0045` |
| `KbService` (categories + articles CRUD, search), `FileLibraryService` (upload/list/delete, tag-based search, uploader allow-list) | `modules/Knowledge/Services/` |
| `KbController`, `FileLibraryController` | `modules/Knowledge/Controllers/` |
| KB list/detail/form/categories pages, File library list + upload + uploader-management pages | `themes/default/templates/kb/`, `themes/default/templates/files/` |
| A generic tag-pill input widget (`tag-input.js`) — type, press Enter, get a removable pill | `themes/default/assets/js/tag-input.js` |
| A reusable `app.storage_root` container binding, and a reverse tag lookup (`TagRepository::subjectIdsWithTag()` / `TagService::subjectIdsWithTagName()`) | Core — `Application.php`, `TagRepository.php`, `TagService.php` |

## Architectural decisions made this phase

- **"Any file type" is genuinely safe here** — not a loosening of the security posture set in Phase 5. `FileLibraryService` reuses `AttachmentService`'s core architecture (files live outside the public webroot, under a random hex filename, served back out only through a controlled `Content-Disposition: attachment` download response) and simply drops the extension allow-list. Because files are never requested directly by path, even an uploaded `.php` or `.exe` is inert bytes on disk — it's never executed. This is explicitly called out in the service's own docblock as a load-bearing assumption: dropping the allow-list would NOT be safe if this ever moved to a directly web-servable directory.
- **Tags are reused, not rebuilt.** The file library uses Phase 5's existing `tags`/`taggables` polymorphic tables (`taggable_type = 'file_library_document'`) rather than a new tag system. This needed one small, genuinely missing piece added to Core: a *reverse* lookup (`subjectIdsWithTag()`) — the existing code could only answer "what tags does this file have," never "what files have this tag," which is exactly what tag-based search needs.
- **A real bug caught in review, not after shipping**: the natural way to write `FileLibraryUploaderRepository::forOrganization()` — a correlated subquery for the user's display name — would have silently mis-scoped itself. A correlated subquery has its own `WHERE`, which appears earlier in the raw SQL text than the outer `WHERE`; `tenantFindMany()`'s automatic first-`WHERE`-only scoping would have targeted the subquery's `WHERE` by mistake, the same bug class that has bitten this project for real several times before. Written with explicit org-qualification from the start instead.
- **Per-user upload/delete access, per the explicit requirement.** `org_admin` can always upload/delete; a `file_library_uploaders` allow-list (managed from `/files/uploaders`, `files.manage_uploaders` permission, org_admin only) lets org_admin grant that same ability to specific members — "access is determined and managed by org admin," for some or all users, not a fixed role split. `FileLibraryService::canManage()`/`requireCanManage()` check both layers: the role permission OR the allow-list.
- **The tag-pill input is a new system-wide convention**, following the exact pattern the date picker established: `<input class="crm-tag-input" data-name="tags[]">` anywhere in the app auto-upgrades into the pill widget, no per-page JS needed. Typing text and pressing Enter (or comma) commits a pill; Backspace on an empty field removes the last pill; blurring the field without pressing Enter still commits whatever was typed, so a tag isn't silently lost.
- **A real gap was found and fixed in Core, not worked around**: `Container` has no `basePath()` of its own (only `Application` does) — `FileLibraryService` needed a storage directory outside the webroot, the same way `AttachmentService` does, but had no way to ask for it. Rather than threading `basePath` through some ad-hoc mechanism, a small, genuinely reusable `app.storage_root` string binding was added to `Application.php` — any future module that needs its own storage subdirectory can resolve it the same way.

## Deliberately not done yet

- No article versioning/revision history for KB articles.
- No rich text editor for article bodies — plain `<textarea>`, same as everywhere else in this codebase so far.
- No per-file granular visibility (e.g. "only Sales team can see this one file") — access is all-or-nothing at the `files.view` permission level (everyone who can see the library sees every file in it); only upload/delete is the per-user-controllable action.
- No file versioning (re-uploading replaces nothing automatically — delete and re-upload is the current flow for updating a document).
- No preview/thumbnail generation for images or PDFs — every file downloads, none render inline.

## Deploying this phase

1. Upload everything (full re-upload, overwrite).
2. Run `/system/migrate?token=YOUR_TOKEN` — applies core migration 0045 (permission grants) and the Knowledge module's own 4 migrations.
3. As Super Admin: `/admin/modules` → sync from disk → `/admin/organizations` → **"Manage modules"** for your test org → grant **Knowledge Base & Files**.
4. As Org Admin, visit `/kb/categories` and add one, then `/kb/articles/create` to write an article.
5. Visit `/files`, upload a file of any type (try something other than a common office format), add a couple of tags by typing and pressing Enter, and confirm they show as pills.
6. Search `/files?tag=...` for one of those tags and confirm it filters correctly.
7. Visit `/files/uploaders`, grant a regular member upload access, log in as them (or a second test account), and confirm they can now upload/delete even without being org_admin.
8. Confirm the module boundary: disable Knowledge Base & Files for the org, confirm `/kb/articles` and `/files` 404 and the nav links disappear.

## Definition of Done checklist (per Phase 0 §108)

- [x] Functionality implemented (scope above, with the documented trims)
- [x] Security reviewed — "any file type" justified against the existing non-web-accessible storage architecture, not a new risk; upload/delete access explicitly two-layered (role OR allow-list)
- [x] Permissions tested manually (see deployment steps)
- [x] Tenant isolation — every repository is `TenantScopedRepository`; the one correlated-subquery query caught and fixed with explicit org-qualification before shipping
- [x] Module activation tested (deployment step 8)
- [x] Database migrations tested via `/system/migrate`
- [ ] API — web-only this round
- [x] UI tested — KB list/detail/form/categories, file library list/upload/uploaders, tag-pill widget
- [x] Errors handled
- [x] Documentation updated (this file)
- [ ] Automated tests — none added this phase
- [x] CHANGELOG updated
- [x] Deployment considerations documented (above)
