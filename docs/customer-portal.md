# Customer Portal — V1 (read-only)

Module `customer_portal` (`modules/CustomerPortal`). A CRM **contact** signs in with a
one-time code and sees, read-only: profile, surveys (can answer), invoices, proformas.

## Enabling
1. Super Admin → Admin → Modules → **Sync** (registers the module), then grant it to the
   organization (organization modules screen, or the organization requests it in
   Modules & Features).
2. The organization shares its address: `{APP_URL}/customer/login/{organization-slug}`
   (shown in Organization settings → "Customer portal").
3. Surveys need Customer Satisfaction; invoices / proformas need Finance.

## Identity and session
- No `users` row, no `CurrentUser`, no staff RBAC. The customer is
  `organization_id + contact_id` in its own session keys (`CustomerSession`), checked on every
  request by `CustomerAuthMiddleware` (organization active and entitled, contact active).
- Signing in regenerates the session id; signing out removes only the customer keys.
- Absolute lifetime 8 h (plus the app's idle timeout).

## Sign-in
- `/customer/login/{slug}`: email or mobile. The matching ACTIVE contact of that organization
  gets the code at its registered email; without an email (or if email cannot really be
  delivered), at its mobile.
- `/customer/login/{slug}/national-id`: national ID → the contact's masked email / mobile →
  the customer picks one → code. The ID alone never signs in.
- Unknown, ambiguous (several contacts) or unreachable identifiers get the same answer as a
  real one; the code step then fails. Ambiguous matches are logged
  (`customer_portal.ambiguous_identifier`), never resolved by picking one.

## Codes (`customer_portal_otps`) — defaults in `config/customer_portal.php`
| Rule | Default | .env |
|---|---|---|
| Length | 6 digits (`random_int`) | — |
| Valid for | 5 min | `CUSTOMER_PORTAL_OTP_TTL_MINUTES` |
| Wrong tries per code | 5 | `CUSTOMER_PORTAL_OTP_MAX_ATTEMPTS` |
| Resend cooldown | 60 s | `CUSTOMER_PORTAL_RESEND_COOLDOWN_SECONDS` |
| Codes per contact per hour | 5 | `CUSTOMER_PORTAL_CODES_PER_CONTACT_PER_HOUR` |
| SMS template (Kavenegar verify) | `verify` | `CUSTOMER_PORTAL_SMS_TEMPLATE` |

Stored as `password_hash()` only; single-use (conditional update); a new code supersedes older
ones. Rate limits (`ActionRateLimiter`): identify 10/15 min per IP and 5/15 min per identifier;
national ID 5/h per IP and 3/h per ID; verify 20/15 min per IP; 300 identifications/h per
organization.

## Delivery
- Email: the platform mailer (as password resets). With `MAIL_DRIVER=log` outside the local
  environment, email is treated as unavailable (it would be written to `storage/mail`).
- SMS: `SmsService::sendTemplate()` (template token — never written to `sms_logs`), in the
  organization's SMS account / credit. Never Marketing (opt-outs would block it).

## Data access
`CustomerRecordsRepository`: every query filters on the session's organization AND contact in
SQL. Invoices: `issued`, `cancelled` (no drafts). Proformas: `sent` onwards. Surveys: the
contact's real invitations; answered through the invitation's own single-use public link
(looked up by id only after ownership is proven), so the anonymous link keeps working and
only one answer is possible.

## Known limitations (next phase)
- Duplicate contacts (same email / mobile / national ID) cannot sign in until merged.
- Contact emails and mobiles are not verified when staff enter them.
- The national-ID step reveals that a matching contact with contact details exists (masked).
- Printed invoices read buyer details from the live contact; V1 has no profile editing.
- No PDF / print for customers, no notes / terms shown (they may contain internal text).
- Hidden on the profile: gender, date of birth, position, category, source, notes.
- Email codes need a real mail driver (`MAIL_DRIVER=smtp` or `php_mail`).
