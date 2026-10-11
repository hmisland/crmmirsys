# Client Records module (پرونده مراجع)

Status: **implemented** — module key `client_records`, folder
`modules/ClientRecords/`. A structured client-history layer around the
existing Contact entity, first aimed at beauty/aesthetic clinics but
deliberately industry-neutral (dental, physio, salons, consulting, …).

This is not an EMR: no diagnoses, prescriptions, labs or clinical
decision support.

---

## 1. Audit findings (Phase 1, before any code)

| Area | What exists | Decision |
|---|---|---|
| Customers | `contacts` (Core) — name, gender, date_of_birth, phone/email (+ extra phones/emails), source, owner_id, team_id | **Reused.** A client record is 1:1 with a contact (`UNIQUE(organization_id, contact_id)`). Name, phone, email, DOB, gender and referral source are always read from the contact, never copied. |
| Organizations / tenancy | `OrganizationContext` + `TenantScopedRepository` auto-scoping; correlated subqueries must scope explicitly | Every new table carries `organization_id`; every repository extends `TenantScopedRepository`. No joins across tenant tables. |
| Users | `users` (org, team, department, role) | Specialist / provider = `users.id`, validated to belong to the caller's org. |
| RBAC | Code-defined `Permission` catalogue, `role_permissions(scope)`, `AuthorizationEngine::can()/scopeFor()`, `RbacMiddleware` | **Reused.** 9 new keys; all record checks go through `AuthorizationEngine`. |
| Module entitlement | `modules`/`organization_modules`/`user_module_overrides`, `ModuleMiddleware` (404 on deny), `ModuleAccessResolver` | **Reused.** All routes wrapped in `ModuleMiddleware('client_records')`. Integrations check the *other* module's entitlement too. |
| Activities / audit | No audit_log table; `timeline_events` (Core `TimelineService`) is the project's only audit/activity mechanism | **Reused.** Every mutation writes a timeline event on subject `client_record`. Sensitive-data views go to the file logger (an audit line, not a feed entry). |
| Follow-ups | `Modules\Followups` — `follow_ups.related_type/related_id`, `FollowUpService` (exposed in the container) | **Reused.** Follow-ups are created through `FollowUpService::create()` with `related_type='contact'`. A session stores the `follow_up_id` it spawned. No follow-up logic in this module. |
| Appointments / calendar | **Do not exist** anywhere in the codebase (confirmed again in docs/followup-engine-progress.md) | Not built. Planned visits are sessions with status `scheduled` (see §6). |
| Products / services | `products` (Finance) — the catalogue for both goods and services | **Reused.** A service record links `product_id`; `title` holds the label (and covers orgs without Finance). |
| Sales / invoices / payments | `invoices.contact_id`, `paid_total`; `payments.invoice_id` | **Reused, read-only.** Shown through `InvoiceService`/`PaymentService` with their own `invoices.view`/`payments.view` checks. Links go to `/finance/invoices/{id}`. |
| Documents / files | Core `attachments` (polymorphic, randomized names, stored outside the web root, real MIME check); Knowledge file library is org-wide, not per-record | **Reused `AttachmentService`** for storage. A small metadata table adds kind/category/session/service/date. |
| Notes | Core `NoteService` (polymorphic) | **Reused** with `notable_type='client_record'`. |
| Custom fields | Core `custom_field_definitions/values` keyed by `entity_type` | **Reused** with `entity_type='client_record'` — this is the per-organization configurable-field mechanism. |
| Search | Core `SearchService` + `/search` page | **Extended:** the search page appends client-record hits when the module is available to the user. |
| Localization | `t()` + `lang/{en,fa,ar,tr}.php`, RTL via `Translator::direction()` | All UI text in all four files. |
| API | `/api/v1` envelope (`BaseApiController`), cookie session, `_csrf` on writes; only auth endpoints exist so far | First business endpoints follow the same envelope, middleware and CSRF rule. |

## 2. Architecture decisions

**Module boundary.** Optional module with no hard dependencies
(`"dependencies": []`). Finance and Follow-ups are *soft* integrations:
each is used only when (a) its service is in the container, (b) the
module is entitled for the user, and (c) the user holds that module's
own permission. Turning Finance off simply hides the financial tab.

**New entities** (module migrations, all tenant-scoped):

