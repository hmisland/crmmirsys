# Appointments module (قرار ملاقات)

Optional module `appointments` (`modules/Appointments/`). Staff book time
for a contact (the customer) on a bookable **resource** — a person, a
place or a piece of equipment — with **appointment types**, weekly
**working hours**, **capacity** and conflict checks. Industry-neutral:
nothing in code, UI text or translations assumes a particular kind of
business.

This version is staff-operated: customers have no access to the CRM.
The data model and the separate availability service are built so a
public booking page or a customer portal can be added later on top of
the same rules (see §6).

## 1. Concepts

| Concept | Table | Notes |
|---|---|---|
| Resource | `appointment_resources` | `kind` = person / place / equipment. A person may be linked to a user (`user_id`); booking that resource assigns the appointment to that user by default. `capacity` = how many appointments may overlap on it. Inactive resources stay in history but can't be picked. |
| Working hours | `appointment_working_hours` | Weekly, per resource, several ranges per weekday (split shifts). A weekday with no range is closed. A resource with **no rows at all** has no hours defined: bookable any time, nothing checked. `weekday` = PHP `date('w')` (0 = Sunday). |
| Appointment type | `appointment_types` | Default `duration_minutes` and `buffer_minutes` (the resource stays blocked that long after each appointment of the type). `is_public` is reserved for self-service booking; no UI yet. |
| Appointment | `appointments` | `contact_id` (required), optional type / resource / `assigned_user_id`, `starts_at`/`ends_at`, `status`, `source`, `notes`, `cancel_reason`, plus `owner_id`/`team_id`/`department_id` captured from the creator for RBAC scope. |

Statuses: `requested` (waiting for confirmation — what a future online
booking starts as), `scheduled`, `confirmed`, `in_progress`, `completed`,
`cancelled`, `no_show`. `cancelled` and `no_show` free their time slot;
all others occupy it. `source`: `staff` (this version), `public`, `portal`.

## 2. Booking rules (`AppointmentService`)

- **Capacity is a hard rule**: a resource never holds more overlapping
  slot-occupying appointments than its capacity. Each appointment
  occupies `[starts_at, ends_at + its type's buffer)`. Checked inside a
  transaction with a `SELECT … FOR UPDATE` lock on the resource row, so
  two simultaneous bookings of the same resource are checked one after
  the other.
- **Warnings the user may override** ("save anyway" checkbox, `force=1`):
  the time is outside the resource's working hours, or the responsible
  staff member already has an overlapping appointment.
- Re-opening a cancelled / no-show appointment re-checks capacity.
- Editing excludes the appointment itself from the overlap check.
- Duration left empty → the type's default duration.
- Every change is written to the **contact's timeline**
  (`appointment_booked`, `appointment_rescheduled`, `appointment_<status>`,
  `appointment_deleted`), so it shows on the contact page.

## 3. Permissions

| Key | member | org_admin |
|---|---|---|
| `appointments.view` | organization | organization |
| `appointments.create` | organization | organization |
| `appointments.edit` (incl. status changes, cancel) | organization | organization |
| `appointments.delete` (permanent delete) | — | organization |
| `appointments.manage_settings` (resources, types, hours) | — | organization |

The calendar is shared front-desk data, hence organization scope for
members; narrow it per role from the roles page if needed. OWN scope
matches the creator **or** the assigned staff member. Granted by core
migration `0063_grant_appointments_permissions`. The module must also
be enabled for the organization (`ModuleMiddleware`).

## 4. Pages

| URL | What |
|---|---|
| `/appointments` | Calendar. `?view=day` (default): time rows × resource columns, 30-minute slots, range = opening hours of the shown resources (stretched to include any appointment outside them). Cell states: booked / busy (covered by an earlier appointment) / open (with a "Book" link pre-filling resource, date, time) / closed. `?view=week`: seven day columns (Saturday-first in fa/ar, Monday-first in en/tr). Filters: `date`, `resource_id`. |
| `/appointments/list` | Filterable list (text, date range, status, resource, staff). |
| `/appointments/create`, `/{id}`, `/{id}/edit` | Booking form (query string may pre-fill `date`, `time`, `resource_id`, `contact_id`, `appointment_type_id`), detail page with status actions and cancel-with-reason. |
| `/appointments/settings` | Resources and appointment types (manage_settings). A resource or type used by any appointment can't be deleted — deactivate it instead. |

Dates use the CRM date picker; dates on the calendar are shown through
`LocaleFormatter` (Jalali digits in fa). Sidebar: CRM group, after
Client Records, behind the module-access flag.

## 5. Files

```
modules/Appointments/
  module.json, AppointmentsModule.php, RequireOrganizationMiddleware.php
  AppointmentRepository.php, ResourceRepository.php,
  AppointmentTypeRepository.php, WorkingHoursRepository.php
  Services/AppointmentService.php          booking rules, RBAC, timeline
  Services/AppointmentSettingsService.php  resources, hours, types
  Services/AvailabilityService.php         working hours, free slots
  Services/CalendarService.php             day grid / week shaping
  Controllers/AppointmentController.php, AppointmentSettingsController.php
  Migrations/0001…0004
database/migrations/0063_grant_appointments_permissions.php
themes/default/templates/appointments/*.php
```

Shared files touched: `Permission.php` (5 keys), `layout.php` (sidebar
item), `functions.php` (`badge()` tones for `requested`/`confirmed`),
`app.css` ("Appointments calendar" section), `lang/*.php`.

## 6. Extending later

- **Public booking page / customer portal**: create appointments with
  `source = public|portal` and `status = requested`; offer slots from
  `AvailabilityService::freeSlots($resource, $date, $minutes, $buffer)`,
  which applies the same hours + capacity rules staff bookings use; show
  only types with `is_public = 1`. Needs its own spam protection (e.g.
  phone verification) and a decision on which resources can perform
  which types (a `appointment_type_resources` link table).
- **Reminders** (SMS / in-app) and **calendar sync** (iCal feed per
  resource, or Google Calendar API) are not built.
- **Closed dates / holidays** (exceptions to weekly hours) are not built.
- `AppointmentsModule::appointmentService()` is exposed for optional
  consumers (e.g. upcoming appointments on the contact page), the same
  pattern Followups uses.

## 7. Deployment

1. Upload the package.
2. `/system/migrate` — runs core `0063` and the module's `0001`–`0004`.
3. Super Admin → `/admin/modules` → **Sync** (registers the new module),
   then enable **Appointments** for the organizations that need it.
4. As org admin: `/appointments/settings` → add resources (with working
   hours) and appointment types.
