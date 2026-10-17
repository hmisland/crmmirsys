# Marketing Phases 1–3 — Audit & Verification

Date: 2026-10-15. Read-only audit of Marketing Core, SMS (Kavenegar) and
Email (Sender, Brevo). No application code was changed by this audit.
Evidence: code review, the full lite test suite, and a probe script run
against `crm_local` inside one transaction that was rolled back.

Status legend: ✅ verified · ⚠️ finding (see §10) · ℹ️ by design / gap

## 1. Scope — implemented?

| Item | Status | Where |
|---|---|---|
| Marketing Core (dashboard, campaigns, templates, history, opt-outs, analytics) | ✅ | `modules/Marketing` |
| SMS via Kavenegar | ✅ | `SmsChannel` → `SmsService::sendOne` → `KavenegarSmsSender` |
| Email via Sender / Brevo | ✅ | `EmailChannel` → `EmailProviderService` → adapters |
| Campaign / audience / recipients | ✅ | `CampaignService`, `AudienceResolver`, `marketing_messages` |
| Templates + sending | ✅ | `TemplateService`, `MessageComposer`, `QueueWorker` |
| Delivery status / retry / idempotency | ✅ | `QueueWorker`, Kavenegar `localid`, `marketing_webhook_events` |
| Audit logs | ✅ written / ⚠️ F8 not viewable | `TimelineService` |
| Transactional vs marketing | ✅ separated / ⚠️ F2 | `message_class`, core `MailerInterface` untouched |
| Organization Settings | ✅ | `/org/messaging`, `/org/sms` |
| Module entitlement gating | ✅ / ⚠️ F3 | routes, services, worker, webhooks |
| Multi-tenant isolation | ✅ / ⚠️ F1 (intra-org) | explicit `organization_id` on every query |
| Opt-out / unsubscribe | ✅ / ⚠️ F3 | `marketing_suppressions`, `/m/unsubscribe/{token}` |
| Provider connection test | ✅ | Sender `GET campaigns`, Brevo `GET account`+`GET senders`, Kavenegar `account/info` |
| Test email / test SMS | ✅ / ⚠️ F6 | `/org/messaging/.../test-email`, `/org/sms/test`, campaign test send |
| Webhooks | ✅ Brevo / ℹ️ Sender not implemented | `WebhookService` |

## 2. Organization Settings

- ✅ All provider configuration is under Organization settings
  (`/org/messaging` for email, existing `/org/sms` for Kavenegar). Marketing
  has **no** provider settings of its own; it only reads them.
- ✅ Email cards without the `marketing_email` module: visible, muted
  (`provider-card--unavailable`, disabled fieldset, "Module not purchased"
  badge), and every POST is refused in `OrgMessagingController::entitledProvider()`
  (test: "entitlement: … refused server-side"; mutation-checked).
- ℹ️ The Kavenegar card is **not** muted without `marketing`: the SMS
  account also carries non-marketing SMS (notifications, test SMS), so
  locking it would break existing customers. Only the "SMS campaigns"
  badge reflects the purchase. Confirm this decision (see §10, D1).
- ✅ Kavenegar shared (platform) and organization-owned modes: code paths
  unchanged (`senderFor()`, billing, refund). `SmsSenderInterface` is
  unchanged (test asserts its method list). New behaviour is additive
  (`SmsDeliveryReportingInterface`). ℹ️ Marketing tests exercise the
  own-account path only (F10).
- ✅ Sender/Brevo secrets: `SecretBox` AES-256-GCM, only a 4-char hint is
  rendered, an empty field keeps the stored key, disconnect wipes it
  (tests + mutation "secret stored in plain text" killed).
- ✅ Sender identity: sender email + name required and validated; reply-to
  only where supported (Sender: disabled with explanation); Brevo checks
  the sender is an active sender; domain authentication is reported as a
  warning, never assumed.

## 3. Multi-tenant & security

