# System-wide conventions

Rules in this file apply everywhere in the app, across every phase and
every module — past and future. Read this before adding any new form
field that matches one of the patterns below.

## Date fields — always the CRM date picker, never native `<input type="date">`

**Rule**: every date field anywhere in the system — present and future,
Core or any module — uses:

```html
<input type="text" class="crm-datepicker" name="..." value="<?= e($isoDateOrEmpty) ?>">
```

Never `<input type="date">`. This is auto-initialized on every page by
the bootstrap script at the bottom of `layout.php` — no per-page JS
needed, just the class.

### Why not the native picker

The native HTML5 date input only shows the Gregorian calendar. This
product needs Gregorian, Jalali (Persian/Shamsi), and Hijri (Islamic/
Qamari), switchable by the user, so a fully custom widget is
necessary — `themes/default/assets/js/datepicker.js`.

### How it works

- The visible `<input>` is display-only (readonly, no `name`) — it
  shows the date formatted in whichever calendar is currently active.
- A hidden sibling `<input type="hidden">` carries the field's real
  `name` and always holds the canonical value as Gregorian ISO
  (`YYYY-MM-DD`) — that's what the server actually receives and what
  every DB `DATE`/`DATETIME` column stores. The calendar system only
  changes what's *displayed*; storage is never anything but Gregorian.
- The picker popup has a small tab strip (`میلادی` / `شمسی` / `قمری`)
  to switch calendar systems. The user's choice is remembered in
  `localStorage` (`crm_calendar_system`) and reused on every date
  field from then on, site-wide.
- Until the user picks one, the **default** comes from the org's
  language: `fa` -> Jalali, everything else -> Gregorian (set in
  `layout.php`, `$lang === 'fa' ? 'jalali' : 'gregorian'`). Extend
  this mapping (e.g. `ar` -> Hijri) once more languages actually exist
  — Phase 11 (Localization) is where a real per-organization language
  setting will live; until then `app.default_language` is the only
  signal available.

### Conversion algorithms — where they come from

- **Jalali <-> Gregorian**: the standard astronomical Jalali algorithm
  (Borkowski) for the Jalali-specific math, layered on a Gregorian
  day-count pivot. That pivot deliberately does NOT use a
  hand-transcribed Julian Day Number formula — several published
  "standard" ones were tried and numerically verified (round-tripped
  against thousands of dates and known reference anchors) before
  shipping, and none of them actually round-tripped correctly when
  checked. The JS engine's own `Date` object is used instead, since it
  correctly implements proleptic Gregorian arithmetic by spec — see
  `datepicker.js`'s docblock for the full account of why.
- **Hijri <-> Gregorian**: the standard **tabular** (civil, Kuwaiti-
  algorithm) Islamic calendar — a fixed 30-year arithmetic leap cycle,
  not real moon sightings. This is accurate to within roughly +/-1-2
  days of the observation-based Umm al-Qura calendar actually used in
  Saudi Arabia. That's the normal trade-off every non-Saudi-specific
  Hijri converter makes (true moon-sighting data isn't available
  offline/programmatically) — worth knowing if a user ever asks why a
  Hijri date looks a day off from an official religious calendar.

### What this means for new forms going forward

Any new phase (Support, Marketing, Finance, whatever comes next) that
needs a date field — a due date, a birth date, an expiry, a follow-up
date, anything — uses this exact pattern. No exceptions, no "just use
the native picker for this one quick field." If a genuinely different
need comes up (a date range picker, a month-only picker), extend
`datepicker.js` rather than reaching for a different, inconsistent
widget.

## Copyright signature (per explicit request)

The product is "CRM GROUP by MIRSYS" (crmgroup.ir). Per an explicit
request, this is signed in two places, and both are standing
conventions for new files going forward — not a one-time retrofit
of the whole codebase, which would be noisy busywork for near-zero
value on files nobody reads standalone:

- **The UI footer** (`layout.php`) shows "© {year} CRM GROUP by
  MIRSYS" on every page — this is the one visible, always-present
  signature and doesn't need repeating anywhere else in the UI.
- **A short header comment** on new Core/architectural entry-point
  files — the kind of file someone would open first to understand
  the system (a new kernel/bootstrap class, a new top-level module
  bootstrap file, a new main layout/template) — reading:
  ```
  /**
   * CRM GROUP by MIRSYS — crmgroup.ir
   * Copyright (c) {year} MIRSYS. All rights reserved.
   */
  ```
  placed as the file's own top comment, before any other doc comment
  the file already has. **Not** added to every controller, service,
  repository, template, or migration — that would bury the signal
  in noise. Judgment call: if it's the kind of file a new engineer
  would open first, sign it; if it's one of hundreds of similarly-
  shaped files (another `XyzRepository.php`, another `show.php`),
  don't.
- `NOTICE.md` at the repo root carries the same copyright as a
  single canonical statement for the whole project.
