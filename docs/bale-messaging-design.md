# Bale Messaging (Safir) — Stage A: Audit + Design

Status: **approved (D1–D12, Option A) and implemented** — see
`docs/bale-messaging-module.md` for what was built. This document is
kept as the audit record. No real message has been sent.

---

## 1. Current architecture findings

| Area | What exists (verified in the repository) |
|---|---|
| Module registry / entitlement | `modules/*/module.json` + `ModuleRegistry` (discovery is **alphabetical by folder**). Access chain: installed → globally active → `organization_modules` (Super Admin per org) → `user_module_overrides` (`ModuleAccessResolver`). `ModuleMiddleware` returns **404** on denial. `module.json` `dependencies` are enforced per organization by `ModuleService` (a dependency must be enabled for the org first). For work that runs without a user (cron/worker), `Modules\Marketing\Services\Entitlements` re-checks the org. |
| Org settings | `/org/messaging` (`OrgMessagingController`, `organization.edit`): one card per provider. Kavenegar (`/org/sms`), Sender and Brevo (`EmailProviderService` → `organization_email_providers`). A card is muted and every POST is refused server-side when the org lacks the module. |
| Secrets | `SecretBox` (AES-256-GCM, key from `APP_KEY`). Pattern: `secret_encrypted` + `secret_hint` (last 4 chars), never returned, never logged, re-entry to replace, "disconnect" clears it. |
| RBAC / tenant isolation | `RbacMiddleware(permission)` on routes + `AuthorizationEngine::can()` re-checked in services; module permission keys through `Permission::registerModuleKeys()`, defaults granted by a migration. Every repository query is pinned to `OrganizationContext::id()`; workers use `OrganizationContext::runAs()`. |
| Contacts / mobile numbers | `contacts.phone` (main mobile) + `contact_phones` (`type = 'mobile'`). CRM canonical form: **`09XXXXXXXXX`** (`SmsService::normalizeNumber()` handles Persian/Arabic digits, `+98`, `0098`, `98…`, `9…`; foreign numbers become `00…`). |
| Lists / groups | `contact_categories` (= contact groups, one per contact, `contacts.category_id`) and `tags`/`taggables`. |
| Segments | Marketing Phase 2: `marketing_segments` (static members in `marketing_segment_contacts`, or **dynamic** rules compiled to SQL by `SegmentRules`), resolved through `SegmentAudience`. Belongs to the **Marketing** module. |
| Audience expansion | `Modules\Marketing\Services\AudienceResolver` (types `contacts`, `group`, `tag`, `all`, `segment`), keyset chunks of 500, classifies eligible / missing / invalid / suppressed / duplicate. Snapshot at approval (`audience_snapshot`), count must still match at approval. |
| Suppression / consent | `marketing_suppressions` (UNIQUE org+channel+address; reasons manual / unsubscribed / bounce / blocked …), checked at expansion **and again at send time**. **There is no consent model** in the CRM — only opt-outs. |
| Queue / jobs | No general job system (shared hosting). Marketing's queue is `marketing_messages` itself: atomic claim with lock token, `bin/worker.php` (cron, every minute, ~50 s, lock file) + page ticks every 4 s; `MAX_ATTEMPTS = 3`; ambiguous outcomes flagged and never auto-retried; stale-row recovery. |
| Channel abstraction | `MarketingChannelInterface` (`key`, `moduleKey`, `unavailableReason`, `send` → `DispatchResult{success, retryable, ambiguous, providerMessageId}`) + `ChannelRegistry`. `docs/marketing-module.md` §1 already names Bale as the intended next channel ("one class + its entitlement module"). `CampaignService` offers a channel only if `ModuleGate` allows its `moduleKey()`. |
| Existing Bale code | `modules/Notification/Gateways/BaleGateway.php` = **Bale Bot API** (`tapi.bale.ai/bot<token>/sendMessage`, `chat_id` only) for staff notifications. **Not Safir, not reused** for phone-number messaging. |
| Wallet | `modules/Wallet`: `WalletService::credit()/debit()` over `wallet_ledger`; the precedent for per-message charging is `SmsBillingInterface` (`tryCharge/refund`, bound by the Notification module). Read for planning only — **nothing is changed**. |
| Rate limiting | `ActionRateLimiter` (`rate_limit_hits`) for user actions. |
| Audit trail | `TimelineService` (campaign + organization events, never secrets). |
| Tests | `php tests/lite/run.php` (plain-PHP runner; DB tests against `crm_local` inside a rolled-back transaction; HTTP mocked). Marketing precedent: `MarketingProvidersTest` (no DB) + `MarketingFlowTest` (DB). |
| Migrations / deploy | Core `database/migrations/NNNN_*.php`, module `modules/X/Migrations/NNNN_*.php`, each with `up()`/`down()`. Deploy = `tools/build-upload.ps1` → `upload.zip` → cPanel. |