```
client_records            1:1 contact. status, record_number, assigned_user_id,
                          owner/team/department (for RBAC scopes),
                          important_notes, preferences (normal),
                          sensitivities, restrictions, observations (sensitive)
client_record_services    service history / treatment course. product_id → products,
                          provider, location, status, planned_sessions,
                          started_on/completed_on, invoice_id → invoices
client_record_sessions    one visit/session. optional service_record_id,
                          session_number, session_at, status
                          (scheduled|completed|cancelled|no_show), provider,
                          notes, observations (sensitive), client_feedback,
                          next_recommended_on, follow_up_id → follow_ups,
                          invoice_id → invoices
client_record_files       metadata over a Core attachment: kind (media|document),
                          category, session/service link, taken_at, notes
```

`product_id`, `invoice_id` and `follow_up_id` reference tables owned by
other optional modules, so they are indexed but carry **no FK
constraint** (a module's migrations must run even when the other module
isn't installed). Integrity is enforced in the service layer at write
time: the referenced row is loaded through the owning module's service,
which applies tenant scoping and its own RBAC. FKs that *are* declared:
organizations, contacts (CASCADE — the contact is the root), users,
attachments (CASCADE), and the module's own tables.

Industry-specific fields (skin type, treatment area, tooth chart,
mobility score…) are **not** columns: they are custom fields on
`client_record`, configured per organization in Org settings → Custom
fields.

## 3. Permissions

| Key | Meaning | org_admin | member |
|---|---|---|---|
| `client_records.view` | open/list records | organization | organization |
| `client_records.create` | open a record for a contact | organization | organization |
| `client_records.edit` | intake, status, archive, notes | organization | own |
| `client_records.delete` | permanent delete | organization | — |
| `client_records.view_sensitive` | sensitivities/restrictions/observations, media, documents | organization | own |
| `client_records.manage_sessions` | service history + sessions | organization | own |
| `client_records.manage_media` | upload/delete before/after media | organization | own |
| `client_records.manage_documents` | upload/delete documents | organization | own |
| `client_records.export` | CSV export of the list | organization | — |

"Own" matches the record's `owner_id` **or** `assigned_user_id` (the
specialist), both evaluated by `AuthorizationEngine::can()`.
Access requires **both** the module entitlement (`ModuleMiddleware`) and
the permission (`RbacMiddleware` + a second check inside every service
method).

## 4. Security model

- Tenant isolation: all reads go through `TenantScopedRepository`; a
  record id from another organization is simply "not found" (404).
- IDOR: every child id (session, service, file, note) is re-checked to
  belong to the record in the URL before acting.
- Files are never served by path. `/client-records/{id}/files/{fileId}`
  re-checks tenant, record ownership of the file and
  `view_sensitive`; images are served inline with
  `Cache-Control: private, no-store` and `X-Content-Type-Options:
  nosniff`, everything else as a download. Media uploads are restricted
  to image types.
- Media and documents use their own attachable types
  (`client_record_media`, `client_record_doc`) which the generic
  `/records/{type}/…` controller does not accept, so they can't be
  fetched through the unprotected generic download route.
- Sensitive fields are stripped server-side (web and API) when the
  caller lacks `view_sensitive`; a sensitive view is written to the
  audit log channel.
- All writes are POST + CSRF (web and API).

## 5. UI

`/client-records` list (KPIs, search/status/specialist filters, CSV
export) → record page with a client header (avatar, status, phone,
specialist, last/next visit, alert strip, quick actions) and
server-side tabs: Overview · Intake · Services · Sessions · Appointments
· Follow-ups · Invoices · Documents · Media · Timeline. The contact page
links to its record; the sidebar gets "Client records" under CRM.

## 6. Implementation reference

**Module files** (`modules/ClientRecords/`): `ClientRecordsModule` (bootstrap +
routes), `RequireOrganizationMiddleware`, 4 repositories, services
`ClientRecordService` (record, RBAC/scope helpers, notes, custom fields),
`ClientTreatmentService` (services + sessions), `ClientFileService` (media +
documents), `ClientIntegrations` (the only gateway to Finance/Follow-ups),
`ClientTimelineService` (read-side merge); controllers
`ClientRecordController`, `ClientTreatmentController`, `ClientFileController`,
`Api/ClientRecordApiController`. Templates: `themes/default/templates/client-records/`.

