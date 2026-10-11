# File library — folders + thumbnails (Audit + Design)

Status: **approved 2026-10-09 (D1–D7, plus grid view) — implemented.** See §5.

## 1. Request

- The file section (`/files`, module `knowledge`) gets **folders** and
  **thumbnails**.
- The old CRM's files (already on the server, copied as-is) must show up
  in the file section with the **same folder tree**:
  `Mason/`, `Mohr/`, `مدارک حامد/`, `مدارک حسین میرشمسی/`, `هوشمند پرداز/`
  (old-system leftovers `.tmb/` and `index.html` are ignored).
- A file's **title = its filename without the extension**.
- One-time job; no general-purpose import tool. The user copies the
  folders into one server directory and the system picks them up itself.

## 2. Audit (current state)

| Area | Today |
|---|---|
| Routes | `KnowledgeModule::routes()` — `GET /files`, `POST /files/upload`, `GET /files/{id}/download`, `POST /files/{id}/delete`, uploader allow-list |
| Table | `file_library_documents` (id, organization_id, filename, original_filename, mime_type, size, description, uploaded_by_user_id, created_at). **Flat — no folders, no title.** |
| Storage | `storage/file-library/{orgId}/{random-hex}.{ext}` — outside webroot (root `.htaccess` 404s `/storage`), only ever served via the controller as `attachment` |
| Permissions | `files.view` (list/download), `files.manage` or uploader allow-list (upload/delete), `files.manage_uploaders` |
| UI | `files/index.php`: one table (file, tags, size, uploader, date, delete) + upload card. No preview. |
| Thumbnails | None in this module. `ClientFileController::show()` already has a safe inline-image pattern (allow-listed image MIME, `nosniff`, strict CSP, `private, no-store`) to reuse. |
| Server | Shared cPanel, no SSH; PHP has `gd`, `fileinfo`, `exif`. Migrations run via `/system/migrate?token=…`. |

## 3. Decisions to approve

### D1 — Folder model (schema change)
New table `file_library_folders`:
`id, organization_id, parent_id NULL (self-FK, ON DELETE RESTRICT), name VARCHAR(190), created_by_user_id NULL, created_at`,
`UNIQUE (organization_id, parent_id, name)`.
Add `file_library_documents.folder_id INT UNSIGNED NULL` (FK, ON DELETE RESTRICT; `NULL` = root).
Nested folders are supported (old folders may contain sub-folders).
Existing files stay at the root — nothing moves.

### D2 — Title column (schema change)
Add `file_library_documents.title VARCHAR(255) NULL`.
- Picked-up files: title = filename without extension (`مدرک شناسایی.jpg` → `مدرک شناسایی`).
- Normal uploads: optional "Title" field; empty → filename without extension.
- List shows title (bold) with original filename muted underneath; tag search also matches title.

### D3 — Folder UI
`/files?folder={id}`:
- Breadcrumb (`.breadcrumb`, already in app.css): Files › Mason › …
- Folder rows first (icon `folder`, name, item count), then files — same
  `.table-responsive > table.table`, so the list rules in CLAUDE.md hold.
- Managers: "New folder" (inline form), rename, delete (**only if empty**,
  `btn--danger` + `data-confirm`), "Move to folder" select on each file.
- Upload card uploads into the current folder.
- Tag search searches all folders and shows each hit's folder path.

### D4 — Thumbnails
- New route `GET /files/{id}/thumb` (`files.view`), only for
  `image/jpeg|png|gif|webp`. GD resizes to max 320×320 (sharp in the grid on 2x screens) (keeps ratio,
  respects EXIF rotation for JPEG), saves cache
  `storage/file-library/{orgId}/thumbs/{id}.jpg`, generated lazily on
  first request, deleted together with the file.
- Served with the ClientRecords hardening headers (inline, `nosniff`,
  strict CSP, `private`).
- Non-images show a type icon instead (pdf / doc / sheet / archive /
  generic) — new `<symbol>`s in `icons.php` where missing.