**Gap found:** no consent model (only opt-outs) — see D9. No other duplicate
system is needed: contacts, groups, tags, segments, suppression, queue and
reporting all exist.

---

## 2. Safir API — what is verified

Source: the official page **https://docs.bale.ai/safir** ("سامانه ارسال پیام
در بله (سفیر)"), fetched as raw HTML on 2026-10-16 and read in full. No
third-party library was used. Nothing below was tested against the live
API.

### 2.1 Verified (official documentation)

| Item | Documented contract |
|---|---|
| Base / version | `https://safir.bale.ai/api/v3/` (HTTPS) |
| Auth | Header `api-access-key: <key>` on every call. The key is issued to the organization after creating a Safir in the Bale business panel (business.bale.ai). |
| Sender identity | Body `bot_id` (Integer, required) = numeric id of the sending bot. |
| Send | `POST /api/v3/send_message`, JSON: `request_id` (String, optional, recommended), `bot_id`, `phone_number` (String, required), `message_data` → `message` → `text`. Also documented but **out of scope**: `file_id` (+ `upload_file`), `copy_text`, `reply_markup` (inline buttons), `is_secure`, `otp_message`, `template_message` (`template_id`, `text_fields`). |
| Recipients per request | **One** `phone_number` per request. There is **no bulk endpoint**. Bulk = one request per recipient. |
| Phone format | Must start with `98` or `+98`, followed by 10 digits, no other characters. Invalid examples: `09196111003`, `0919-611-1003`, `9196111003`. |
| Response | `message_id` (String, e.g. a UUID) + `error_data` (Array of ErrorInfo `{phone_number, code, description}`, `null` on success). The OTP example returns only `message_id`. |
| Error codes | 2 InternalServerError · 3 RateLimitExceeded · 4 InvalidInput · 8 InvalidPhone · 17 NotBaleUser (recipient has no Bale account) · 20 PaymentRequired (not enough credit) · 21 MaximumContactLimitReached (bot's contact limit) |
| Idempotency | "If the same `request_id` is sent again, the message is not sent again." Optional, recommended always. |
| Recipient prerequisite | The recipient must have a Bale account (code 17). Nothing says the recipient must have started the bot first. |
| Templates / pricing | Template messages must be created in the business panel and approved by support; the docs say this lets an organization separate promotional and service messages so each is "priced differently". No prices are given. |

### 2.2 Not documented (must not be assumed)

- HTTP status codes for errors, the response body when the key is wrong or
  revoked, and the format of a rate-limit response (only code 3 exists).
- Numeric rate limits, quotas, request or text-length limits.
- The scope and lifetime of `request_id` deduplication (per key or per bot,
  for how long).
- Delivery receipts, read status, status-query endpoint, webhooks or
  downloadable reports. **None exist in the docs.**
- An account, balance or connection-test endpoint. **None exists.**
- Key expiry, rotation or revocation behaviour.
- Whether plain `message.text` to arbitrary numbers is allowed for
  promotional content, or whether promotional sending must use approved
  templates.
- Documentation inconsistency: the valid example `+98919611003` has only 9
  digits after `+98`, which contradicts the "10 digits" rule. We will always
  send `98` + 10 digits, without `+`.

### 2.3 Consequences for the design

- "Accepted" = HTTP response with `message_id` and an empty `error_data`.
  **"Delivered" and "read" are never shown**, because there is no evidence
  source.
- No safe connection test exists (D4).
- Bulk = a queue of single requests, each with its own `request_id`.

---

## 3. Architecture options (D1)

### Option A — Bale as a Marketing channel, its own entitlement module (recommended)

`modules/MarketingBale` (module key **`bale_messaging`**, `dependencies:
["marketing"]`, the same pattern as `marketing_email`) registers a
`BaleChannel` in Marketing's `ChannelRegistry`. Bulk operations are
Marketing campaigns on channel `bale`, so the following are reused
unchanged: audience types (single contact, selected contacts, group, tag,
static + dynamic segment), the eligibility review, the snapshot,
confirmation, count-must-match, the queue and worker, claim locks, history,
the per-recipient report, retries, suppression and the contact panel.

- **Pro:** maximum reuse. No second queue, cron, audience or report system.
  The hardened, mutation-tested Marketing path applies to Bale. Least new
  code.
- **Con:** the organization needs **both** `marketing` and `bale_messaging`
  (Super Admin still enables Bale separately per organization). This
  conflicts with a strict reading of product decision #1 ("standalone").
  It also requires small, additive changes inside Marketing (§4.2). The
  folder must sort after `Marketing` (hence `MarketingBale`) so the
  Marketing registry already exists when Bale registers.

### Option B — Fully standalone module

`modules/BaleMessaging` with its own `bale_operations` / `bale_messages`
tables, its own worker, cron entry, UI and report pages. It would call
Marketing's `AudienceResolver` / segments / suppressions only when the
organization also has Marketing; otherwise only single and manual
selections, plus groups and tags, would work, and those would need a copy
of the audience reader.

- **Pro:** truly independent entitlement.
- **Con:** duplicates the queue, claim/lock, retry, recovery, report and
  history logic (about 1,500 lines that Marketing has already hardened). A
  second cron job. Segments are still only available with Marketing. This
  works against the "reuse, don't duplicate" requirement.

The rest of this document describes **Option A**. If B is chosen, I will
revise the file map and data model before writing any code.

---

## 4. Proposed file / change map (Option A)

### 4.1 New

| File | Purpose |
|---|---|
| `modules/MarketingBale/module.json` | key `bale_messaging`, depends on `marketing` |
| `modules/MarketingBale/MarketingBaleModule.php` | registers the channel, settings service, routes, permission keys |
| `modules/MarketingBale/Migrations/0001_create_organization_bale_settings_table.php` | §5 |
| `modules/MarketingBale/BaleSettingsRepository.php` | org-pinned CRUD |
| `modules/MarketingBale/Services/BaleSettingsService.php` | save / replace key, disable, status (encrypt, mask, audit) |
| `modules/MarketingBale/Safir/SafirClient.php` | the only HTTP code. Injectable transport, so tests never reach the network. Sends `request_id`, `bot_id`, `phone_number`, `message_data.message.text`. |
| `modules/MarketingBale/Safir/SafirResponse.php` | maps HTTP / `error_data` codes → accepted / failed / unsupported / retryable / unknown |
| `modules/MarketingBale/Safir/SafirPhone.php` | `09XXXXXXXXX` → `989XXXXXXXXX`; anything else → unsupported |
| `modules/MarketingBale/Channels/BaleChannel.php` | `MarketingChannelInterface` implementation |
| `modules/MarketingBale/Billing/BillableMessageSink.php` + `NullBillableMessageSink.php` | future wallet boundary (§8), no-op |
| `modules/MarketingBale/Controllers/BaleSettingsController.php` | save / disable on `/org/messaging/bale` |
| `themes/default/templates/org/_bale_card.php` | settings card (masked key) |
| `tests/lite/BaleSafirProvidersTest.php` | no DB, mocked transport |
| `tests/lite/BaleMessagingFlowTest.php` | crm_local, rolled back, mocked transport |
| `docs/bale-messaging-module.md` | final module doc (after implementation) |

### 4.2 Modified (additive only)

| File | Change |
|---|---|
| `modules/Marketing/MarketingModule.php` | expose `ChannelRegistry` through `services()` |
| `modules/Marketing/AudienceRepository.php`, `Services/AudienceResolver.php` | treat `bale` as a phone channel (same columns as SMS), normalization through the channel |
| `modules/Marketing/Channels/MarketingChannelInterface.php` | optional companion interface (e.g. `RecoverableChannel`) so `QueueWorker` asks the channel how to recover stale rows, instead of `=== 'sms'` |
| `modules/Marketing/Services/QueueWorker.php` | generic recovery / exception policy by channel. Stops the batch on Bale rate limit (3), no credit (20) and contact limit (21). |
| `modules/Marketing/Services/CampaignService.php`, `MessageComposer.php`, `SuppressionService.php`, `MarketingController.php` | `bale` accepted wherever the channel list is validated. Text only, no unsubscribe link. |
| `themes/default/templates/marketing/**` | channel label / options, plus "unsupported recipient" and "unknown outcome" counters |
| `app/Application/Controllers/OrgMessagingController.php` + `templates/org/messaging.php` | Bale card (muted without `bale_messaging`, like the email cards) |
| `lang/{en,fa,ar,tr,fr}.php` | new `bale.*` keys (added with the EOL-preserving PHP generator) |
| `CHANGELOG.md` | entry |

Not touched: Wallet, Finance, Contracts, Projects, Notification (its Bot-API
`BaleGateway` stays as it is), auth and core RBAC, existing migrations.

---

## 5. Minimal data model

**One new table** (module migration `MarketingBale/0001`):

```
organization_bale_settings
  id, organization_id (UNIQUE, FK cascade),
  bot_id BIGINT UNSIGNED NULL,
  api_key_encrypted TEXT NULL, api_key_hint VARCHAR(12) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'disconnected',   -- disconnected | configured | disabled
  last_success_at DATETIME NULL, last_error VARCHAR(255) NULL,  -- sanitized, never contains the key
  updated_by_user_id (FK set null), created_at, updated_at
```

Everything else reuses the existing Marketing tables, **without schema
changes**:

| Need | Existing field |
|---|---|
| Operation id / org / initiator / source type / safe source reference | `marketing_campaigns.id`, `organization_id`, `owner_id`, `queued_by_user_id`, `audience_type` (`contacts` / `group` / `tag` / `segment`), `audience_ids`, `audience_snapshot` |
| Recipient record | `marketing_messages` (id = stable message id, `contact_id`, `address` = normalized `98…`, `attempts`, `sent_at`, `error`, `skip_reason`, `ambiguous`, `provider_message_id` = Safir `message_id`) |
| Statuses | queued → `pending`; processing → `processing`; accepted → `sent` (already defined as "accepted by provider"); failed → `failed`; unknown → `failed` + `ambiguous = 1`; unsupported → `skipped` + `skip_reason = 'unsupported_recipient'` (code 17, or a non-Iranian number); delivered → **never set** (no evidence source) |
| Bale opt-outs | `marketing_suppressions` with `channel = 'bale'` |
| Message body | stored once per campaign (`body_text`), not per recipient |

`request_id` = installation prefix + `marketing_messages.id` (the same idea
as the Kavenegar `localid`). It is deterministic, so it does not need its
own column.

---

## 6. Permission and organization-isolation matrix

| Action | Module gate (404 if missing) | Permission | Isolation |
|---|---|---|---|
| View / edit Bale settings card | `bale_messaging` (POST refused server-side) | `organization.edit` | row by `OrganizationContext::id()`, org id never taken from the request |
| See the Bale channel in campaign forms | `marketing` + `bale_messaging` | `marketing.create` | — |
| Create draft, test send | same | `marketing.create` | contact / group / tag / segment ids verified in-org (`AudienceResolver::validate`) |
| Approve and send, cancel, retry | same | `marketing.send` | campaign looked up org-pinned; foreign id → 404 |
| History / report | `marketing` | `marketing.view` (+ `contacts.view` for unmasked rows, unchanged) | org-pinned queries |
| Worker sending | org must still hold both modules (`Entitlements`) | — | `OrganizationContext::runAs(campaign.org)`; the key is loaded only for that org |
| Bale opt-out list | `marketing` | `marketing.manage_suppressions` | org-pinned |

New permission keys: **none** in Option A (D8). Credentials are never sent
to the browser: the form shows `•••• + hint` and an empty field means "keep
the current key".

---

## 7. Bulk processing, idempotency and reporting

1. **Compose** (Persian UTF-8 text; length cap D6) → **review**: audience
   name, contacts matched, unique numbers, eligible, invalid, duplicates,
   suppressed, unsupported (non-Iranian, known before sending) → **confirm**
   (explicit checkbox, count must still match; refused if changed) →
   snapshot → rows inserted (sync ≤ 2,000, otherwise background
   preparation, as today).
2. **Worker / page tick** claims rows → `BaleChannel::send()` → one Safir
   request per row with `request_id`.
3. **Outcome mapping** (D5):

| Safir result | Row outcome | Auto-retry |
|---|---|---|
| `message_id`, empty `error_data` | `sent` (accepted) | — |
| code 8 InvalidPhone, 4 InvalidInput | `failed` | no |
| code 17 NotBaleUser | `skipped` / `unsupported_recipient` | no |
| code 20 PaymentRequired, 21 contact limit, auth rejected | `failed`. Remaining batch paused (campaign `last_error`), resumes on the next run. | no (row); campaign resumes |
| code 3 RateLimitExceeded | back to `pending`, batch stops | yes, delayed, max 3, same `request_id` |
| connection refused / DNS (request never sent) | back to `pending` | yes, max 3 |
| timeout after connect, code 2, HTTP 5xx, unparseable body, worker died mid-send | `failed` + `ambiguous` = **unknown** | **no** |

4. **Manual retry:** "Retry failed" re-queues only definite failures (as
   today). Unknown rows can be retried only one by one, with an explicit
   confirmation, and reuse the same `request_id` (D5).
5. **Throttle:** an internal, configurable cap (`config/messaging.php`
   `bale.per_run`, `bale.per_second`) clearly labelled as **our** safety
   setting, not a Safir limit (D7). Back off on code 3.
6. **Report** (campaign page, extended): total, eligible, accepted, failed
   with reasons, unknown, unsupported, suppressed, per-recipient rows with
   the Safir reference. A note says that Bale/Safir does not provide
   delivery or read reports.
7. **Accidental repeats:** one row per address per campaign (UNIQUE), approval
   transition guard, `ActionRateLimiter` on approve, and per-row
   `request_id`.

Individual sending = a one-contact campaign (`audience = contacts`,
transactional class allowed), or "test send" to a typed number by a
`marketing.create` user. Optional shortcut: D10.

---

## 8. Future wallet integration boundary (interface only)

```php
interface BillableMessageSink {
    /** Called once per state change; must be idempotent on $event['event_key']. */
    public function record(array $event): void;
}
// event: event_key = "bale:{message_id}:{outcome}", organization_id, campaign_id,
//        message_id, provider_message_id, outcome (accepted|failed|unknown|unsupported),
//        occurred_at (UTC)
```

- The default binding is `NullBillableMessageSink` (does nothing). It is
  called after each final row outcome. Sending never depends on it, and an
  exception from it is logged and never changes a message.
- A future billing module would bind its own sink and store `event_key`
  under a UNIQUE index, so retries and repeated events cannot charge twice.
- **No** wallet calls, pricing, ledger rows, balances or financial
  migrations.

Open business decisions (not implemented): whether to charge on acceptance
(the only signal Safir gives); whether failed / unsupported messages are
free; how unknown outcomes are treated (hold until reconciled?); Safir cost
versus selling price, and whether templates are priced differently;
rounding; refunds and reconciliation against the Bale business-panel
invoice; whether the wallet balance is reserved before a bulk send.

---

## 9. Migration and rollback

- Migration: `MarketingBale/0001` only (create table). No change to
  existing tables.
- Rollback: disable `bale_messaging` for the organization (sending stops at
  once; queued rows stay pending), then `down()` drops
  `organization_bale_settings` (the stored keys are lost and must be
  re-entered). Revert the files from the backup taken before
  implementation, since the project is not reliably under git. Marketing
  history rows on channel `bale` remain readable.
- No production access in this phase. `upload.zip` is rebuilt locally only.

---

## 10. Test plan

**Automated (mocked Safir, no real messages):**
- payload exactly as documented, including Persian UTF-8 text unchanged
  (`JSON_UNESCAPED_UNICODE`)
- number normalization (all CRM forms → `98…`, foreign → unsupported)
- every error code mapping in §7
- timeout and 5xx → unknown and not retried
- 429 / code 3 → delayed retry with the same `request_id`
- payment and contact-limit errors pause the batch
- single contact, selected contacts, group, tag, static and dynamic
  segment, with duplicates, invalid numbers and suppressions (at expansion
  and at send time)
- snapshot not altered by later segment edits
- double approve / double submit
- stale-row recovery
- secrets: encrypted at rest, absent from HTML / JSON / logs / exceptions /
  timeline
- cross-organization reads and writes refused (settings, campaign, report,
  contact / group / segment ids)
- module off → 404 / POST refused / worker skips
- RBAC per action
- report counters
- `NullBillableMessageSink` receives stable event keys, and **no** wallet
  or ledger row is created (row counts before and after)

Plus the full existing `php tests/lite/run.php` and mutation checks (as in
previous phases).

**Manual (Daymart):** checklist in §12. A real message only with your
explicit approval and a test number.

---

## 11. Decisions requiring approval

| # | Decision | Recommendation |
|---|---|---|
| **D1** | Architecture | **Option A** (Marketing channel + `bale_messaging` entitlement; Daymart needs `marketing` too). B only if strict independence matters more than reuse. |
| **D2** | Where settings live | Bale card on `/org/messaging` (`organization.edit`), table owned by the module |
| **D3** | Credentials | `api-access-key` (encrypted, masked) + `bot_id` (plain integer, not secret) per organization |
| **D4** | Connection test | Safir has **no** test or account endpoint. Do **not** probe with fake sends. Validate format only; status stays "configured, not verified" until a manual, explicitly confirmed test send to a number the admin types in (an existing campaign "test" action). |
| **D5** | Retry policy | as in §7. Auto-retry only when nothing could have been sent, or on code 3 with the same `request_id`. Unknown is never auto-retried. |
| **D6** | Message content | plain text only this phase (no files, buttons, OTP, secure or template messages). Length cap 4,000 characters (an internal guard: Safir documents no limit). |
| **D7** | Throttling | internal configurable cap (proposed default 5 requests/second, 200 per worker run per campaign), labelled as ours, not Safir's |
| **D8** | Permissions | reuse `marketing.create / send / view`, with no new keys. Alternative: add `bale_messaging.send` as an extra requirement. |
| **D9** | Consent gap | the CRM has opt-outs only. Proposal: Bale-specific opt-out list (`channel = 'bale'`), plus the bulk confirmation checkbox includes "recipients have agreed to receive Bale messages". No new consent model. |
| **D10** | Individual-send shortcut | a "Send Bale message" action in the contact page's Marketing panel that opens a prefilled one-contact campaign (no new send path) |
| **D11** | SMS opt-outs | **not** applied to Bale (separate channel). Alternative: Bale also honours SMS `manual` / `unsubscribed` entries. |
| **D12** | Folder name | `modules/MarketingBale` (needed for registration order in Option A) |

## 12. Daymart manual checklist (after implementation, local first)

1. Before entitlement: `/org/messaging` Bale card is muted, POST refused,
   the Bale channel is absent, and a direct URL returns 404.
2. Super Admin enables `marketing` (if not already) and `bale_messaging`
   for Daymart.
3. Enter Daymart's `api-access-key` + `bot_id`. Check that only the hint is
   shown.
4. Connection test: none available (D4). Confirm that saving sent nothing.
5. **With your explicit approval:** one Persian test message to a test
   number.
6. Compare the API response (Safir `message_id`) with what the recipient
   actually received.
7. Small batch to 2–3 authorized test numbers.
8. Batch to a contact group and to a segment (include a duplicate, an
   invalid number and an opted-out number).
9. Inspect the campaign report and the per-recipient rows.
10. Failure handling: a number without Bale (expect "unsupported"),
    retry-failed behaviour.
11. As a user of another organization: Daymart's card, campaign and report
    ids → 404 / refused.
12. Check that wallet balance, `wallet_ledger` and `wallet_transactions`
    are unchanged.

## 13. Risks and open questions for Bale

- Rate limits, `request_id` dedupe window and scope, auth-error format,
  HTTP status codes and text length: **ask Bale support**. Until then the
  conservative defaults in §7 apply.
- Whether promotional content must use approved templates (pricing and
  compliance).
- No delivery or read reports: reports can only show "accepted by Bale".
- Code 21 (bot contact limit) can stop large sends. The limit value is
  unknown.
- Credentials needed for Daymart: the Safir `api-access-key` and the
  sender `bot_id` from business.bale.ai, plus a test phone number you
  authorize.
- Option A makes Bale availability depend on the Marketing module.