**Changed existing files** (all additive): `Permission.php` (9 keys),
`database/migrations/0060_*`, `InvoiceService::list()` + `InvoiceRepository`
(optional `contact_id` filter), `FinanceModule` + `Application.php`
(`PaymentService` exposed like `InvoiceService`), `SearchController` +
`crm/search.php`, `ContactController` + `crm/contacts/show.php` (record
button), `CustomFieldAdminController` (`client_record` entity type),
`layout.php` (nav item), `functions.php` (`badge()` tones for
`planned`/`no_show`), `partials/icons.php` (5 icons), `app.css`, `lang/*.php`,
`routes/web.php` (passes the container to the two controllers).

**Web routes** (`/client-records`, all behind Auth + Module + organization):
list, `/create`, `/export`, `/for-contact/{contactId}`, `/{id}?tab=…`,
`/{id}/edit`, `/{id}/status`, `/{id}/delete`, `/{id}/notes`,
`/{id}/followups`, `/{id}/services[/{serviceId}[/edit|/delete]]`,
`/{id}/sessions[/{sessionId}[/edit|/status|/delete]]`,
`/{id}/files[/{fileId}[/delete]]`.

**API** (`/api/v1/client-records`, JSON envelope, cookie session, `_csrf` on writes):

| Method | Path | Notes |
|---|---|---|
| GET | `/` | `?q=&status=&assigned_user_id=`; scope-filtered |
| POST | `/` | create (`contact_id`, intake fields, `custom{}`) |
| GET | `/{id}` | `sensitive` is `null` without view_sensitive |
| PATCH | `/{id}` | partial update |
| GET/POST | `/{id}/services` | |
| GET/POST | `/{id}/sessions` | `observations` stripped without view_sensitive |
| GET | `/{id}/files?kind=media\|document` | metadata only; bytes via the web route |
| GET | `/{id}/timeline` | unified timeline |
| GET | `/{id}/followups` | from the Follow-ups module |
| GET | `/{id}/financials` | invoices + payments + remaining + outstanding (strings). `remaining` = grand − paid (unchanged, kept for existing consumers); `outstanding` = grand − credited − paid for issued invoices, `"0"` for drafts / cancelled — the invoice page's rule (finance remediation P2) |

**Deploying**: upload, run `/system/migrate?token=…` (core 0060 + 4 module
migrations), Super Admin → Modules → *Sync from disk*, then grant
*Client Records* per organization. Custom intake fields: Org settings →
Custom fields → "Client Records".

**Tested** (locally, real browser + kernel): create/edit/archive/restore/
delete (files removed from disk), services, sessions (auto session number,
course auto-start, follow-up creation), appointment outcomes, media/document
upload (spoofed image rejected and cleaned up), follow-up from the record,
invoices/payments/outstanding balance, timeline, custom fields of every
type, search, contact-page link, CSV export; member (OWN scope) and a
view-only custom role on web and API; module disabled → 404 everywhere
with full permissions; cross-organization ids → 404; same-org IDOR on
sessions/services/files/notes/links → 404/422; CSRF missing → 419; Super
Admin → 404; fa/ar (RTL) and en/tr (LTR) on 17 pages; mobile 375px without
horizontal overflow; regression over 28 existing pages.

## 7. Known limitations / extension points

- **No Appointment module exists.** Sessions with status `scheduled`
  play the role of booked visits inside the record (upcoming / past /
  cancelled / no-show). When an Appointment module is built, add an
  `appointment_id` column to `client_record_sessions` and show its
  appointments in the Appointments tab.
- Dates are displayed as stored (ISO Gregorian), like the rest of the
  app; input uses the CRM date picker (Jalali/Hijri/Gregorian).
- Custom-field keys are slugified from the Latin part of a label (Core
  behavior) — a purely Persian label becomes `field`, so give labels a
  Latin word or two until Core supports Unicode keys.
- Deleting a contact cascades its record rows; Core attachments of a
  deleted contact are not cleaned up (same as existing Core behavior).
  Deleting a record from this module does remove its files.
- No reporting screens yet; the schema is indexed for active records,
  sessions per period, repeat visits and follow-up completion.
- API validation errors return translation keys
  (`client_records.validation.*`) in `errors[0].meta.fields`, so any
  client can localize them.
- Timeline entries are stamped when they were recorded (audit time); a
  session back-dated to last week shows its own date in Sessions, but its
  "session recorded" event shows today.
- Media are images only; there is no thumbnail generation, the original
  file is served (max 10 MB, Core limit).
- No automated PHPUnit tests were added (PHPUnit isn't installed locally);
  `ClientRecordService::can()` and `ClientTreatmentService`'s validation
  are the best candidates once it is.
