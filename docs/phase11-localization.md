# Phase 11 — Localization (English / Persian / Arabic / Turkish)

Status: **infrastructure complete, content partial** — the real translation system is fully built and working; only the nav/header and the auth pages (login, register) have actually been converted to use it so far. Every other template in the app (Contacts, Sales, Wallet, Support, Mail, Notifications, Knowledge, Org settings, ...) still renders hardcoded English text.

## An honest note on scope

Phase 1 set up a `t('some.key')` passthrough stub specifically so this day would be painless — the plan was that every template built since would already be calling `t()`, and Phase 11 would only need to fill in the real implementation. **That plan wasn't followed in practice.** Every template built across this whole build (Phases 5 through 10) used plain hardcoded English strings directly. This phase's real infrastructure is built and correct; converting the rest of the app's templates to use it is a large, ongoing mechanical task, continuing in further deliveries.

## What this phase added

| Piece | Where |
|---|---|
| `Translator` — loads a locale's language file, falls back to English for any missing key, resolves RTL/LTR direction | `app/Infrastructure/Localization/Translator.php` |
| `Locale` — per-request static holder (mirrors `CurrentUser`'s own pattern), set once in `Application::handle()` | `app/Infrastructure/Localization/Locale.php` |
| Language files: English (the required fallback), Persian, Arabic, Turkish | `/lang/en.php`, `/lang/fa.php`, `/lang/ar.php`, `/lang/tr.php` |
| The real `t()` implementation, replacing the Phase 1 stub | `app/Infrastructure/Theme/functions.php` |
| Locale resolution wired into the request lifecycle: logged-in user's own `language` → guest's session-stored choice → platform config default | `app/Core/Application.php` |
| A language switcher in the nav, and a `/lang/switch` endpoint that persists the choice (to the user's profile if logged in, to the session if not) | `themes/default/templates/layout.php`, `app/Application/Controllers/LanguageController.php` |
| `users.language`/`organizations.default_language` validation expanded from `[fa, en]` to all 4 locales | `AuthService.php`, `OrganizationService.php` |
| Converted to real translations: the main nav, the Super Admin nav, and the login/register pages | `layout.php`, `auth/login.php`, `auth/register.php` |
| **Separate fix, requested alongside this phase**: the date picker's month/year header is now clickable, opening a year-stepping month-grid quick-jump instead of requiring dozens of single-month clicks to reach a far-off date | `themes/default/assets/js/datepicker.js` |

## How this actually works

- **A language file is just a plain PHP array** (`return ['key' => 'value', ...]`), not a database table or an admin UI. Fixing a bad translation is a direct edit to the matching key in `/lang/{locale}.php` — no migration, no redeploy of code, nothing else needs to change.
- **Adding a fifth language** is: copy `/lang/en.php` to `/lang/{code}.php`, translate every value, add `'{code}'` to `Translator::SUPPORTED_LOCALES` (one line, in `Translator.php`). That's the whole change.
- **English is the required fallback for every locale.** `Translator` always loads `en.php` first; if a key is missing from, say, `tr.php`, the English value is used for just that key rather than the page breaking or showing the raw key. Every key that exists anywhere in the app must exist in `en.php`; a translated locale can lag behind without anything failing.
- **Direction (RTL/LTR) follows the locale automatically** — Persian and Arabic are RTL, English and Turkish are LTR, resolved by `Translator::direction()` and applied to `<html dir="...">` in `layout.php`.
- **A logged-in user's language is their own `users.language` field** (already existed since Phase 2/3 for exactly this, just unused until now). A guest (not logged in — the login/register pages themselves) gets a session-stored choice instead, since there's no user row yet to persist it to; that choice carries over automatically once they do log in or register, because `AuthService`'s own registration flow already defaults a new user's language to the platform config unless told otherwise — a guest's session choice isn't currently threaded into that default, which is a real, small gap: if a guest switches to Arabic on the login page and then registers, their new account defaults to the platform's language, not the Arabic they just picked. Worth fixing in a follow-up.

## Deliberately not done yet

- **Every other template still shows English**, unconditionally, regardless of the selected locale — Contacts, Companies, Leads, Sales, Wallet, Support, Mail, Notifications, Knowledge Base, Files, Org settings, and every admin page. This is the bulk of the remaining Phase 11 work, continuing module by module in further deliveries.
- The small gap noted above: a guest's language choice on the login page doesn't carry through to their new account's default language at registration.
- No per-organization language restriction (an org admin can't currently limit which of the 4 languages their members may choose — every member can pick any of the 4 regardless of the org's own default).
- No translation-completeness tooling (no report of "which keys are missing from tr.php," for instance) — checked manually via the safety sweep before each delivery instead.

## Deploying this phase

1. Upload everything (full re-upload, overwrite) — this phase adds new Core files (`/lang/*.php`, `Translator.php`, `Locale.php`, `LanguageController.php`) and edits `Application.php`/`functions.php`/`layout.php`, but **no new migrations**.
2. Visit the login page while logged out. Confirm the language dropdown in the nav shows all 4 languages, and switching to Persian or Arabic flips the whole page to RTL immediately.
3. Log in, go to `/profile`, and confirm the Language field now offers all 4 options (previously only Persian/English).
4. Switch language from the nav dropdown while logged in and confirm the choice persists across a page reload (it's now saved to your own profile, not just the session).
5. Confirm the rest of the app (Contacts, Sales, etc.) still shows in English regardless of the selected language — expected for now, not a bug.
6. Open a date picker anywhere in the app, click the month/year label at the top, and confirm it opens a 12-month grid with year-stepping prev/next buttons, rather than only being able to page one month at a time.

## Definition of Done checklist (per Phase 0 §108)

- [x] Functionality implemented — infrastructure complete; content conversion partial and explicitly scoped above
- [x] Security reviewed — `/lang/switch`'s `return_to` is validated as a same-origin relative path only, never an open redirect
- [x] Permissions tested manually — the switcher works both logged-in and as a guest
- [ ] Tenant isolation — not applicable to this phase (locale is a per-user/per-guest preference, not tenant-scoped data)
- [ ] Module activation — not applicable; this is Core, not a toggleable module
- [ ] Database migrations — none needed this phase
- [ ] API — not touched this phase
- [x] UI tested — nav, login, register, language switcher, RTL/LTR flip, date picker quick-jump
- [x] Errors handled — an unsupported locale value silently falls back to English rather than erroring
- [x] Documentation updated (this file), including the honest gap analysis
- [ ] Automated tests — none added this phase
- [x] CHANGELOG updated
- [x] Deployment considerations documented (above)
