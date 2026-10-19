# Marketing — Core, SMS and Email (Sender, Brevo)

First production version of Marketing. In scope: Marketing Core, SMS
campaigns (Kavenegar) and email campaigns (Sender, Brevo). Bale, WhatsApp
and WooCommerce are out of scope.

## 1. Architecture

```
Organization settings → Messaging providers (/org/messaging, organization.edit)
   SMS — Kavenegar ── existing /org/sms (platform or own account, billing) — unchanged
   Email — Sender ─┐
   Email — Brevo  ─┴─ EmailProviderService (core) → organization_email_providers (encrypted)

Marketing module (/marketing)
   CampaignService ── AudienceResolver (contacts / group / tag / all, from the existing CRM)
         │            MessageComposer (personalization, unsubscribe link)
         ▼
   marketing_messages = per-recipient history AND the queue
         ▼
   QueueWorker (cron + campaign page ticks)
         ▼
   ChannelRegistry ─ SmsChannel   → SmsService (core) → KavenegarSmsSender / own account
                   └ EmailChannel → EmailProviderService → SenderNetEmailProvider | BrevoEmailProvider
```

- **Provider abstraction.** SMS keeps the existing `SmsSenderInterface`
  (unchanged). Two optional additions live in a new interface,
  `SmsDeliveryReportingInterface`: `sendWithLocalId()` (Kavenegar
  `localid`, provider-side deduplication) and `deliveryStatuses()`
  (Kavenegar `sms/status.json`). Email uses the new
  `EmailProviderInterface` with one adapter per provider and a
  `capabilities()` list, so unsupported features are shown as unsupported
  instead of being ignored.
- **Channels.** Marketing only talks to `MarketingChannelInterface`. A new
  channel (e.g. Bale, WhatsApp) = one class registered in
  `ChannelRegistry` + its entitlement module; the core does not change.
  A new email provider = one `EmailProviderInterface` class + one
  `EmailProviderDefinition` in `Application.php`.
- **No second contact store.** Audiences read `contacts`,
  `contact_phones`, `contact_emails`, `contact_categories` (groups) and
  `tags`/`taggables`. There was no dynamic-segment feature in the project;
  tag-based selection is offered as the dynamic list (resolved when the
  campaign is approved).
- **Audit.** Through the existing `TimelineService` (no second audit
  system): campaign created/edited/queued/cancelled/retried/test/completed
  on `marketing_campaign`; provider credentials saved/tested/disconnected,
  webhook registered, test emails, suppression changes on `organization`;
  opt-outs on the `contact`. Secrets are never written.

## 2. Entitlements

| Module key | What it unlocks |
|---|---|
| `marketing` (`modules/Marketing`) | Marketing Core + SMS campaigns |
| `marketing_email` (`modules/MarketingEmail`, depends on `marketing`) | Email channel and the Sender/Brevo settings cards |

Enforced server-side in every path: routes (`ModuleMiddleware`), the
campaign service (channel availability via `ModuleGate`), the settings
controller (every email POST), the queue worker and webhooks
(organization-level check, `Entitlements`). Without the module the
cards are still shown, muted, labelled "Module not purchased / not
available". The Kavenegar card stays interactive because the SMS account
is also used by existing (non-marketing) SMS.

## 3. Permissions

| Key | Default |
|---|---|
| `marketing.view` | org_admin, member |
| `marketing.create` (drafts, test send) | org_admin, member |
| `marketing.send` (approve & send, cancel, retry) | org_admin |
| `marketing.manage_templates` | org_admin |
| `marketing.manage_suppressions` | org_admin |

Provider credentials: `organization.edit` (existing). A member's draft
needs someone with `marketing.send` to approve it.

## 4. Data (migrations)

