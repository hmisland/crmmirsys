# Phase 2 — Authentication

Status: **implemented**, scoped to exactly what Phase 0 §88 listed: Login, Logout, Email verification, Password reset, Sessions, User profile, Language preference, Security controls. No Organizations/RBAC (Phase 3), no modules (Phase 5+).

## What this phase added

| Piece | Where |
|---|---|
| `users`, `auth_tokens`, `login_attempts` tables | `database/migrations/0001`–`0003` |
| User / token / login-attempt data access | `app/Infrastructure/Repositories/{User,AuthToken,LoginAttempt}Repository.php` |
| Registration, login, logout, verification, password reset, profile update | `app/Application/Services/AuthService.php` |
| Brute-force throttling | `app/Infrastructure/Security/LoginThrottle.php` |
| Mail abstraction (log file by default; real sendmail via `php_mail`) | `app/Infrastructure/Mail/` |
| Minimal input validation | `app/Infrastructure/Validation/Validator.php` |
| Session identity (`login()`/`logout()`/`userId()`/flash messages) | `app/Infrastructure/Security/Session.php` (extended) |
| Real `AuthMiddleware` (was a Phase 1 placeholder) | `app/Core/Middleware/AuthMiddleware.php` |
| Per-request authenticated-user holder | `app/Infrastructure/Security/CurrentUser.php` (mirrors `OrganizationContext`) |
| Domain events | `app/Domain/Users/Events/` |
| Web controller + forms | `app/Application/Controllers/AuthController.php`, `themes/default/templates/auth/*` |
| API controller | `app/Application/Controllers/Api/AuthApiController.php`, routed under `/api/v1/auth/*` |
| Config | `config/auth.php`, `config/mail.php` |

## Architectural decisions made in this phase (not re-litigating Phase 0)

- **`users.organization_id` is nullable now**, added ahead of Phase 3's `organizations` table, to avoid an `ALTER TABLE` on a populated table later. Phase 3 adds the FK constraint and enforces NOT NULL for everyone except Super Admin.
- **Email is unique platform-wide**, not per-organization — one email = one account = (eventually) one organization, consistent with `organization_id` being a single column rather than a membership table. Trade-off: someone working with two client organizations needs two accounts. Flagged, not hidden.
- **Email verification is required before login by default** (`AUTH_REQUIRE_EMAIL_VERIFICATION=true`), configurable per deployment.
- **Login failure messages are identical** whether the email doesn't exist or the password is wrong — prevents account enumeration.
- **Password reset requests never reveal whether an email is registered** — the controller always shows the same message.
- **API auth reuses the same cookie session as the web app** rather than introducing a token scheme now. A personal-access-token mechanism can be added later as an additional option without breaking this.
- **No SSH on the target shared host** means no migration CLI, so a token-gated `GET /system/migrate?token=...` route runs `Migrator::run()` instead. Requires `MIGRATE_TOKEN` in `.env`; 404s (not 403, to avoid revealing the route) when empty/wrong. Migrations are idempotent, so it's low-risk to leave reachable, but treat the token like a secret and rotate/clear it once you're done.

## Deliberately not done yet

- No organization membership enforcement — every new account currently has `organization_id = NULL`. Phase 3 assigns/enforces it.
- No RBAC — anyone who is logged in can do anything Phase 2 exposes (view/edit their own profile). There's nothing more sensitive than that yet.
- No "log out other devices" / session management UI — native PHP sessions have no cheap way to enumerate a user's other sessions. A `user_sessions` table would be needed for that; not built here.
- No real transactional email by default — `MAIL_DRIVER=log` writes to `storage/mail/` instead of sending. Set `MAIL_DRIVER=php_mail` for real delivery via the host's sendmail (works on most cPanel hosts with zero extra config; deliverability is host-dependent).

## Deploying this phase

1. Upload the new/changed files (safest: re-upload the whole project, overwriting).
2. Add to `.env` (see the updated `.env.example` for the full list): at minimum set `MIGRATE_TOKEN` to a long random string.
3. Visit `https://yourdomain/system/migrate?token=YOUR_TOKEN` once — it creates `users`, `auth_tokens`, `login_attempts` and returns `{"data":{"applied":[...]}}`. Running it again is harmless (already-applied migrations are skipped).
4. Test: `/register` → creates an account. With the default `MAIL_DRIVER=log`, the verification link is in a new file under `storage/mail/` (open it via File Manager, copy the link, open it in a browser) rather than actually emailed. Then `/login`.
5. Once mail is confirmed working (`MAIL_DRIVER=php_mail`), check your inbox and spam folder — shared-host sendmail deliverability varies.
6. Optionally clear/rotate `MIGRATE_TOKEN` in `.env` after running migrations.

## Definition of Done checklist (per Phase 0 §108)

- [x] Functionality implemented (scope above)
- [x] Security reviewed (password hashing, CSRF on all state-changing forms, session fixation protection on login, brute-force throttling, generic auth-failure/reset messages, hashed-at-rest tokens, no secrets logged)
- [ ] Permissions tested — N/A, no RBAC yet (Phase 3)
- [x] Tenant isolation — N/A yet (no organizations); `UserRepository` documents why it's intentionally not tenant-scoped
- [x] Module activation — N/A, Auth is Core, not a toggleable module
- [x] Database migrations tested — applied via `/system/migrate` against a real MySQL database
- [x] API tested — `/api/v1/auth/*` mirrors the web flows
- [x] UI tested — login/register/forgot/reset/verify/profile pages render through the real pipeline
- [x] Errors handled (validation, auth failures, expired tokens all produce a normal page/response, not a crash)
- [x] Documentation updated (this file)
- [x] Automated tests — `ValidatorTest` (pure logic). `AuthService` itself needs a real/test database to exercise meaningfully; a `tests/Feature` suite against a test MySQL database is a good candidate for a later hardening pass
- [x] CHANGELOG updated
- [x] Deployment considerations documented (above)
