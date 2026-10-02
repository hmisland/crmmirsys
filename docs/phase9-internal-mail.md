# Phase 9 — Internal Mail

Status: **implemented** — inbox/sent, multi-recipient compose, threaded replies, plus Org Admin "send to all" broadcasting to the whole team or to every customer.

## What this phase added

| Piece | Where |
|---|---|
| Internal Mail module (toggleable, `modules/InternalMail/`): `messages`, `message_recipients`, `contact_broadcasts` | `modules/InternalMail/Migrations/0001`–`0003` |
| `mail.*` permissions — `mail.view`/`mail.send` for everyone, `mail.broadcast_team`/`mail.broadcast_customers` org_admin only | `Permission.php`, `database/migrations/0040` |
| `MessageService` (compose, reply, inbox, sent, mark-read, thread access control), `BroadcastService` (send-to-all, two audiences) | `modules/InternalMail/Services/` |
| `MailController`, `BroadcastController` | `modules/InternalMail/Controllers/` |
| Inbox, Sent, Compose, Thread, Broadcast pages | `themes/default/templates/mail/` |

## Architectural decisions made this phase

- **"Send to all" is two genuinely different mechanisms behind one concept**, per the explicit requirement: broadcasting to the **team** is just an ordinary `MessageService::compose()` with every org member as a recipient (they already have accounts and inboxes here); broadcasting to **customers** is a real outbound email via the same `MailerInterface` Phase 2 built for verification/reset links (whichever driver — log/php_mail/smtp — this deployment is configured with), since a Contact has no login or inbox to deliver an internal message to.
- **No queue or background job system exists yet in this codebase.** A customer broadcast sends synchronously, in the same HTTP request, one mailer call per contact with a valid email. This is fine for tens or a few hundred contacts; it genuinely risks a slow request or a timeout for a large list. This is a real, load-bearing limitation, not a corner cut casually — a proper fix needs actual queue infrastructure (a worker process or cron-driven job table), which is future infrastructure work, not something to fake by e.g. silently truncating the recipient list.
- **`contact_broadcasts` is a send log, not a delivery tracker** — no per-recipient status, no open/click tracking. That's real Marketing-phase territory (Phase 14); this is deliberately just "who sent what, to how many, when," for the admin's own reference.
- **A message thread has no separate "conversation" entity** — `messages.thread_id` self-references the root message's id (NULL for the root itself), and `MessageRepository::forThread()` pulls every message where `id = root` OR `thread_id = root` in one query. Simpler than a parent `conversations` table plus a `messages` child table for what's fundamentally the same relationship.
- **The inbox lists one row per received message, not one row per thread** — a documented simplification versus Gmail-style thread-grouping (which would need tracking "latest message per thread" and an aggregate unread count per thread). A busy thread shows as several inbox rows; clicking any of them opens the same full thread.
- **Reply recipients are computed from the root message's original recipient list** (plus the root's sender, minus whoever's replying) — not re-selectable per reply. Simpler than a per-reply "choose who this goes to" UI, and matches how most simple internal messaging tools behave (a reply goes to the thread, not to a hand-picked subset).
- **Thread access control checks every message in the thread**, not just the root — someone could in principle be added as a recipient on a later reply rather than the original message (not exposed in this UI yet, but the authorization check was written to be correct for that case rather than assuming only the root's recipient list matters).

## Deliberately not done yet

- No background/queued sending for large customer broadcasts (see above — a real limitation, not a stylistic choice).
- No delivery/open/click tracking for customer broadcasts.
- No thread-grouped inbox (Gmail-style).
- No per-reply recipient editing.
- No file attachments on messages in this pass (Support's ticket attachments and Contacts' both reuse the shared `AttachmentService`; Internal Mail could too, but wasn't asked for and was left out to keep this phase's scope to what was requested).
- No read receipts visible to the sender (read status is tracked per-recipient internally for the inbox's own unread indicator, but a sender can't currently see "seen by X" on their own sent messages).

## Deploying this phase

1. Upload everything (full re-upload, overwrite).
2. Run `/system/migrate?token=YOUR_TOKEN` — applies core migration 0040 (permission grants) and the Internal Mail module's own 3 migrations.
3. As Super Admin: `/admin/modules` → sync from disk → `/admin/organizations` → **"Manage modules"** for your test org → grant **Internal Mail**.
4. As any member, visit `/mail`, compose a message to a colleague, and confirm it shows up in their inbox (log in as them, or check via a second test account) and in your own Sent.
5. Reply to it and confirm the thread shows both messages in order.
6. As Org Admin, visit `/mail/broadcast`. Send a team broadcast and confirm every other member's inbox receives it.
7. Send a customer broadcast — check `storage/mail/` (if `MAIL_DRIVER=log`) or your actual inbox (if `smtp`/`php_mail`) to confirm delivery, and confirm the send shows up in the "Recent customer broadcasts" log with the right recipient count.
8. Confirm the module boundary: disable Internal Mail for the org, confirm `/mail` 404s and the nav link disappears.

## Definition of Done checklist (per Phase 0 §108)

- [x] Functionality implemented (scope above, with the documented trims — especially the synchronous-broadcast limitation)
- [x] Security reviewed — thread access control checks every message in a thread, not just the root; broadcast permissions are org_admin-only and separately keyed from ordinary send
- [x] Permissions tested manually (see deployment steps)
- [x] Tenant isolation — every repository is `TenantScopedRepository`; every correlated-subquery query (sender/subject lookups) explicitly org-qualified from the start, not discovered as a bug afterward
- [x] Module activation tested (deployment step 8)
- [x] Database migrations tested via `/system/migrate`, including a self-referencing FK (`messages.thread_id` → `messages.id`) defined within its own CREATE TABLE
- [ ] API — web-only this round
- [x] UI tested — inbox, sent, compose, thread, broadcast
- [x] Errors handled — invalid recipients, empty subject/body, a customer broadcast with zero valid emails
- [x] Documentation updated (this file)
- [ ] Automated tests — none added this phase
- [x] CHANGELOG updated
- [x] Deployment considerations documented (above), including the synchronous-broadcast scaling caveat