- ✅ Every Marketing repository query carries `organization_id` from
  `OrganizationContext` (campaigns, messages, templates, suppressions,
  webhook events, audience, provider settings). Probe + test "isolation":
  another organization's campaign → 404 on show and cancel; its messages
  never appear in history.
- ✅ Selected contacts/groups/tags are validated against the organization
  (`AudienceResolver::validate`, mutation-checked).
- ✅ Backend gating: `ModuleMiddleware('marketing')` + `RbacMiddleware` on
  every route, permission re-checks in services, org-level entitlement in
  the worker and webhooks. A user override can only *restrict* a module
  (`ModuleService::setUserAccess`), so it cannot bypass entitlement.
- ⚠️ **F1** — inside one organization, Marketing exposes contact data to
  users who lack `contacts.view` (probe P2: a role with only
  `marketing.view` + `marketing.create` sees every contact's name, phone
  and email in the campaign form's picker, while `/contacts` is 403 for
  the same user).
- ⚠️ **F4** — a Super Admin (no organization) opening `/marketing` gets a
  500 (`RuntimeException: No OrganizationContext`) instead of a 404.
- ✅ Secrets in logs/exceptions: keys travel only in headers (Sender,
  Brevo) or the Kavenegar URL path, which is never logged; error texts
  shown/stored are the provider's JSON message (tests assert the key is
  absent from errors). Webhook secret encrypted; bearer compared with
  `hash_equals`.
- ✅ Webhook: URL key selects the organization, bearer token must match,
  idempotent (UNIQUE event key), entitlement checked, body size capped
  (mutations "bearer check removed" and "idempotency bypassed" killed).
  ℹ️ Not yet verified against a real Brevo delivery (F10).

## 4. Recipient selection

| Case | Status |
|---|---|
| One contact | ✅ (`contacts` with one id) |
| Several selected contacts | ✅ (picker) — ⚠️ F9 picker lists only the newest 2,000 contacts |
| Group (`contact_categories`) | ✅ |
| Segment | ℹ️ No segment feature existed; tag-based selection is the dynamic list |
| All eligible contacts | ✅ with explicit confirmation + recipient-count check |
| Duplicate address | ✅ deduplicated per campaign (resolver + UNIQUE index); test "no duplicate rows" |
| ⚠️ Large audiences | **F5** |

## 5. SMS (Kavenegar) — verified with tests (fake provider; no live SMS sent)

| Check | Status |
|---|---|
| Successful send, provider id stored | ✅ |
| Provider error (e.g. 418 no credit) → failed, shown per recipient, not auto-retried | ✅ |
| Invalid number → skipped `invalid_address`, never sent | ✅ |
| Retry: network failure → delayed retry, max 3, then failed; "retry failed" requeues | ✅ |
| Crash recovery: provider `localid` prevents a second send | ✅ (mutation killed) |
| Status tracking: `sms/status.json` → delivered / undelivered / blocked | ✅ (parser test + flow test) |
| Opt-out never sent (at approval and at send time) | ✅ (mutation killed) |
| Operator block (status 14) → automatic opt-out | ✅ |
| Audit / delivery log | ✅ `sms_logs` (existing) + `marketing_messages` + timeline |
| ⚠️ Billing on crash recovery (platform mode) | **F7** |

## 6. Email — Sender and Brevo (mocked HTTP; real Sender verified manually by the user)

Checked against the official docs (api.sender.net, developers.brevo.com).

| | Sender | Brevo |
|---|---|---|
| Auth | ✅ `Authorization: Bearer` | ✅ `api-key` header |
| Endpoint / body | ✅ `POST /v2/message/send` `{from,to,subject,html,text,headers}` | ✅ `POST /v3/smtp/email` `{sender,to[1],subject,htmlContent,textContent,replyTo,headers,tags}` |
| Response | ✅ requires `success: true`, stores `emailId` | ✅ stores `messageId` |
| Errors | ✅ 401, 422 field errors, 400 message | ✅ 401/400 messages |
| Timeout | ✅ failed + ambiguous, never auto-retried | ✅ same |
| Outage / 5xx / 429 | ✅ delayed retry, `Retry-After` honoured | ✅ `x-sib-ratelimit-reset` honoured |
| Success/failure recorded | ✅ per recipient | ✅ |
| Duplicate on retry | ✅ prevented (claim lock; ambiguous excluded from retry) | ✅ |
| Delivery tracking | ℹ️ accepted only (no documented per-message events) | ✅ webhooks |
| Live | ✅ user sent a real test email; Sender reported delivered | ℹ️ not tested live |