- New `GET /files/{id}/view`: opens images (and PDF) inline in a new tab;
  everything else keeps the existing attachment download.
- List gets a first column with a 48px rounded thumbnail (`loading="lazy"`);
  new CSS component `.file-thumb` built from existing tokens only.
- Grid/list toggle: **added on approval** — `?view=grid` (kept across folder links and form posts), reusing `.media-grid` / `.media-card`.

### D5 — How the old files are picked up ("system reads it itself")
One inbox directory per organization, outside the webroot:

```
<CRM root>/storage/file-library/{orgId}/inbox/
    Mason/...
    Mohr/...
    مدارک حامد/...
    مدارک حسین میرشمسی/...
    هوشمند پرداز/...
```

When a user with `files.manage` opens `/files` and the inbox is not empty,
a notice appears: "N files found in the server inbox — Add them" (one
button, CSRF POST). On click the system, in batches of ~200 files per
request (shared-host timeout safe, auto-continues):
1. creates the matching folder tree (reusing a folder if the name already exists),
2. **moves** each file into normal storage (random name, like uploads) and
   creates the DB row (title per D2, uploader = the clicking admin),
3. skips `.tmb/`, `index.html`, dot-files and empty files,
4. removes emptied inbox folders.

Moving (not copying) makes it idempotent: a re-run only sees what is left.
The 25 MB upload limit does **not** apply to inbox files (they are
already on the server). No permission/RBAC changes. The org id is shown
in that notice so the user knows which `{orgId}` folder to use.

Alternative rejected: browsing the disk folder directly without DB rows —
would lose tags, permissions, tenant scoping and audit of who uploaded.

### D6 — Persian filenames
Linux stores UTF-8 names fine. Risk is only in how the files reach the
server: if they are uploaded as a zip and extracted in cPanel, the zip
must carry UTF-8 names (7-Zip, or Windows 11 "Compress to ZIP"). The
pick-up step skips any name that isn't valid UTF-8 and lists it, rather
than importing a garbled title.

### D7 — Delivery
Migrations: `Knowledge/Migrations/0005_create_file_library_folders_table.php`,
`0006_add_folder_and_title_to_file_library_documents.php` (run via
`/system/migrate`). All new UI strings in en/fa/ar/tr/fr. Tests in
`tests/lite` for: folder tenant isolation, cannot delete non-empty
folder, thumb refuses non-image + other-org file, inbox pick-up skips
`.tmb`/`index.html`/path traversal names and is idempotent. CHANGELOG +
`upload.zip` rebuild.

## 4. Out of scope
Drag-and-drop, per-folder permissions, bulk download/zip,
the ClientRecords file module.

## 5. Implementation notes

- Migrations (module `knowledge`): `0005_create_file_library_folders_table`,
  `0006_add_folder_and_title_to_file_library_documents` — run `/system/migrate?token=…`.
- New routes: `POST /files/folders`, `/files/folders/{id}/rename`,
  `/files/folders/{id}/delete`, `/files/{id}/move`, `/files/inbox/import`;
  `GET /files/{id}/thumb`, `/files/{id}/view`. Same module/RBAC gates as
  before: viewing needs `files.view`, every change needs `files.manage` or
  the uploader allow-list. No permission changes.
- Thumbnails: `storage/file-library/{org}/thumbs/{id}.jpg`, regenerated when
  the source is newer, skipped above 25 MP (type icon shown instead).
  Served `image/jpeg`, `nosniff`, `default-src 'none'`, `private, max-age=86400`.
- `/view`: images get a strict CSP; PDFs get none (Chrome's viewer breaks
  under one) but keep `nosniff` + their detected type. Everything else is a
  download. Downloads now send an RFC 5987 `filename*` so Persian names survive.
- Inbox: `storage/file-library/{org}/inbox/`. 20 s budget per click; skipped
  items (empty files, non-UTF-8 names) stay in the inbox and are listed.
- Search (`?q=`, old `?tag=` still works) matches title, filename, exact tag
  and folder names, across all folders.
- Tests: `tests/lite/FileLibraryTest.php` (F1–F10).