| Migration | Table |
|---|---|
| core `0080_create_organization_email_providers_table` | provider account per organization: encrypted secret + last 4 chars hint, sender identity, status, last test, webhook key + encrypted bearer secret |
| Marketing `0001` | `marketing_templates` |
| Marketing `0002` | `marketing_campaigns` |
| Marketing `0003` | `marketing_messages` (UNIQUE campaign+channel+address) |
| Marketing `0004` | `marketing_suppressions` (UNIQUE org+channel+address) |
| Marketing `0005` | `marketing_webhook_events` (UNIQUE provider+event key) |
| Marketing `0006` | default permission grants |
| Marketing `0013` | `marketing_transactional_messages` (UNIQUE org+reference) — Phase 2 transactional delivery, see §5a |

All additive: no existing table or column changes. Rollback: each
migration's `down()` drops only its own table (`0006` removes only
`marketing.*` grants). Dropping `organization_email_providers` deletes the
stored Sender/Brevo credentials (they must be re-entered).

## 5. Sending, queue and safety

- **Lifecycle:** draft → (approve, `marketing.send`) preparing → queued →
  sending → completed; cancellable until completed. At approval the
  confirmed recipient count must still match, and "all contacts" needs an
  explicit checkbox.
- **Recipients** are snapshotted at approval; opt-outs that arrive later
  are still honoured at send time.
- **Queue processing:** `modules/Marketing/bin/worker.php` (cron, every
  minute, ~50 s budget, lock file) and, while a campaign page is open, a
  JSON tick every 4 s that sends a small batch of that campaign. The
  approving request never sends.
- **No duplicate sends:** atomic row claim with a lock token; one row per
  address per campaign; SMS rows pass a per-row `localid` to Kavenegar
  (an installation prefix + row id), so even a crash-recovered row cannot
  be sent twice; an email that may have reached the provider (timeout,
  worker died) is marked failed + "ambiguous" and is never retried
  automatically, nor by "Retry failed".
- **Retries:** connection refused, 429 (honouring `Retry-After` /
  `x-sib-ratelimit-reset`) and 5xx go back to the queue with a delay, at
  most 3 attempts; a 429 stops the batch. Provider rejections (bad line,
  no credit, invalid address) are final until a user retries.
- **Status:** "sent" = accepted by the provider. "Delivered" is only set
  from provider reports: Kavenegar `sms/status.json` (polled for 47 h,
  every 5 min per message) and Brevo webhooks.
- **Opt-outs (suppression list):** manual entries, unsubscribe links,
  Brevo `hard_bounce`/`invalid_email`/`spam`/`unsubscribed`/`blocked`,
  Kavenegar status 14 (recipient blocked promotional SMS). Marketing
  messages skip all; transactional messages skip all but "unsubscribed".
- **Unsubscribe:** every marketing email gets a signed link
  (`/m/unsubscribe/{token}`, HMAC with a key derived from the app secret)
  as a footer unless the body places `{{unsubscribe_url}}`, plus
  `List-Unsubscribe` and one-click `List-Unsubscribe-Post`.
- **Webhooks:** `POST /webhooks/email/brevo/{key}` — the key selects the
  organization, `Authorization: Bearer` must match its stored secret
  (constant-time); idempotent; final states are never downgraded.
- **Privacy:** one email per recipient (nobody sees other addresses);
  message bodies are not copied per recipient; secrets are encrypted
  (`SecretBox`, AES-256-GCM), never returned, never logged.

## 5a. Transactional delivery for other modules (Phase 2)

`Services/TransactionalDelivery` implements Core's
`TransactionalDeliveryInterface` and is bound in the container through
`MarketingModule::services()`. Other modules (first: Customer
Satisfaction survey invitations) hand it ONE message; they never call an
SMS/Email/Bale provider themselves.

- **Channels:** the same `ChannelRegistry` as campaigns — SMS
  (`SmsService`, platform or own account, billing/refunds unchanged),
  Email (the organization's first connected Sender/Brevo account with a
  sender identity, sent as transactional), Bale (when `bale_messaging` is
  installed and configured). `availableChannels()` lists a channel only
  while the organization holds `marketing` and the channel's module and
  the provider is ready (e.g. Bale not paused).
- **Opt-outs:** every entry on the channel's opt-out list blocks the
  message, whatever the reason (including "unsubscribed": a survey
  request is not a receipt). Recorded as `skipped`.