The CRM correctly surfaced Sender's real HTTP 400 "no DMARC policy" error
during setup — provider errors reach the user intact.

## 7. Transactional vs marketing

- ✅ Core system emails (verification, password reset, Internal Mail
  broadcasts) still use `MailerInterface`; Marketing cannot reach that
  path, and that path never uses the Marketing providers.
- ✅ Within Marketing, `message_class` separates behaviour: marketing →
  every opt-out honoured, unsubscribe link + `List-Unsubscribe`;
  transactional → only "unsubscribed" is ignored, no unsubscribe link.
- ⚠️ **F2** — anyone with `marketing.create` can label any campaign
  "transactional"; the only control is approval by `marketing.send`.
  Promotional content sent this way skips opt-outs.

## 8. Database & architecture

- ✅ Migrations: core `0080`; Marketing `0001`–`0006`; all applied locally;
  each has a working `down()`. No duplicate tables/columns; no existing
  table altered. Naming follows project conventions (`snake_case`,
  `organization_id`, `fk_*`, `idx_*`, `uniq_*`).
- ✅ Foreign keys: every table → `organizations` ON DELETE CASCADE;
  contacts/users → SET NULL (history kept when a contact is deleted);
  messages → campaigns CASCADE (only drafts can be deleted, and drafts
  have no messages).
- ✅ Indexes cover the queue (`status,next_attempt_at`;
  `campaign_id,status`; `lock_token`), provider-id lookup, contact
  history, analytics.
- ✅ Disconnecting a provider or disabling a module deletes nothing:
  queued messages wait (`last_error` shown), history stays.
- ℹ️ Production: migrations are applied there (Sender settings were saved
  on crmgroup.ir); `marketing` migrations should be confirmed in
  `module_migrations`.

## 9. Tests (actual results, 2026-10-15)

| Suite | Result |
|---|---|
| Full lite suite `php tests/lite/run.php` | **699 / 699 passed**, 0 failed, 0 skipped |
| Marketing only (`MarketingProvidersTest` 15 + `MarketingFlowTest` 28) | **43 / 43 passed** |
| Mutation checks (10 safety mechanisms broken one at a time) | **10 / 10 detected** |
| Permission tests | in `MarketingFlowTest`: no-role user forbidden, member cannot send or manage templates, member can view, organization.edit required for provider settings |
| `tests/Unit` (PHPUnit) | **not run** — PHPUnit is not installed (`vendor/bin/phpunit` missing); pre-existing, unrelated to Marketing. `tests/Feature` is empty. |
| Probe script (crm_local, rolled back) | confirmed F1, F3, F4 |
| Live provider calls | Sender: one real test email by the user (delivered). Kavenegar and Brevo: not yet called live. |

## 10. Findings

