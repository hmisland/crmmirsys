# Bale Messaging (Safir) — module `bale_messaging`

Sends Persian text messages to contacts' mobile numbers through **Bale's
Safir service**, using each organization's own Safir account. This is
Option A of `docs/bale-messaging-design.md` (approved D1–D12): Bale is a
**channel of Marketing**, so campaigns, audiences, approval, the queue,
the worker, opt-outs and reports are Marketing's own.

> Status (2026-10-16): implemented and tested locally with a **mocked**
> Safir. No real Bale message has been sent. Not deployed.

## 1. Pieces

```
Organization settings → Messaging providers → "Bale — Safir" card
   BaleSettingsController ─ BaleSettingsService ─ organization_bale_settings (key encrypted)

Marketing campaign (channel "bale")
   CampaignService ─ AudienceResolver (contacts / group / tag / segment / all, phone columns as SMS)
        │            + consent confirmation + approval rate limit (Bale only)
   marketing_messages (queue + history) ─ QueueWorker (existing cron + page ticks)
        ▼
   BaleChannel ─ SafirClient ─ SafirTransport (CurlSafirTransport; fake in tests)
        └ BillableMessageSink (NullBillableMessageSink — future billing seam)
```

| File | Purpose |
|---|---|
| `modules/MarketingBale/module.json` | key `bale_messaging`, depends on `marketing` |
| `modules/MarketingBale/MarketingBaleModule.php` | registers the channel in Marketing's `ChannelRegistry`, the settings card, `/org/messaging/bale` routes |
| `Safir/SafirClient.php`, `SafirOutcome.php`, `SafirPhone.php`, `SafirTransport.php`, `CurlSafirTransport.php`, `SafirHttpResponse.php` | the only HTTP code; outcome mapping; number format |
| `Channels/BaleChannel.php` | `MarketingChannelInterface` + `PhoneTextChannel` + `RecoverableChannel` |
| `Services/BaleSettingsService.php`, `BaleSettingsRepository.php` | settings, encryption, status, pause |
| `Billing/BillableMessageSink.php`, `NullBillableMessageSink.php` | future billing boundary (no-op) |
| `Controllers/BaleSettingsController.php`, `themes/default/templates/org/_bale_card.php` | settings card |
| `Migrations/0001_create_organization_bale_settings_table.php` | the only new table |

The module folder is named `MarketingBale` on purpose: modules are
discovered in alphabetical folder order, so Marketing (and its
`ChannelRegistry`) is registered first.

## 2. Entitlement and permissions

- The Super Admin enables **`marketing`** and **`bale_messaging`** for an
  organization (Module manager). `bale_messaging` declares the dependency
  on `marketing`.
- The Bale channel appears in campaign forms only when the organization
  holds both modules (`ModuleGate`). The worker re-checks both modules for
  every batch; without them, messages stay queued (nothing fails, nothing
  is deleted).
- The settings card is always shown. Without both modules it is muted and
  every POST is refused in the controller.
- **No new permission keys.** `organization.edit` is needed for the
  settings. `marketing.view` / `marketing.create` / `marketing.send`
  (and `contacts.view` for unmasked recipients) work exactly as for SMS
  and email. Resending an unknown message needs `marketing.send`.

## 3. Configuration (per organization)

Organization settings → Messaging providers → **Bale — Safir**:

| Field | Notes |
|---|---|
| Safir API access key (`api-access-key`) | Issued in the Bale business panel (business.bale.ai) after creating a Safir. Stored encrypted (`SecretBox`, AES-256-GCM); only the last 4 characters are shown; empty field = keep the stored key. |
| Sender bot id (`bot_id`) | Numeric id of the Bale bot that sends. Stored as an integer (not secret). |

Status values: **Not connected**, **Configured — not verified** (saved;
Safir offers no connection test, so only the format is checked),
**Verified** (Safir accepted a message with these credentials, e.g. a
campaign test send), **Key rejected by Safir** (HTTP 401/403; sending
blocked until a new key is saved). The card also offers **Pause / Resume
sending** and **Remove credentials**. Saving never sends a message.

Global safety settings (`config/messaging.php` → `bale`, overridable in
`.env`). These are **our own** settings, not Safir limits — Safir
documents no numeric rate limit:

| Key | Default | Meaning |
|---|---|---|
| `requests_per_second` (`BALE_REQUESTS_PER_SECOND`) | 5 | spacing between requests of one process |
| `per_run` (`BALE_MESSAGES_PER_RUN`) | 200 | Bale messages per campaign per worker run / page tick |
| `rate_limit_backoff_seconds` (`BALE_RATE_LIMIT_BACKOFF`) | 60 | organization pause after code 3 / HTTP 429 |
| `pause_seconds` (`BALE_PAUSE_SECONDS`) | 600 | organization pause after code 20 / 21 |
| `approvals_per_user_per_hour` (`BALE_APPROVALS_PER_USER_PER_HOUR`) | 20 | accidental repeated approvals guard |
| `connect_timeout_seconds` / `timeout_seconds` | 5 / 15 | HTTP timeouts |
| `base_url` (`BALE_SAFIR_BASE_URL`) | `https://safir.bale.ai/api/v3` | |