- **Idempotency:** one row per (organization, caller reference). Sent →
  the same "accepted" answer again, nothing sent. A row is claimed before
  the provider call; a live claim answers "retry later". A row left
  claimed by a run that died: SMS asks the provider for its dedupe id
  (found → sent; otherwise resent safely, the localid blocks a duplicate
  and `billed_units` a second charge); email/Bale → "unknown", final.
  Provider dedupe ids use their own range (`900 000 000 000` + row id), so
  they never collide with campaign rows.
- **Answers:** accepted (provider accepted — not delivered); retry
  (nothing sent: provider down, rate limited, account paused, channel not
  ready); failed (invalid address, opted out, unsupported recipient,
  rejected, unknown). Error values are translation keys
  (`marketing.transactional.error.*`).
- **History:** `marketing_transactional_messages` (status, provider id,
  attempts, error) and a `marketing.transactional_sent` event on the
  contact's timeline. The message text is not stored: it can carry a
  single-use public link. (The SMS account's own `sms_logs` still records
  SMS texts, as for every SMS in the app.)
- **Not included:** delivery reports for transactional messages (SMS
  report polling and email webhooks cover campaign rows only), no UI list
  yet (the caller shows its own status, e.g. the CX invitations list).

## 6. Provider capabilities (verified against official docs, 2026-10)

| | Sender (`api.sender.net/v2`) | Brevo (`api.brevo.com/v3`) | Kavenegar |
|---|---|---|---|
| Auth | `Authorization: Bearer` | `api-key` header | key in URL (existing) |
| Send | `POST message/send` | `POST smtp/email` | `sms/send.json` (+`localid`) |
| Connection test | `GET campaigns?limit=1` (read only) | `GET account` + `GET senders` | `account/info.json` (existing) |
| Reply-to | not in the API → unsupported | yes | — |
| Sender verified check | not in the API → "unknown" + warning | `senders[].active` | — |
| Delivery / bounce / unsubscribe | not implemented (account-level dashboard webhooks without a documented payload) | webhooks | `sms/status.json` |
| Webhook registration | — | `POST webhooks` (transactional, bearer auth) | — |

Both email APIs used are their transactional single-message endpoints,
called once per recipient. Check each provider's acceptable-use rules for
sending marketing content this way.

## 7. Limitations / manual steps

- **Scheduled sending is not included**: there was no job scheduler in the
  project; only immediate sending is offered.
- A cron job is needed for sending without an open campaign page and for
  SMS delivery reports. Without it, a dashboard warning appears after 10
  minutes.
- Sender: no delivery events, no reply-to, sender verification cannot be
  checked by API.
- Brevo: the sender address must be an active sender in Brevo, and the
  domain should be authenticated (SPF/DKIM) in Brevo; this is reported as
  a warning, never assumed. Webhooks need `APP_URL` with `https://`.
- Kavenegar delivery status is only available for 48 hours. Iranian
  promotional-SMS rules (opt-out wording such as «لغو۱۱» on shared lines)
  are the organization's responsibility in the message text.
- Existing system emails (verification, password reset, Internal Mail
  broadcasts) are unchanged and still use `MailerInterface`.

## 8. Tests

`MarketingProvidersTest` (15, no DB, mocked HTTP) and `MarketingFlowTest`
(27, crm_local, one rolled-back transaction, mocked providers). Mutation
checks: removing the dedupe id, the cross-org check, webhook idempotency,
the bearer check, the settings/worker entitlement checks, send-time
suppression, ambiguous-email protection, the approval count check, or
secret encryption each makes a test fail.

`TransactionalDeliveryTest` (15, crm_local, fake SMS): binding, available
channels per entitlement, send + history + timeline, idempotency, opt-out,
retry vs final failures, live and stale claims (SMS recovered from the
provider, email unknown), invalid input, tenant safety, and the Customer
Satisfaction invitation end to end. Mutation checks: removing the opt-out
check, the sent-reference idempotency, SMS stale recovery, the retry
mapping, the contact tenant check or the live-claim check each makes a
test fail.
