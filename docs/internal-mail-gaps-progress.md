# Internal Mail — Closing Gaps Against the Original Architecture (§20)

Done 2026-10-02, per the client's explicit request after a spec-vs-
implementation comparison found two real gaps plus a new Cc/Bcc
requirement.

## What was missing and is now built

1. **Drafts & Trash** — the original spec said "inbox/sent/drafts/
   trash/threads model"; only inbox/sent/threads existed. Added:
   - `messages.status` (`draft`/`sent`) and `draft_recipients_json`
     — a draft's intended To/Cc/Bcc are stored as JSON, deliberately
     *not* real `message_recipients` rows, so a draft can never
     accidentally appear in someone's inbox before it's actually sent
   - `messages.deleted_by_sender_at` — the sender's own "delete" from
     Sent/Drafts, hiding it from their view only; recipients keep
     their own copies untouched
   - `message_recipients.is_deleted`/`deleted_at` — per-recipient
     trash (mirrors how `is_read` already works: one person trashing
     their copy of a shared message never affects anyone else's)
   - New pages: `/mail/drafts`, `/mail/trash` (shows both
     inbox-trashed and sent-trashed items, each independently
     restorable)

2. **Polymorphic linking + Timeline surfacing** — the spec explicitly
   required `linkable_type`/`linkable_id` and Timeline surfacing;
   neither existed. Added:
   - `messages.linkable_type`/`linkable_id` columns
   - `MessageService::send()` calls the existing `TimelineService`
     (the project's one audit/activity-feed mechanism — no second
     system built) whenever a message with a linkable is actually
     sent
   - `/mail/compose?linkable_type=contact&linkable_id=5` is a real,
     working entry point today — carried through drafts and
     validation-error redisplay via hidden fields — so a future
     "Send Email" button on a Contact/Deal/Lead/Company/Ticket/KB
     page can link straight into a tagged compose and it will just
     work. The button itself wasn't added to those pages this round
     (that's N separate small UI additions, not part of this fix),
     but the underlying capability is no longer dormant schema —
     it's exercised by the query-param path today.

3. **Cc/Bcc** (new request, not in the original gap list) —
   `message_recipients.recipient_type` (`to`/`cc`/`bcc`). Bcc is a
   real privacy boundary, not just a label: `visibleRecipientsFor()`
   hides Bcc rows from everyone except the sender and that exact
   Bcc'd recipient — enforced in the repository layer, so no
   controller or template can accidentally leak a Bcc list. A reply
   to a Bcc'd thread only re-adds the visible To/Cc participants,
   never silently exposes a Bcc'd identity to the other participants
   (matching how ordinary email clients behave).

## Real bugs caught before shipping

1. **`visibleRecipientsFor()`'s first draft used `tenantFindMany()`
   on a query whose correlated subquery (recipient name lookup) had
   its own `WHERE` appearing before the outer one** — the exact same
   bug class already fixed multiple times in Finance/Followups
   (`ProductRepository`, `ProformaRepository`, `FollowUpRuleRepository`,
   etc.). Caught by re-running the project-wide automated sweep for
   this bug class after finishing the Internal Mail changes — it's
   now a standing habit, not a one-off fix. Repo-wide sweep confirmed
   no other instance exists anywhere.
2. **`updateDraft()`'s first draft never passed the draft's existing
   `linkable_type`/`linkable_id` through when saving an edit**, which
   would have silently wiped a draft's link to its Contact/Deal/etc.
   every time it was edited. Fixed by reading the draft's current
   linkable fields first and carrying them through.
3. **`BroadcastService::broadcastToTeam()`** called
   `MessageService::compose()` with its old 4-argument signature —
   caught immediately after changing `compose()`'s signature (it now
   takes separate To/Cc/Bcc arrays) by grepping every call site
   project-wide before considering the change done.

## Deployment

1. Replace the zip
2. Run `/system/migrate` (2 new Internal Mail migrations — both pure
   `ALTER TABLE`, no data loss for existing messages)
3. Try it: `/mail/compose`, fill in Cc/Bcc, click "Save draft" —
   confirm it shows up under `/mail/drafts` and the recipient didn't
   get it yet; open the draft, click Send; confirm it now appears in
   their inbox and under `/mail/sent`; delete it from Sent and
   confirm it shows under `/mail/trash` with a Restore option