| # | Severity | Finding | Proposed fix |
|---|---|---|---|
| F1 | **High** | Users with `marketing.create` but without `contacts.view` see all contacts' names, phones and emails (picker, preview, recipient tables, history). | Require `contacts.view` for the picker/preview/recipient lists (or mask addresses when it is missing); keep sending working by id. |
| F2 | Medium | "Transactional" can be chosen for any campaign by any creator; it skips marketing opt-outs and the unsubscribe link. | New permission `marketing.send_transactional`; show a warning; transactional campaigns limited to selected contacts (no group/tag/all). |
| F3 | Medium | Unsubscribe links return 404 when the organization no longer has the Marketing module, so recipients cannot opt out. | Always accept a valid signed token (record the opt-out); keep the module check only for sending. |
| F5 | Medium | Review page walks the whole audience on every view; approval walks it twice in one web request. Very large lists may exceed shared-hosting time limits; a fatal timeout leaves the campaign in "preparing" (it can be cancelled). | COUNT-based review; snapshot recipients in the worker (status `preparing` processed by cron) instead of the request. |
| F4 | Low | Super Admin → `/marketing` returns 500 instead of 404. | Require an organization on Marketing routes (same as `/org/*` pages). |
| F6 | Low | Campaign "test send" has no rate limit (provider settings test email has one); each test SMS is billed in platform mode. | Same session limit as the settings page (5 per 10 minutes). |
| F7 | Low | Platform mode: if a worker crashes after Kavenegar accepted an SMS, the recovered retry is deduplicated by Kavenegar (no second SMS) but charged again to the organization's credit. | Skip the charge when the provider returns an existing `localid` record, or refund it. |
| F8 | Low | Audit events are written (campaign and organization timeline) but no page shows them; existing `/org/sms` changes are not audited. | "History" section on the campaign page; organization audit list in settings. |
| F9 | Low | Contact picker lists only the newest 2,000 contacts. | Server-side search endpoint for the picker. |
| F10 | Info | Not covered: Kavenegar platform mode in Marketing tests; live Kavenegar and Brevo calls; Brevo webhook against a real delivery; Sender delivery events (not offered by its API). | Add a platform-mode flow test with a fake billing; one supervised live test per provider. |

**Decisions needed**

- D1: Keep the Kavenegar card always interactive (recommended), or
  mute it too when `marketing` is not purchased?
- D2: Approve fixing F1–F7 (and which of F8–F10) as "Marketing Phase 3.1
  — hardening", before scheduled sending / segments?

## 11. Resolution — Marketing 3.1 (2026-10-15)

| # | Status | Fix | Regression test (MarketingHardeningTest) |
|---|---|---|---|
| F1 | Fixed | `CampaignService::canSeeContacts()` (= `contacts.view`). Without it: no contact picker, individual selection refused server-side, preview and test send use `[first_name]` placeholders, recipient lists / history / opt-outs masked in the controller (`ContactPrivacy`) | F1 ×3 |
| F2 | Fixed | New key `marketing.send_transactional` (org_admin, migration 0007); transactional only for individually selected contacts up to `messaging.marketing.transactional_max_recipients`; re-checked on save and on approval | F2 ×3 |
| F3 | Fixed | Unsubscribe needs only a valid signature + the message in the token's organization; no module check | F3 |
| F4 | Fixed | `Modules\Marketing\RequireOrganizationMiddleware` on every Marketing route → 404 | F4 |
| F5 | Fixed | Audience above `messaging.marketing.sync_audience_limit` (default 2,000): COUNT-based review, approval sets `preparing` + `prepare_cursor`, the worker (cron or page tick) expands in chunks and resumes from the cursor; small audiences unchanged | F5 ×3 |
| F6 | Fixed | `ActionRateLimiter` (table `rate_limit_hits`, migration 0081), limits in `config/messaging.php` (`test_sends`), shared by campaign test, `/org/sms` test and provider test email; per user and per organization | F6 ×2 |
| F7 | Fixed | `marketing_messages.billed_units` (migration 0008) stored right after the charge; a retried/recovered message is never charged again and a final failure refunds it; on crash recovery the worker asks Kavenegar (`statuslocalmessageid`) and records an accepted message without resending | F7 ×4 |
| D1 | Done | Kavenegar card stays interactive; marketing SMS still requires the module | D1 |
| F8 | Deferred (by decision) | — | — |
| F9 | Deferred to Segments/Audiences (by decision) | — | — |
| F10 | Partly | Platform-mode billing now covered by tests (F7); live provider tests out of scope | — |

Residual risk (F7): a crash in the instant between the credit charge and the `billed_units` write (two consecutive statements) could still charge twice; the SMS itself is still never sent twice (Kavenegar `localid`).
