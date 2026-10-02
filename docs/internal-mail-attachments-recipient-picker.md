# Internal Mail — Attachments + Gmail-Style Recipient Picker

Done 2026-10-02, per the client's follow-up request after the
drafts/trash/Cc-Bcc/linking work.

## 1. Attachments

Never actually implemented before this (the original architecture
diagram named `mail_attachments` but it was only ever a planned table
name — zero code existed for it).

- `message_attachments` table — same storage architecture as File
  Library (Knowledge module): files live outside the public webroot
  under a random hex filename, served only through a controlled
  `Content-Disposition: attachment` response, never requested
  directly by path. That's what makes accepting any file type safe.
- 25 MB per file, same cap as File Library.
- Added a genuinely new core capability: `Request::files(string $key): array`.
  PHP's native `$_FILES` shape for an array field (`name="attachments[]"`)
  is one array *per property* (name/type/tmp_name/...), each indexed
  by file position — not one array per file. The existing `file()`
  method only ever handled a single upload. `files()` reshapes the
  native structure into a clean list of individual per-file arrays —
  purely additive, the existing `file()` method is untouched.
- Wired into `compose()`, `saveDraft()`/`sendDraft()`, and `reply()` —
  attach while composing, while drafting (removable before sending),
  or on a reply.
- Download requires being a thread participant (sender or any
  recipient, To/Cc/Bcc alike) — enforced in
  `MessageService::requireAttachmentAccess()`, not just "logged in".

## 2. Gmail-style recipient picker

Replaced the three `<select multiple>` boxes (clunky, and the client
specifically asked for this) with `recipient-picker.js`: type a name,
a dropdown of matching colleagues appears, Enter/click commits it as
a removable pill carrying the real user id, continue typing for the
next.

The project already had `tag-input.js`, but it's free-text — any
typed text becomes a pill. That's wrong for recipients, who must be
real users. `recipient-picker.js` is a new, separate widget: it only
ever commits a pill from an actual match in the supplied colleague
list, never arbitrary text. Shares the same pill/wrapper CSS classes
as `tag-input.js` for visual consistency; only the new suggestion
dropdown needed its own CSS.

## Real bugs/risks caught before shipping

1. Declaring `MessageAttachmentRepository::delete(int $id)` would
   have overridden `BaseRepository::delete(string $table, string
   $whereSql, array $whereParams)` with an incompatible signature —
   a real PHP method-compatibility risk. Renamed to
   `deleteAttachment()` before it was ever called.
2. `visibleRecipientsFor()`'s query (added in the previous round) was
   re-swept as part of this round's safety pass — the project-wide
   automated check for the correlated-subquery-before-outer-WHERE bug
   class now runs on every Internal Mail change, not just once.
3. Verified every new `tenantFindMany`/`tenantFindOne` call added or
   touched this round against both known sub-classes of that bug
   (no `WHERE` at all; a subquery's `WHERE` appearing before the
   outer one) — repo-wide sweep came back clean.

## Deployment

1. Replace the zip
2. Run `/system/migrate` (1 new migration — `message_attachments`,
   pure `CREATE TABLE`)
3. Try it: compose a message, type a colleague's name in To — a
   dropdown should appear; attach a file; send. Open the thread and
   confirm the attachment downloads. Save a draft with an attachment,
   reopen it, confirm the attachment is listed with a Remove option.