## 4. Sending

- **Bulk:** Marketing → New campaign → **Bale**. Pick the audience (selected
  contacts, group, tag, saved segment, everyone), write plain text
  (≤ 4,000 characters, an application limit; personalization variables
  allowed, no `{{unsubscribe_url}}`), save, review, then approve with
  `marketing.send`. Approval requires the **consent confirmation** checkbox
  ("these recipients agreed to receive Bale messages") and the exact
  confirmed count; the audience is snapshotted then.
- **Individual:** the contact page's Marketing panel has **Send Bale
  message**, which opens a one-contact Bale campaign draft — the same
  approval, permissions, opt-outs and audit apply.
- **Test send:** the campaign's "Send a test" sends one real message to a
  number the user types (rate-limited like every test message).
- **Numbers:** read from `contacts.phone`, else the first mobile in
  `contact_phones` (as SMS), normalized by the CRM's `SmsService` rules and
  sent as `98` + 10 digits without `+`. Foreign numbers are skipped as
  **unsupported** before sending (never turned into Iranian numbers).
- **Opt-outs:** Bale has its own list (`marketing_suppressions.channel =
  'bale'`, Marketing → Opt-outs). SMS opt-outs are **not** applied to Bale
  (D11). The list is checked at approval and again right before each send.
  There is no consent model in the CRM. The consent confirmation is a
  per-campaign declaration by the approver.

## 5. Safir outcomes, queue and retries

One `send_message` request per recipient. There is no bulk endpoint.
`request_id` = `crm-` + installation prefix + `marketing_messages.id`. It
is stable for a message and reused on every safe retry.

| Safir answer | Message row | Automatic retry |
|---|---|---|
| `message_id`, no `error_data` | `sent` = **accepted by Safir** (not delivered/read) | — |
| 8 InvalidPhone, 4 InvalidInput, other HTTP 4xx | `failed` | no ("Retry failed" may requeue) |
| 17 NotBaleUser | `skipped` / `unsupported_recipient` | no |
| 3 RateLimitExceeded, HTTP 429 | back to `pending` (+1 attempt), batch stops, org paused 60 s | yes, same `request_id`, max 3 attempts |
| connection failed before sending (DNS, refused, TLS) | back to `pending` | yes, same `request_id`, max 3 attempts |
| 20 PaymentRequired, 21 MaximumContactLimitReached | back to `pending` **without** using an attempt, batch stops, campaign + card show the reason, org paused 10 min | resumes after the pause |
| HTTP 401 / 403 (key refused) | back to `pending`, status "Key rejected" | blocked until a new key is saved |
| timeout / connection lost after sending, HTTP 5xx, 2 InternalServerError, undocumented code, unreadable body, worker died mid-send | `failed` + `ambiguous` = **unknown** | **never** |

- **Unknown outcome:** shown separately (counter + "may have been sent" on
  the row). "Retry failed" does not touch it. A `marketing.send` user may
  send one unknown message again from its row, after ticking "I accept a
  possible duplicate". It is resent with the same `request_id`; Safir
  documents that a repeated `request_id` is not sent again, but the scope
  and lifetime of that guarantee are **not** documented. The action is
  audited on the campaign timeline.
- **Stale rows** (worker died while sending): marked unknown. Safir has no
  status query.
- **Workers:** the existing Marketing cron
  (`modules/Marketing/bin/worker.php`, every minute) and campaign page
  ticks. No new cron job.

## 6. Reporting

The campaign page shows total recipients, the eligible count at approval,
**accepted by provider**, failed (with a reason label such as `Safir:
safir_8_invalid_phone`), **unknown outcome**, **unsupported recipients**,
skipped/opted out, cancelled, and per-recipient rows (number, status,
reason, Safir `message_id`, time; masked without `contacts.view`). For
Bale there is no "delivered" counter and the page states that Bale/Safir
provides no delivery or read reports. Message history and the contact
panel include Bale rows. The message text is stored once per campaign,
not per recipient.

## 7. Security

- Credentials: encrypted at rest, never sent to the browser (only `••••` +
  last 4), never logged, never in timeline entries or errors. Message
  rows store fixed reason labels only — not Safir's free-text description,
  the key or the text.
- Isolation: settings are read with an explicit `organization_id` from
  `OrganizationContext` (also under a Super Admin context). Campaigns,
  messages, audiences and opt-outs are organization-pinned as before, and
  the worker runs each campaign inside its own organization.
- No logging of request bodies. Responses are read up to 64 KB, with no
  redirects and HTTPS only.

## 8. Future wallet billing (not implemented)

`BillableMessageSink::record()` is called once per **final** Safir
outcome (accepted, failed, unsupported, unknown) with `event_key =
bale:{request_id}:{outcome}`, `organization_id`, `campaign_id`,
`request_id`, `provider_message_id`, `outcome`, `reason` and `occurred_at`
(UTC). The default `NullBillableMessageSink` does nothing; a failing sink
never changes a message. A future billing module would bind its own sink
(`BillableMessageSink::class` in the container, before MarketingBale
registers) and store `event_key` under a UNIQUE index.

Open business decisions: charge on acceptance (the only signal Safir
gives) or not at all for some classes; failed and unsupported messages
(free?); unknown outcomes (hold until reconciled with the Bale business
panel?); Safir cost vs selling price, and whether template messages are
priced differently; rounding; refunds/reconciliation; reserving wallet
balance before a bulk send. **Nothing in this phase touches Wallet,
ledger, prices or Finance.**

## 9. Safir API: verified vs not

Verified from https://docs.bale.ai/safir (fetched 2026-10-16): endpoint,
`api-access-key` header, `bot_id` (Integer), `phone_number` format, `message_data.message.text`,
`request_id` idempotency statement, response `message_id` + `error_data`,
and error codes 2/3/4/8/17/20/21.

**Not documented (assumptions or unknowns):**
- HTTP status codes (we assume 401/403 = key refused, 429 = rate limit,
  5xx = server error).
- Numeric rate limits and quotas.
- Text-length limit (4,000 is our own guard).
- Scope and lifetime of `request_id` deduplication.
- Auth-error body.
- Key expiry and rotation.
- Whether promotional content must use approved templates.
- The documentation's "valid" example `+98919611003` has only 9 digits.

There are **no** delivery/read reports, webhooks, balance or
connection-test endpoints.

## 10. Migration, deployment, rollback

- Migrations: MarketingBale `0001_create_organization_bale_settings_table`
  (new table only; Marketing tables unchanged) and `0002_seed_bale_help_article`
  (Persian-only help guide `bale-safir-setup` from `database/help-content/`; data only,
  idempotent, `down()` removes only that slug). Run it with the existing
  `/system/migrate?token=…` endpoint, which runs every module's migrations.
  Locally it was applied to `crm_local` only.
- Deploy: rebuild `upload.zip` (`tools/build-upload.ps1`). It includes
  `modules/MarketingBale`, `config/messaging.php`, `lang/*` and the theme
  changes. After uploading: run the migrations, then in the Super Admin
  module manager sync modules and enable `marketing` + `bale_messaging`
  for the organization. The Marketing cron job is required for sending
  without an open campaign page.
- Rollback: disable `bale_messaging` for the organization (sending stops,
  queued rows stay queued, history stays readable). To remove it
  completely: delete `modules/MarketingBale` and run the migration's
  `down()` (drops `organization_bale_settings` — **stored Safir keys are
  lost** and must be re-entered later). Restore the other changed files
  from the backup `.backup/bale-stage-b-20261016/`. Campaign rows on
  channel `bale` remain in `marketing_*` tables.

## 11. Tests

`php tests/lite/run.php` — `BaleSafirProvidersTest` (8, no database) and
`BaleMessagingFlowTest` (24, crm_local, one rolled-back transaction).
Safir is always the fake `FakeSafirTransport`. 19 mutation checks (consent,
pause handling, code mapping, unknown handling, stale recovery,
`request_id` stability, settings isolation, entitlement, encryption,
rejected key, foreign numbers, confirmed resend, unsupported skip, per-run
cap, send-time opt-outs, approval rate limit) are each caught by at least
one test.

## 12. Daymart — manual checklist

1. Before entitlement: `/org/messaging` shows the Bale card muted; saving
   it is refused; Bale is absent from Marketing → New campaign.
2. Super Admin: sync modules, enable `marketing` (if needed) and
   `bale_messaging` for Daymart.
3. Enter Daymart's Safir `api-access-key` and `bot_id`. Check that only
   `••••` + the last 4 characters are shown, and that the status is
   "Configured — not verified".
4. Connection test: not available from Safir. Confirm that saving sent
   nothing.
5. **⚠ Requires separate explicit authorization:** one Persian test
   message (campaign → Send a test) to an authorized test number.
6. Compare the result (status "Verified", the Safir `message_id`) with
   what the recipient actually sees in Bale.
7. **⚠ Requires authorization:** a small campaign to 2–3 authorized test
   contacts.
8. **⚠ Requires authorization:** a campaign to a test contact group and
   one to a test segment (include a duplicate, an invalid number, a
   non-Iranian number and a Bale opt-out).
9. Check the campaign report: accepted / failed / unknown / unsupported /
   opted out, and the per-recipient rows.
10. Failure handling: a number without Bale (→ unsupported), "Retry
    failed", and a resend of an unknown row (if one occurs).
11. As a user of another organization: no access to Daymart's card,
    campaigns or reports (404 / refused).
12. Check that `wallets`, `wallet_ledger`, `wallet_transactions` and
    `notification_credit_transactions` are unchanged.
