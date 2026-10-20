# Changelog

All notable changes to this project are recorded here, per Phase 0 §65/§108.

## [Unreleased]

### Customer Portal V1 (read-only)
- New module **Customer Portal** (`customer_portal`): a CRM contact signs in at `/customer/login/{organization}` with a one-time code — to the registered email, else the mobile (SMS verify template), or via national ID → masked destination chosen by the customer — and sees, read-only, their profile, their surveys (and can answer them), invoices and proformas. No editing, no payments, no orders.
- Separate from staff sign-in: own session keys and middleware, no `users` row, no staff permissions. Every customer query is limited to the signed-in organization AND contact; other records are 404. Codes hashed, single-use, 5-minute validity, 5 tries, 60-second resend cooldown, hourly cap, rate limits per IP / identifier / organization; unknown and ambiguous identifiers get the same answer and never sign in.
- Organization settings show the portal address when the module is granted; Modules & Features lists the module.
- Small shared changes: `PublicLinkService::resolveById()` (same validity rules), the survey form can post to another address (the portal); anonymous survey links unchanged.
- Migration: module `customer_portal` `0001_create_customer_portal_otps_table`. 100 new translation keys × 5 languages. New suite `CustomerPortalTest` (20); 17 mutation checks. See `docs/customer-portal.md`.

### Help categories managed by the Super Admin
- **Help → Categories** (`/admin/help-categories`, Super Admin): create, edit and delete help categories — a name in each language (English required, the fallback), a key (used in `?category=…`), display order and "show in the filter even while it has no articles" (on for FAQ).
- The 20 categories that were fixed in code (FAQ, General, CRM platform and one per module) became ordinary editable categories with their current names in all five languages. Article categories are unchanged.
- Deleting a category keeps its articles (they only lose that category); changing a key follows through to the articles. The article form offers the current categories; unknown keys are dropped.
- Migration: core `0092_create_help_categories_tables` (new tables, the 20 categories, link from article categories; safe to repeat). 22 new translation keys × 5 languages. `HelpCategoriesTest` now 9; 7 mutation checks.

### Help center: categories, FAQ, pagination and a list view
- Help articles have **categories** — one article can be in several: Frequently asked questions, General, CRM platform, and one per module (Sales, WooCommerce, Marketing, Email marketing, Customer satisfaction, Finance…, named as in Modules & Features). The Super Admin ticks them in the article form.
- The help page has a **category filter** (with article counts; FAQ is always offered, even while empty, the other categories once they have articles), **pagination** (25 per page by default), and a **Cards / List** switch — cards stay the default; the list shows titles only. Search, category and view are kept together.
- The 48 existing guides are filed under their modules by the migration (the deal/contract guide under Sales, Contracts and Projects; shop-purchase segments under Marketing and WooCommerce…).
- Migration: core `0091_create_help_article_categories_table` (new table + categories of the existing guides; safe to repeat). 11 new translation keys × 5 languages. New suite `HelpCategoriesTest` (8); the help suites read the list with 100 per page. Removed a stray translation work file from `database/help-content`.

### Fix — tickets and module requests: Super Admin actions gave a 500; ticket list alignment; ticket attachments
- **500 after a Super Admin reply** (the reply was saved): the organization's timeline was written outside any organization context — a Super Admin has none — so the request failed after saving; reloading then hit the POST-only reply address (404). Timeline entries of tickets and of module activation requests (approve / reject had the same flaw) are now written inside the ticket's / request's organization. Tests now act as a real Super Admin (no organization context).
- **Ticket lists:** the number and date cells were left-to-right as a whole, so in Persian/Arabic they drifted away from their column headers; only the value is left-to-right now (tickets, module requests, ticket facts).
- **Attachments on tickets** (new ticket and every reply, both sides): txt, csv, jpg, png, webp; at most 5 MB each and 5 per message; the file's real content must match its extension (e.g. an HTML page renamed .txt is refused). Stored with the shared attachment service (random name, outside the web root); listed under each message; downloaded only through the ticket page's own checks (another organization or another ticket's address → 404). If a file cannot be stored after the message is saved, the message stays and the sender is told.
- No migrations (attachments use the existing `attachments` table). 7 new translation keys × 5 languages. `PlatformTicketTest` now 15; 9 mutation checks.

### Tickets to the platform team (organization admin → Super Admin)
- **Help › Send a ticket** (`/org/tickets`, `organization.edit`): organization admins send a ticket (subject, category, priority, message) and follow the conversation; they can reply and close. Only their own organization's tickets. A **New ticket** button on the Help page; the menu item sits right under Help and shows how many tickets the platform team has answered.
- **Super Admin → Tickets** (`/admin/tickets`): every organization's tickets, waiting ones first (urgent on top); search by subject, organization or number; filter by status; reply (optionally closing), close / reopen, change priority. The menu shows how many tickets are waiting.
- Statuses: waiting for the platform team → answered → closed; a reply from the organization makes it wait again, a reply to a closed ticket reopens it. Messages are never edited or deleted. The sender is notified of a reply or close under Platform messages (in their language); events are recorded on the organization's timeline. At most 10 new tickets per user per hour.
- Separate from the Support module's customer tickets. Migration: core `0090_create_platform_tickets_tables` (new tables only). 67 new translation keys × 5 languages. New suite `PlatformTicketTest` (12); 15 mutation checks.

### Help guides — WooCommerce, Customer satisfaction, campaigns and modules
- 13 new help-center guides, each in en, fa, ar, tr, fr: connecting a WooCommerce shop; how orders are synchronized (webhooks, 15-minute sync, Sync now, queue, cron); turning shop customers into contacts; importing older orders; shop orders on the contact page; segments by shop purchases; scheduling a campaign; the transactional messages report; getting started with Customer satisfaction; sending a test survey invitation; survey results and follow-ups; the Modules & Features page; requesting a module activation.
- "?" help buttons on the matching pages (Integrations, webhook settings, Contact sync, contact Shop orders panel, segment builder when shop conditions exist, campaign scheduling, Transactional, Customer satisfaction, Modules & Features, Modules, Super Admin Module requests).
- Migration: core `0089_seed_integration_cx_module_help_articles` (data only; existing or edited guides are never overwritten). New suite `IntegrationHelpTest` (7).

### Modules & Features catalog and activation requests
- **Organization settings → Modules → Modules & Features** (`/org/modules/catalog`, `organization.edit`): every registered, platform-active module (sample `demo` hidden) grouped by purpose, with its real status for the organization — **Active** (with an Open link), **Available**, **Request pending** or **Request rejected** (with the reason; a new request is allowed). Dependencies are shown ("Requires Wallet — Not active"); Email marketing and Bale messaging are listed under Marketing. No prices, payment links or credentials.
- WooCommerce card lists only capabilities that exist (shops, order import, contacts, orders on the contact page, purchase segments, post-order surveys) and links to the existing integration when active. The WooCommerce integration, its routes and its module gate are unchanged.
- **Request activation** never activates anything: the module is validated against the registry, one pending request per organization + module (database-enforced), the request is recorded on the organization's timeline.
- **Super Admin → Module requests** (`/admin/module-requests`): pending first, filter by status; **Approve** enables the module through the existing per-organization entitlement (same dependency / platform checks) in the same transaction — if it fails the request stays pending and the reason is shown; **Reject** with an optional reason. A second review of the same request changes nothing. Pending requests can also be approved/rejected on the organization's module screen. The requester is notified through the announcement bell (in their language); decisions are recorded on the organization's timeline.
- Migration: core `0088_create_module_activation_requests_table` (new table only). 106 new translation keys × 5 languages. New suite `ModuleCatalogTest` (18); 12 mutation checks.

### Fix — WooCommerce import stopped early on stores that cap orders per request
- A sync batch treated any page with fewer orders than requested (50) as "caught up". Many stores / security plugins cap `per_page` lower (e.g. 10 or 20), so every batch ended after one page, no continuation was queued and the import only advanced once per scheduled sync — e.g. ~170 of 1,900 orders. Now a batch is caught up only when the shop returns no orders, or a short page that is its last page by `X-WP-TotalPages`; otherwise continuation batches follow immediately.
- The contact sync page shows how far the import has got and explains that contacts are per customer (fewer than orders).
- No migrations. 2 new translation keys × 5 languages; `WooContactSyncTest` 27.

### Import older WooCommerce orders (contact sync)
- The first sync of a shop imports only orders of the last 30 days (`WOOCOMMERCE_INITIAL_DAYS`), so older customers never became contacts. New **"Import older orders"** on the shop's Contact sync page: from a chosen date or from the beginning. The first part is imported at once, cron continues in small batches; guest customers (email or phone only) are included as before.
- Safe to repeat: orders are stored once per shop; old orders never start a satisfaction survey (only orders completed in the last 7 days do). Shows the oldest imported order.
- No migrations. 10 new translation keys × 5 languages; `WooContactSyncTest` now 26 tests.

### Fix — "Sync contacts now" did nothing without cron
- **Cause:** the button only synced customers already imported from the shop; orders are imported by the background task (cron), and "Sync now" on the Integrations page only queues that import. Without a running cron there were no customers, so nothing happened. A sync waiting to retry after a failed attempt also blocked a second click. The button was also disabled (without explanation) until contact sync was switched on and saved.
- **Now:** "Sync contacts now" first fetches the shop's latest orders — it queues the usual order sync and runs this shop's queued jobs right away (time-limited, same jobs and claims as cron, a job waiting to retry is retried now) — then syncs the contacts, and reports both results and any shop error.
- The contact sync page shows imported customers / orders, the last successful import, the import queue and the last shop error; explains why the button is disabled; warns when no customer has been imported yet.
- Core: `IntegrationWorker::runForConnection()`, `IntegrationJobRepository::claimForConnection()` / `makeSyncDueNow()`. No migrations. 8 new translation keys × 5 languages; `WooContactSyncTest` now 25 tests.

### Phase E — Test survey invitation
- **Customer Satisfaction → Send a test invitation** (users with `cx.manage`): choose a survey (also one not switched on yet) and a ready channel; a real invitation with a single-use link goes to your own profile mobile or email through the normal invitation flow and Marketing delivery.
- Clearly a test: "[Test]" at the start of the message, "Test" in the invitations list, purpose "survey (test)" in Marketing → Transactional.
- No pollution: no order, no contact, never retried; answering it uses up the link and shows the thank-you page but stores no survey response (results and follow-ups unchanged).
- Clear errors when the profile has no mobile / email or no channel is ready; at most 10 tests per user per hour; another organization's survey is refused.
- Migration: Customer Satisfaction `0003_add_is_test_to_cx_invitations` (additive). 18 new translation keys × 5 languages. New suite `CxTestInvitationTest` (10); 6 mutation checks.

### Phase D — Scheduled Marketing campaigns
- **Send later:** the approval form takes an optional send date (date picker) and time in the organization's time zone; stored as UTC (`marketing_campaigns.scheduled_at`), at least one minute and at most one year ahead.
- The campaign is approved and queued as before (recipients fixed at approval; large audiences prepared in the background ahead of time); the worker — cron or the open campaign page — starts sending only once the time has come. Repeated runs cannot send twice (existing claims).
- Opt-outs added after approval, module access and the provider are checked again when sending starts; retryable failures go back to the queue as before; "Cancel" stops a scheduled campaign.
- "Scheduled" status with the local time on the campaign page, the campaign list and the dashboard.
- Migration: Marketing `0015_add_scheduled_at_to_marketing_campaigns` (additive). 11 new translation keys × 5 languages. New suite `ScheduledCampaignsTest` (12); 5 mutation checks.

### Phase C — WooCommerce purchase segments
- **New dynamic segment conditions** ("Shop purchases"): has purchased, number of purchases, total purchase amount, purchased product, has an order with status, last purchase, first purchase (last N days / more than N days ago / before / after).
- **Shop scope:** each condition (except product, which already belongs to one shop) can be limited to one shop or use all shops; contacts buying in several shops are evaluated per selected shop.
- **What counts as a purchase:** completed or processing orders; pending, on-hold, failed, cancelled, refunded and drafts never count (the order-status condition can still target them).
- Resolved by the existing segment engine (preview, approval snapshot, chunked background expansion) from the synchronized order tables — correlated, organization-pinned subqueries, no copies.
- **Not included:** product category (WooCommerce order data has no categories; the CRM doesn't store the shop catalog).
- Engine (backward compatible): `amount` value type, optional `ScopedSegmentCondition` with a "Where" select in the builder, scope shown on the segment page.
- No migrations. 16 new translation keys × 5 languages. New suite `WooSegmentsTest` (13); 6 mutation checks.

### Phase B — Delivery reports and a page for transactional messages
- **SMS delivery reports** for transactional messages (e.g. survey invitations): the Marketing worker's existing Kavenegar status polling now covers them too (same 47-hour window; "blocked" adds an SMS opt-out). Organizations that only sent transactional SMS are included.
- **Email delivery events:** Brevo webhook events update transactional emails the same way as campaign emails — replays ignored, final states never downgraded, bounce / complaint / unsubscribe add an opt-out; scoped to the webhook's organization.
- "Accepted by provider" is never shown as delivered; Sender and Bale (Safir) send no delivery reports and stay "Not reported".
- **Marketing → Transactional** page: source, recipient (masked without `contacts.view`), channel, status, delivery state, errors, times; filters and pagination; `marketing.view`.
- Migration: Marketing `0014_add_delivery_reports_to_transactional_messages` (additive). 12 new translation keys × 5 languages. New suite `TransactionalReportsTest` (9); 5 mutation checks.

### Phase A — WooCommerce customers become CRM contacts
- **Per-shop contact sync** (Organization settings → Integrations → shop → Contact sync): on/off, contact group (required first; can be created there, existing names reused), tags, source for new contacts (default: the shop's domain), field mapping, "Sync contacts now", last run summary, counts per status and a list of skipped / conflicting customers.
- **Safe matching:** by normalized email and Iranian mobile (main and additional numbers, common stored forms). Matched contacts get only their empty fields filled — nothing is overwritten or erased, their source is kept, the group is set only when empty, tags are added once. Email and mobile on different contacts, or several contacts per identifier → reported as a conflict, nothing changed. New contacts only with a first name and a valid email or Iranian mobile.
- **Generic WooCommerce phone** is never copied into both mobile and landline: automatic (by number kind), mobile only, landline, or not imported.
- **Province / country:** WooCommerce's Iranian state codes map to the CRM's province list; the country code becomes a name in the organization's language (PHP intl), otherwise the code.
- Sync runs right after each import (before the order event), in the cron task `woocommerce.contacts`, and on demand; idempotent. Phone-only guest checkouts are now stored as shop customers.
- **"Shop orders" panel** on the contact page, from the existing order rows.
- Core: optional `ProvidesConnectionLinks` (a provider can add buttons to its connection card); `ContactPhoneRepository::add()` / `ContactEmailRepository::add()`.
- Migrations: core `0087_add_contact_lookup_indexes` (indexes only); WooCommerce `0002_add_contact_sync`. 94 new translation keys in all five languages. New suite `WooContactSyncTest` (23); 7 mutation checks.

### Phase 2 — Marketing delivers transactional messages for other modules
- **Marketing implements Core's `TransactionalDeliveryInterface`** (`modules/Marketing/Services/TransactionalDelivery.php`, bound through `MarketingModule::services()`). Customer Satisfaction invitations, which waited as "waiting for a channel" in Phase 1, are now sent through Marketing's existing SMS, Email (Sender/Brevo) and Bale channels. No module calls a provider directly.
- **Channels offered** only while the organization holds `marketing` and the channel's module and the provider is ready; email uses the first connected provider with a sender identity.
- **Safety:** every opt-out on the channel's list blocks the message; idempotent per caller reference (a sent reference is never sent again); a message is claimed before the provider call; an interrupted send is recovered from the provider for SMS (Kavenegar localid) and is "unknown" — never resent — for email/Bale; SMS charges are never taken twice (`billed_units`).
- **History:** new table `marketing_transactional_messages` (no message text — it can carry a single-use link) and a timeline event on the contact.
- Small backward-compatible change: `SmsChannel` accepts an optional log label (`sms_context`); campaigns keep `marketing.campaign:{id}`.
- Migration: Marketing `0013_create_marketing_transactional_messages_table` (new table only). 8 new translation keys `marketing.transactional.error.*` in all five languages.
- New suite `TransactionalDeliveryTest` (15, fake SMS, nothing sent); `IntegrationsCxTest` updated for the bound deliverer. Not included: delivery reports for transactional messages, a Marketing UI list of them. See `docs/marketing-module.md` §5a.

### Bale messaging (Safir) — new module `bale_messaging`
- **New module `modules/MarketingBale`** (key `bale_messaging`, depends on `marketing`; Super Admin enables it per organization). Adds a **Bale** channel to Marketing, so the existing campaigns, audiences (one contact, selected contacts, group, tag, static/dynamic segment, all), review, approval, snapshot, queue, worker, opt-outs and reports are reused. No new permission keys.
- **Safir** (`https://safir.bale.ai/api/v3/send_message`, verified against docs.bale.ai/safir): one request per recipient, `api-access-key` header, integer `bot_id`, number as `98` + 10 digits, text only (≤ 4,000 characters, an application limit), stable `request_id` per message.
- **Organization settings → Messaging providers → Bale:** the organization's own key (encrypted with SecretBox, only the last 4 characters shown) and bot id; save, replace, pause/resume, remove. Safir has no connection test, so the status is "Configured — not verified" until Safir accepts a message. Muted, and refused on the server, without both modules.
- **Outcomes:** accepted (never shown as delivered — Safir has no delivery/read reports); 8/4 → failed; 17 → "unsupported recipient"; 3 / HTTP 429 → back to the queue with the same `request_id`, batch stopped, organization paused 60 s; 20 / 21 → batch stopped, nothing failed, organization paused 10 min; key refused (401/403) → sending blocked until a new key is saved; timeout after sending, 5xx, code 2, unreadable answers and interrupted workers → **unknown**, never retried automatically. An unknown message can be sent again only one by one, by a `marketing.send` user, after confirming the duplicate risk.
- **Safety:** consent confirmation and an approval rate limit (20/hour per user) for Bale campaigns; a separate Bale opt-out list (SMS opt-outs are not applied to Bale); non-Iranian numbers skipped as unsupported before sending; internal throttle 5 requests/second and 200 messages per campaign per worker run (configurable in `config/messaging.php`, not Safir limits).
- **Shared Marketing changes (backward compatible):** optional channel interfaces `PhoneTextChannel` and `RecoverableChannel`, optional `DispatchResult` fields (`stopBatch`, `pauseReason`, `skipReason`), "unknown" and "unsupported" counters on the campaign page, `ChannelRegistry` exposed as a service, "Send Bale message" on the contact page. Core: `MessagingSettingsCards` lets a module add a card to `/org/messaging`.
- **Future billing seam only:** `BillableMessageSink` (+ `NullBillableMessageSink`, the default) receives one idempotent event per final Safir outcome (`bale:{request_id}:{outcome}`). No Wallet, ledger, price or finance change.
- Migration: MarketingBale `0001_create_organization_bale_settings_table` (new table only). Notification's Bot-API `BaleGateway` is unchanged.
- New suites `BaleSafirProvidersTest` (8, no database) and `BaleMessagingFlowTest` (25, crm_local, Safir mocked); 19 mutation checks. No real message was sent. See `docs/bale-messaging-module.md`.
- **Help guide (Persian only):** «راهنمای پیام‌رسانی بله (سفیر)» — bot via @botfather, Bale business panel (business.bale.ai), «افزایش اعتبار», where to copy «توکن وب‌سرویس (API)» and «شناسهٔ بازو (Bot_id)» (ارسال پیام › وب‌سرویس), CRM settings, test send, sending, report statuses, common errors, opt-outs, FAQ. Seeded by MarketingBale migration `0002_seed_bale_help_article` (data only, idempotent); linked from the Bale card in Persian only; hidden in other languages.

### Time zone — business logic on the organization's clock (P1 backlog)
- **Follow-ups:** "Overdue" (list, page, filter, client-record tab) now switches at the organization's local deadline time, not 3.5 hours later. Automatic follow-ups from deal rules get their due time from the organization's local clock (they were 3.5 hours early); reminders are due by the follow-up organization's clock, including daylight-saving zones.
- **Client records:** next / last visit, "upcoming in 7 days" and the visit tab groups use the organization's local time; new sessions default to the local date and time.
- **Appointments:** free slots no longer include times that have already passed locally; the calendar's "today", its default day and the "Today" button use the organization's date.
- **Business dates:** contract expiry and "expiring" filters, installment overdue, default issue/expense/payment/signing/end dates, the finance report's default end date and gift-code expiry use the organization's date — correct between midnight and 03:30 Tehran time. Contract activation without a typed date stores the local time.
- No data changed, no new migration. New suite `TimezoneBusinessLogicTest` (11).

### Organization time zone + UTC storage
- **Each organization has a time zone** (Organization Settings → Timezone): a grouped list of IANA zones with their UTC offset, e.g. "(UTC+03:30) Asia/Tehran"; validated on the server (offsets such as `+03:30` are refused). New organizations start on `Asia/Tehran` (`ORGANIZATION_TIMEZONE`).
- **All system times are now stored in UTC.** Before, MySQL wrote `created_at`/`updated_at` in the server's zone (Tehran) while PHP wrote UTC. Migration `0083` converts the 151 MySQL-stamped columns once (plus two derived copies), then every connection uses UTC. Times typed by users (due dates, appointment times, dates) are not converted.
- **Times are shown in the organization's zone** on lists and detail pages (contacts, mail, tasks, wallet, marketing, files, support, announcements, settings logs…), and the dashboard's "overdue / due today" uses the organization's today.
- New central `OrganizationTimezone` service for later modules (now, today, conversions, day ranges incl. daylight saving).
- Migrations: core `0083_convert_timestamps_to_utc_storage`, `0084_normalize_organization_timezones` (existing organizations on `UTC`/empty/invalid → `Asia/Tehran`; explicit zones kept). Both have `down()` methods for development use only — the authorized production rollback is restoring the pre-deployment backup (runbook §7). Not yet deployed — see `docs/deploy-organization-timezone-utc.md` for the deployment runbook and `docs/organization-timezone-design.md` §4 for the remaining P2/P3 items (the P1 items are fixed, see the entry above).
- New suite `OrganizationTimezoneTest` (14). The UTC session check treats only a missing `storage_timezone_state` table as "not converted"; any other database error is rethrown instead of silently writing old-zone timestamps.

### Marketing Phase 2 — final audit fixes
- **Large dynamic segments are frozen at approval:** background preparation now evaluates "last N days" as of the approval time and ignores contacts created after approval (the snapshot stores `as_of` and `max_contact_id`; older snapshots still work).
- **Stored rules that no longer validate** (removed field, edited row) now quietly match nobody instead of failing with a database error during preview, approval or preparation; an invalid fixed-choice value can no longer turn "not opted out" into "everyone".
- **Privacy:** without `contacts.view`, values typed into name/email/phone rules show as `•••` on the segment page, and such a segment's edit form is not available (it can still be used and deleted).
- **Dashboard:** "queue stalled" and the 30-day statistics use the database clock (they were off by the PHP/MySQL time-zone difference).
- No migrations. New suite `MarketingSegmentsAuditTest` (9): every filter, date boundaries under several PHP time zones, tampered rules, snapshot freezing, privacy, dashboard windows.

### Marketing help guides (21 articles)
- **21 short guides in the Help center**, with business rules, an example and a practical tip:
  - email setup: getting started, signing up for Sender and creating a token, SPF/DKIM/DMARC in cPanel, connecting Sender (with common errors), Sender free-plan limits (as of October 2026, with a link to sender.net/pricing for the latest), Brevo, Promotions/Spam placement;
  - email marketing: basics, writing a good email, unsubscribe and the opt-out list, marketing vs transactional, reading campaign results;
  - Kavenegar SMS: signing up, platform vs own account, rules for promotional SMS in Iran, the first SMS campaign;
  - segments: introduction, the dynamic rule builder, static segments, segments in campaigns;
  - an overview of Marketing permissions and business rules.
- **Languages:** the Kavenegar guides are **Persian only** (Kavenegar serves Iran); all others are in all five languages.
- **Help center change:** a reader only sees articles that exist in their language or in English. Persian-only guides no longer appear as empty pages in other languages; the Super Admin list still shows everything.
- **"?" buttons** on Messaging providers, SMS settings (Persian only), the Marketing dashboard, campaign form and page, opt-outs and segment pages.
- **Core migration `0082_seed_marketing_help_articles`** (data only, idempotent: it never overwrites a guide the Super Admin has edited). New suite `MarketingHelpTest` (9).

### Marketing Phase 2 — Segments & Audiences
- **Segments** (Marketing → Segments): reusable audiences, either **static** (a hand-picked list of contacts; members are added through a paginated search and removed explicitly) or **dynamic** (rules recalculated every time the segment is previewed or used).
- **Rule builder:** contact fields, status, gender, location, creation date / last N days, birthday month, contact group, tag, company, interest and marketing opt-out. Conditions combine with AND/OR inside groups and AND/OR between groups. Every field and operator is checked against a server-side allowlist, and values are only ever bound parameters.
- **Campaigns** can target a saved segment. Before approval it is re-resolved; opt-outs, invalid addresses and duplicates are removed; an estimate is shown separately from the final count; a change between preview and approval is refused. At approval the segment's definition is frozen on the campaign, and large segments use the existing background preparation (3.1).
- **Privacy and permissions:** new `marketing.manage_segments` (org_admin by default). Without `contacts.view`, previews show counts only, there are no static segments, and rules on name, email or phone are not allowed.
- **Deleting a segment** is soft: campaigns that already used it keep working and keep their history.
- **Extension point** for future data providers such as WooCommerce (`SegmentConditionProvider` / `SegmentConditionRegistry`); nothing purchase-related is implemented.
- Migrations: Marketing `0009`–`0012` (additive). New suite `MarketingSegmentsTest` (21). Details: `docs/marketing-segments.md`.

### Marketing 3.1 — hardening (audit F1–F7)
- **Contact privacy (F1):** Marketing shows contact names, numbers and emails only to users who may view contacts. Without `contacts.view` there is no contact picker, individual selection is refused, previews and test sends use `[first_name]`-style placeholders, and recipient lists, history and the opt-out list are masked.
- **Transactional (F2):** new permission `marketing.send_transactional` (org_admin by default). Transactional campaigns can only target individually selected contacts, up to a configurable limit; this is re-checked at approval.
- **Unsubscribe (F3):** links from earlier campaigns keep working when the Marketing module is switched off; they stay signed and organization-scoped.
- **Super Admin (F4):** Marketing pages answer 404 instead of a server error.
- **Large audiences (F5):** above `MARKETING_SYNC_AUDIENCE_LIMIT` (default 2,000 contacts), recipients are prepared in the background by the worker, in resumable chunks; smaller campaigns work as before.
- **Test-send limit (F6):** one shared, database-backed limit for test SMS (`/org/sms`), provider test emails and campaign test sends: per user and per organization, set in `config/messaging.php` (overridable in `.env`).
- **SMS billing on crash (F7):** each message records what it paid for; a retried or recovered message is never charged twice, and a final failure is refunded. After a worker crash, Kavenegar is asked by `localid` before anything is resent.
- Migrations: core `0081_create_rate_limit_hits_table`; Marketing `0007_grant_marketing_send_transactional`, `0008_add_background_preparation_and_billing_marker` (additive). New suite `MarketingHardeningTest` (20).

### Marketing — Core, SMS campaigns, Email (Sender, Brevo)
- **Organization settings → Messaging providers** (`/org/messaging`): one page with the SMS — Kavenegar card (summary of the existing `/org/sms` settings, unchanged) and the Email — Sender and Email — Brevo cards (API key stored encrypted and never shown again, sender identity, connection test, test email, disconnect, Brevo webhook registration). Cards for a module the organization has not purchased are shown muted, and their actions are refused server-side.
- **Marketing module** (`marketing`; email channel in `marketing_email`): dashboard, campaigns, templates, message history, opt-out list, basic 30-day analytics, and a "Marketing messages" panel on the contact page.
- **Campaigns:** SMS or email; recipients are one or more contacts, a contact group, contacts with a tag, or all active contacts (explicit confirmation). The review page shows the recipient count, missing/invalid/duplicate/opted-out counts, a personalized preview and a test send. Approval (`marketing.send`) queues the campaign. Sending runs in the background (cron worker or the open campaign page), with per-recipient status, provider reference and delivery state. Cancel and "retry failed" are available.
- **Safety:** no duplicate sends (atomic claims, Kavenegar `localid`, ambiguous emails never retried automatically); retries with delays for outages and rate limits; organization-scoped queries; signed unsubscribe links with `List-Unsubscribe`; idempotent, bearer-authenticated Brevo webhooks; "accepted" is kept separate from "delivered".
- **SMS (backward compatible):** `SmsSenderInterface` is unchanged. Kavenegar gains `localid` sends and `sms/status.json` delivery reports through a new optional interface. Platform/own account modes and billing are untouched.
- Migrations: core `0080`; Marketing `0001`–`0006` (additive). New permission keys `marketing.view|create|send|manage_templates|manage_suppressions`. Cron: `modules/Marketing/bin/worker.php` every minute. `public/.htaccess` now passes the `Authorization` header to PHP (webhooks). Details: `docs/marketing-module.md`.
- New suites `MarketingProvidersTest` (15) and `MarketingFlowTest` (27). Scheduled sending is not included.

### Finance help guides (12 articles, 5 languages)
- **12 short guides in the Help center**, each with the system's business rules, a worked example and a general finance tip:
  - getting started; proformas and invoices; amounts, discounts and tax; final invoice rounding;
  - payments and balance; credit notes and cancelling; contract installments; expenses;
  - project finance; financial periods; the financial report; permissions and audit trail.
- **"?" buttons on the Finance pages** open the matching guide in a popup: settings, invoice and proforma lists and pages, line items, payments, credit notes, financial history, installments, expenses, project budget / costs / profit, periods and reports. The invoice and proforma lists now point to the Finance guide instead of the general deal/contract guide.
- **Core migration `0078_seed_finance_help_articles`** (data only) seeds them from `database/help-content/finance-*`. It is idempotent: a guide that already exists, for example one edited by the Super Admin, is never overwritten.
- New suite `FinanceHelpTest` (7). No Finance logic changed.

### Finance Stage 5.2 — audit findings L1–L5 closed (local; Production not yet migrated)
- **Duplicate submissions (L1, migration 0023):** expense, payment and credit-note forms carry a one-time key. A double click or retry gets the original record back, with no second record and no second audit event. A failed attempt does not block its corrected retry.
- **Period locking (L5):** issuing, cancelling, payments, credit notes and expenses re-check the period inside their transaction with a locking read, in one fixed lock order. Closing or reopening a period re-checks its status under a row lock. A lock conflict gets a "please retry" message, not a server error.
- **Amount precision (L2):**
  - money: IRR up to 3 decimals (never rounded to whole rials automatically); other currencies use their configured precision;
  - unit price: money decimals + 2; quantity and percentage discounts: 4;
  - anything more precise is refused with a clear message, never silently cut.
  - "1,000" is still accepted.
  - The invoice-level discount is validated (precision, not negative, not above the lines).
- **Arithmetic (L3):** bcmath at scale 18 everywhere. Line totals are stored exactly. Subtotal, tax and grand total are at the currency precision; totals of documents that were valid before are unchanged. The rounding mode stays half-up (the architecture document said half-even; the discrepancy is now documented).
- **Optional final invoice rounding (migration 0024):**
  - Finance → Settings has an organization default, OFF; the issuer can override it per invoice.
  - IRR rounds to a whole rial; other currencies to their precision.
  - The amount before rounding and the adjustment are kept on the invoice and shown on the page and in print / PDF. The grand total is the final payable, so payments, credit notes, outstanding, reports and profit count the adjustment once.
  - Invoices issued before the change are not touched.
- **Amounts display at their currency's precision:** fractions are no longer hidden by the old whole-number display. This applies to invoices, proformas, print, expenses, reports, project and contract panels, ClientRecords and the contact panel.
- **Audit history (L4):** recording a payment, issuing a credit note, and closing or reopening a period each write a timeline event in the same transaction.
  - The invoice page has a "Financial history" card: payments only with `payments.view`, credit notes only with `credit_notes.view`.
  - The periods page shows the close / reopen history.
  - The events never appear on other timelines.
  - Payments and credit notes still cannot be edited or deleted.
- New suite `FinanceFinalizationTest` (15). Suite total 620, 0 failed. All 9 concurrency probes pass with no leftovers. Mutations: 9 new + L1 (4) + L5 (4), all detected. The expense probe now also removes the period audit event it causes. One existing assertion changed by policy: the contact panel now shows a USD balance as `0.15` instead of `0`. See `docs/finance-stage-5-2-finalization.md`.

### Finance validation & reconciliation — remediation P1–P3
- **Financial report:** one row per currency; amounts in different currencies are never added together. It covers the invoices issued in the period with all their own credit notes and payments, and says so on the page. It no longer shows a negative net when a credit note falls in a later period.
- **Outstanding is now the same everywhere:** grand − credit notes − payments on the invoice page, the contact panel (now decimal-safe), the client record summary and invoices tab, and the ClientRecords API's new `outstanding` field. The existing API field `remaining` is unchanged.
- **Amounts typed into invoice / proforma lines, payments and credit notes** are validated on the server:
  - "1,000" is accepted;
  - malformed or negative values, discounts above 100 % and fixed discounts larger than the line amount get a clear error instead of a server error or a silently wrong total;
  - valid documents compute exactly as before.
- No migration, no schema or data change. New suite `FinanceRemediationTest` (11). Suite total 599, 0 failed; 15 mutations detected.

### Tests: Patch B credit-note visibility suite made deterministic (test-only)
- **Tests 2 / 4 / 6:** amounts are now matched exactly ("700", "-700", "700.00"), never inside an id or a longer number. The page shows "Invoice #10700", which made the bare `notContains('700')` fail at random. All assertions kept; re-introducing the credit-note leak in InvoiceController or DocumentPrintService still fails tests 2, 3, 4, 6, 7, 8, 12 and 14.
- **Test 7 (PDF):** streams are cut by their `/Length` instead of at "\nendstream". A compressed stream ending in "\r" used to lose its last byte and yield no text. `TJ` arrays and hex strings are read too.
- Two new deterministic tests (7a, 7b) pin both causes. No application code changed. Suite total 588.

### Expense project allocation + contract reference (Project Finance, Stage 5.1)
- **"Allocate to project" on the general expense form** (Finance → Expenses → New / Edit).
  - It reuses the existing `project_id` and its checks: `projects.edit`, not cancelled, record scope, both projects when moving.
  - Only an expense allocated to a project counts in that project's actual cost, budget vs actual, profit and margin.
  - Project → New Expense still fixes the project.
- **"Allocate to contract" is a reference only.** It never affects any financial figure, and no project is ever derived from it. A help note under the field says so.
  - Only contracts the user can view are offered.
  - Corrections copy the reference.
  - It can be changed only while the expense itself is editable.
- **Form order:** amount, currency, category, date, payee, project, contract, description.
- **Migration:** Finance 0022 (`finance_expenses.contract_id`, reference only). 10 new translation keys in all five languages.
- **Unchanged:** Stage 5 formulas and files, `ExpenseRepository`, revenue / invoice / payment / credit note / billing / report code, Projects, Contracts and Core.
- **Tests:**
  - new suite `ExpenseAllocationTest` (17). Suite total 586, 0 failed;
  - 18 mutations detected.

### Budget, project profit and margin (Project Finance, Stage 5)
- **New "Budget" panel on the project page (after Project revenue and Project costs, which are unchanged).**
  - An expense budget per category × currency, amount ≥ 0.
  - Budget vs Actual with Remaining, Variance (Actual − Budget) and Variance %, plus a total per currency. Amounts in different currencies are never added together.
  - "No budget defined" plus "Define budget" when allowed.
  - Actual comes from the Stage 4 expenses the user may view, and only with `finance.expenses.view`.
- **Budget form `/finance/projects/{id}/budget`:**
  - needs `finance.budgets.manage` and `projects.edit` on the project (record scope), and the project in draft / planned / active / on_hold; completed and cancelled projects are read-only;
  - each save replaces the whole budget in one transaction with the project's lines locked;
  - the project timeline gets a generic "Budget updated" with no amounts.
- **New "Profitability" panel (per currency, no FX, no combined total):**
  - Revenue (excl. tax) = issued invoices attributed to the project, (grand − tax) less credit notes in each invoice's own net / gross proportion;
  - Actual cost = Stage 4 net expenses;
  - Profit = Revenue − Actual cost;
  - Margin % only when revenue > 0;
  - a note explains why this revenue differs from "Revenue (incl. tax)".
- **Profit is shown only with all of** `finance.budgets.view`, `invoices.view` (organization scope), `credit_notes.view` and `finance.expenses.view` (organization scope). Otherwise it is hidden entirely; there is no partial profit.
- **Permissions:** new module keys `finance.budgets.view` / `finance.budgets.manage`, granted (organization scope) only to the system Organization admin role. Custom roles are unchanged. `Permission.php` is not changed.
- **Migrations:** Finance 0020 (`finance_budget_lines`) and 0021 (grants). 40 new translation keys in all five languages.
- **Unchanged:**
  - Stage 4 (expenses), ProjectRevenue, InvoiceService, PaymentService, CreditNoteService, ReportService;
  - installments, ContractBillingService, InvoiceController, `finance_document_links`;
  - Core, Projects, Contracts, `layout.php`.
- **Tests:**
  - new suites `ProjectBudgetTest` (15) and `ProjectProfitTest` (10). Suite total 569, 0 failed;
  - 38 mutations detected plus 2 equivalent;
  - the new probe `tests/concurrency/budget-probe.php`.

### Expenses (Project Finance, Stage 4)
- **New Finance → Expenses list, form and detail page.** An expense is an actual cost:
  - one positive amount, recoverable tax excluded, with no tax logic;
  - a fixed category, a date, a currency and an optional reference;
  - a payee contact **or** company;
  - at most one project.
- **Lifecycle:** `recorded` → `void` (with a reason of up to 450 characters). There is no delete.
  - Edit, void and receipts only while the expense's financial period is open.
  - After the period is closed, changes are made with **correction entries** (increase / decrease). They copy the original's currency, project and scope. An original's net can never go below zero, and corrections can't be chained.
- **Project link:**
  - **"New expense" in the new project "Project costs" panel.** It needs `finance.expenses.manage` plus `projects.edit` on the project, and the project must not be cancelled. Every check is re-run on submit.
  - The edit form can remove the project.
  - Later project status changes or deletion never change the expense.
- **Project costs panel:**
  - actual cost per currency (no conversion, no combined total), i.e. the net of each original counted once, void excluded;
  - only expenses the user may view are summed;
  - it has no effect on revenue, invoices, payments, credit notes, installments, Contract Billing or Project Revenue.
- **Permissions:** new module keys `finance.expenses.view` / `finance.expenses.manage` with record scope. They are granted (organization scope) only to the system Organization admin role; custom roles are unchanged.
- **Receipts:** go only through Finance's own routes. They need view permission plus record scope to download, and manage permission, a recorded expense and an open period to upload or delete. The Core record attachment routes are off for expenses.
- **Period-close races:** a locking read of the covering period row is used inside every expense transaction. Row locks are held on the expense and on the original of a correction. `FinancialPeriodService` is unchanged.
- **Timeline:** full details on the expense; generic text on the project (no amount, reason or receipt).
- **Migrations:** Finance 0018 (`finance_expenses`) and 0019 (grants). 89 new translation keys in all five languages, one sidebar item.
- **Unchanged:** InvoiceService, PaymentService, CreditNoteService, ReportService, the installment code, ContractBillingService, InvoiceController, ProjectRevenue code, `finance_document_links`, Core RecordSupport, Projects, Contracts and `Permission.php`.
- **Tests:**
  - new suite `ExpenseTest` with 21 tests. Suite total 544, 0 failed;
  - 18 mutations detected plus 2 equivalent;
  - the new probe `tests/concurrency/expense-probe.php` detects 3 lock mutations.

### New invoice from a project; attach-list order (Project Finance, Stage 3-C)
- **"New invoice" button in the project revenue panel.** It opens the normal invoice form, prefilled with the project's contact and a line named after the project.
- **Saving creates a draft invoice attributed to the project in one transaction.** It is revenue only once issued.
- **Access:** needs `invoices.create` and `projects.edit` on an open (not completed / cancelled) project the user can see. The project is re-checked on the server; hidden fields are never trusted.
- **Timeline:** the project-side entry shows no invoice details.
- **Attach list order:** the project's own contact first, then contacts of its company, then the rest. Eligibility unchanged.
- **Unchanged:**
  - `from_contract` / `from_installment` flows, invoice show / cancel (Patch A/B);
  - `ContractBillingService`, `InvoiceService` (no proforma attribution; D-B5 stays);
  - payments, credit notes, reports, installments, Contracts, Projects, Core and permissions.
- No migration. 2 new translation keys in all five languages.
- **Tests:**
  - new suite `ProjectInvoiceTest` with 13 tests. Suite total 523, 0 failed;
  - 15 mutations detected, atomicity by the new probe `tests/concurrency/project-invoice-atomic-probe.php`.

### Project revenue (Project Finance, Stage 3-B)
- **A "Project revenue" panel on the project page.** For each currency, with no conversion and no combined total, it shows:
  - Invoiced (incl. tax) and Paid;
  - with `credit_notes.view` also Credited, Revenue (incl. tax) = invoiced − credit notes, Receivable and Refund due.
- **Security gate:** without `credit_notes.view` the panel shows only Invoiced and Paid. Receivable is withheld too, so no credit amount can be inferred from this panel. Other pages (invoice page, print/PDF, contract Billing) are unchanged by decision; their known inference risk remains.
- **Only issued invoices attributed to the project count.** Payments, drafts, cancelled invoices, proformas, contract value, installments and the contract ↔ project association are never revenue. Only invoices the user may view are listed or summed.
- **Attribution** lives in the existing `finance_document_links` (`linked_type = 'project'`), whose unique key gives one project per invoice. No new table, no migration.
  - Attach needs `invoices.edit` on the invoice and `projects.edit` on the project. Draft or issued invoices only, never cancelled. No move to another project.
  - Detach only while the invoice is a draft.
  - Both lock the invoice row. Duplicate or concurrent attaches are idempotent or refused cleanly.
- **Timeline:** entries on the project (without invoice details) and on the invoice.
- **Unchanged:** InvoiceService, PaymentService, CreditNoteService, ReportService, the installment code, ContractBillingService, Contracts, Projects (incl. Stage 3-A), Core and permissions. 16 new translation keys in all five languages.
- **Tests:**
  - new suite `ProjectRevenueTest` with 20 tests. Suite total 510, 0 failed;
  - 25 mutations detected by the suite, and the lock / timeline race mutations by the new probe `tests/concurrency/project-revenue-probe.php`.

### Contract ↔ Project association hardened (Project Finance, Stage 3-A)
- **The existing Projects link (`project_links`, N:N) is kept**, with no new table or migration.
- **Permissions:** linking or unlinking a project and a contract, and creating a project from a contract, now needs `projects.edit` on the project **and** `contracts.edit` on the contract, with record scope.
- **Status rules for new links:** not with a terminated or cancelled contract, and not with a completed or cancelled project. Unlinking is allowed in every status. Deal links are unchanged.
- **Concurrency:** linking is idempotent even under concurrent requests (no raw duplicate-key error, no duplicate row or timeline entry). Unlink writes the timeline only when it actually removed the link.
- **Timeline:** link and unlink are recorded on the contract too (`project_linked` / `project_unlinked`).
- **UI:** the contract page's Projects panel gets an "Unlink" action per project. "New project" and "Link existing" are shown only when allowed.
- **No financial effect:** no Finance, Contracts, Core or permission-definition change. 2 new translation keys in all five languages.
- **Tests:**
  - new suite `ContractProjectLinkTest` with 21 tests;
  - one `ProjectLinksTest` fixture now uses a member-owned contract (approved rule), with all assertions kept;
  - suite total 490, 0 failed;
  - 18 mutations detected, by the suite or the new probe `tests/concurrency/project-link-probe.php`.

### Contract installment schedule (Project Finance, Stage 2)
- **A Finance-owned payment schedule on the contract page.** Each installment has a due date, an amount and a description. It is a plan, not an invoice: it never counts as revenue, receivable or payment, and the Billing summary is unchanged.
- **Status is derived from the one active linked document:** Not invoiced, Invoiced, Partially paid, Settled, Void, plus an Overdue flag.
- **Documents from an installment:** a proforma or invoice draft made from an installment carries a contract link and an installment link. Only one active document is allowed per installment, enforced under a row lock.
- **Access and state rules:**
  - managing the schedule needs `contracts.edit` on the contract;
  - new installments and edits only while the contract is active;
  - documents while it is active or expired;
  - nothing new once it is terminated;
  - void (reason required) whenever there is no active document.
- **Currency:** the contract's; otherwise the schedule's first. Never mixed.
- **Visibility:** a linked document the user may not open is shown only as "access is restricted".
- **Conversion fix (D-S2-8):** proforma → invoice conversion now runs in one transaction with the proforma row locked and re-checked, so a double conversion can't create two invoices. It also carries the installment link.
- **New:**
  - Finance migration `0017_create_finance_installments_table` (additive);
  - 48 translation keys in all five languages.
- **Unchanged:** no Contracts, Core or permission change. Payment and credit-note services, Patch A cancel rules, Patch B visibility and the period report are untouched.
- **Tests:**
  - new suite `ContractInstallmentsTest` with 24 tests. Suite total 469, 0 failed;
  - 22 mutation checks: 20 caught by the suite, and the 2 lock re-checks caught by the new concurrency probe `tests/concurrency/installment-lock-probe.php`, which also catches row-lock removal.

### Finance: credit-note visibility (Patch B)
- **Credit-note details now require `credit_notes.view`** on the invoice page, the print view and the PDF. This covers the list, number, date, reason, amount, Total credited, Effective total, and Payable on print/PDF.
- **Without the permission:**
  - the controller does not hand any of that data to the template at all;
  - print and PDF show the grand total in place of Payable.
- **Unchanged for everyone:** Outstanding / Remaining and the payment status still account for every credit note.
- **`credit_notes.view` and `credit_notes.create` stay independent:**
  - a user with create only still gets the issue form, without the amount cap and without the list;
  - a user with view only gets the list and no form.
- **Unchanged:** no Core, schema, migration or permission-definition change. The period report, `PaymentService`, `CreditNoteService` and `InvoiceService` are not touched. Proforma print is unchanged. No new translation keys.
- **Tests:** new suite `CreditNoteVisibilityTest` with 15 tests, including the real mPDF output. 8 of them fail on the unpatched code. 11 mutation checks, all caught. Suite total 445, 0 failed.

### Finance: invoice cancel hardening (Patch A)
- **An issued invoice can no longer be cancelled when:**
  - it has **any payment** (partial or full);
  - it has **any credit note**, even if its balance is 0;
  - its issue date is in a **closed financial period**.
  The user gets a translated reason, and on the invoice page the cancel button is disabled with that explanation.
- When allowed, cancelling an issued invoice now **requires a reason** (at most 450 characters). It is recorded in the timeline (subject `invoice`) with the user and the time.
- **Draft invoices** cancel exactly as before. They have no reason requirement and no period lock, though they are now also recorded in the timeline.
- **The rule checks are repeated on the invoice row locked `FOR UPDATE`**, the same lock payments and credit notes use, so a concurrent payment can't slip through.
- **The invoice page now shows:**
  - the cancellation history;
  - for a cancelled invoice that has payments (old data), a warning that the amount is not valid revenue.
  No existing data is changed and no refund is created.
- **Unchanged:** no migration, Core or permission change. `PaymentService` and `CreditNoteService` are not touched. Uses the existing `TimelineService` and `FinancialPeriodService::isDateLocked()`.
- 10 new translation keys in all five languages. The cancel flash messages are now translated.
- **Tests:** new suite `InvoiceCancelHardeningTest` with 17 tests. 8 of 9 mutation checks caught by the suite. The locked-row re-check needs true concurrency: the separate probe `tests/concurrency/invoice-cancel-lock-probe.php` (two connections, two processes, crm_local only) catches its removal. Suite total 430, 0 failed.

### Contract Billing summary: credit-note effect (Project Finance, Stage 1)
- **Per issued invoice, in its own currency**, the contract's Billing panel now computes:
  - Credited = its credit notes;
  - Net billed = Grand total − Credited;
  - Receivable = max(0, Grand total − Credited − Paid);
  - Refund due = max(0, Paid − (Grand total − Credited)).
- Totals are kept per currency and never mixed. Drafts are never counted.
- **Cancelled invoices** are out of every figure. A payment recorded on one is shown in a separate warning: "Payment recorded on a cancelled invoice … not valid revenue".
- **Access:**
  - Only invoices the user may view (permission and record scope) are summed.
  - The Credited and Net billed rows need `credit_notes.view`. Without it, Receivable and Refund due still account for credits, but no credit-note detail is shown.
  - Another organization's invoice or credit-note ids have no effect.
- **Unchanged:** no change to payments, credit notes, invoices, cancel, tax or financial periods; no Core, migration or permission change.
- 5 new translation keys; `finance.billing_hint` corrected in all five languages.
- **Tests:** new suite `ContractCreditEffectTest` with 17 tests. 10 mutation checks, all caught. Suite total 413, 0 failed.
- **Docs:**
  - `docs/project-finance-design.md` now holds the final design decisions;
  - new `docs/invoice-cancel-note.md` analyses cancelling paid invoices, plus the credit-note visibility on the invoice page; both are still to be decided.

### PDF font per language
- Generated PDFs (invoice/proforma) pick the font from the user's language:
  - **fa:** Vazirmatn, the UI font, for both Persian and Latin text. Static `Vazirmatn-Regular.ttf` / `Vazirmatn-Bold.ttf` (v33.003, OFL) were added to `themes/default/assets/fonts/vazirmatn/`, because mPDF can't read the variable woff2.
  - **ar:** XB Riyaz (Arabic Naskh).
  - **en / tr / fr:** DejaVu Sans. Any Arabic-script text in these documents uses XB Riyaz.
- `PdfRenderer::render()` takes a new optional `$locale` argument. `DocumentPrintController` passes the current locale.

### Hardening patch: Follow-ups, task and ticket attachments
- **H1 (security, cross-tenant name leak):** a follow-up's `assignee_user_id`, `team_id` and `department_id` must now belong to the user's organization. The assignee must be an active user; the one already saved on a follow-up may be kept. Otherwise 422.
  - Before, a tampered assignee from another organization showed that person's name in the follow-up list, through an unscoped subquery.
  - The assignee picker now lists only active users, plus the saved assignee.
  - **Automation (D1):** a rule whose fixed assignee isn't an active user of the event's organization assigns the follow-up to the actor instead.
- **H2:** a follow-up created with `type` / `priority` missing used to fail with a database error. It now gets `task` / `normal`. An edit with missing or invalid values keeps the stored ones.
- **H3 (security):** task and ticket attachment downloads now first check the user may view that task (`tasks.view` + OWN creator/assignee rule) or ticket (`tickets.view`): 403. They then check the file belongs to it: 404. Before, any user of the organization could download them by id.
- **H4:** ticket attachment upload now checks the ticket exists in the organization and the user may view it (`tickets.view`, D2) **before** anything is stored.
- **H5 + D3:** the 11 English flash messages and 7 English validation messages of the Follow-ups module are translated (`followups.flash.*`, `followups.error.*`). 21 new keys in all five languages, including H1's 3 error keys.
- **Unchanged:** Core, schema, URLs and permission definitions (`followups.assign` is still not enforced, out of scope); Contracts, Projects and Finance.
- **Tests:** new suite `HardeningPatchTest` with 20 tests.
  - Every security test fails on the unpatched code.
  - 10 mutation checks, all caught.
  - Suite total 396, 0 failed.

### File library: folders, thumbnails, grid view, server inbox
- **Folders** in Files (`/files?folder=N`): nested folders, breadcrumb path, create / rename / delete (empty folders only), "Move to folder" on each file, uploads go into the open folder.
- **Title:** files get a display title; empty = file name without extension. Shown above the original file name.
- **Thumbnails:** images show a GD-made JPEG preview (`/files/{id}/thumb`, cached under `storage/file-library/{org}/thumbs/`); other types show a type icon. Images and PDFs open in the browser (`/files/{id}/view`); downloads keep Persian file names (`filename*`).
- **Grid view:** List / Grid switch (`?view=grid`).
- **Server inbox:** files copied with their folders into `storage/file-library/{org}/inbox/` show a "N files waiting — Add them" notice for file managers. One click recreates the folder tree, moves the files into the library and titles them from their file names. The old system's `.tmb/` and top-level `index.html` are ignored; re-running only picks up what is left.
- **Search** (`?q=`; old `?tag=` links still work) matches title, file name, exact tag and folder names, across folders.
- **Data:** Knowledge module migrations `0005_create_file_library_folders_table`, `0006_add_folder_and_title_to_file_library_documents` (additive; existing files stay at the top level).
- **Unchanged:** permissions (`files.view`, `files.manage`, uploader allow-list), storage location and the 25 MB upload limit.
- 45 new translation keys in all five languages. **Tests:** new suite `FileLibraryTest` (10 tests).

### Contract ↔ Finance (billing from contracts)
- **New on the contract page:** a "Billing" panel, owned by Finance through `RelatedPanelRegistry`.
  - It lists the proformas and invoices linked to the contract and a per-currency summary: billed, paid, balance, and the contract value when the currency matches.
  - The summary counts **issued invoices only**. Credit notes are not included.
  - It has "New proforma" / "New invoice" buttons.
  - Each section needs its view permission (`quotes.view` / `invoices.view`). Every row goes through Finance's existing record scope.
- **Create from a contract:** `/finance/proformas/create?from_contract=N` and `/finance/invoices/create?from_contract=N`.
  - The form is prefilled from the contract: contact, currency, one line "title × value", terms.
  - On save, the contract is re-checked on the server (`RecordTypeRegistry::find()`: Contracts available, `contracts.view`, same organization, record scope).
  - The document is created by the unchanged `create()`, then linked, with a timeline entry on the contract, all in one transaction.
  - A contract that isn't viewable gives 404 on GET and 422 on POST; nothing is created.
- **Conversion and deletion:** converting a linked proforma links the new invoice to the same contract. Deleting a linked draft removes its link.
- **Data:** new Finance module migration `finance/0016_create_finance_document_links_table`.
  - Additive; existing financial tables are untouched.
  - No FK to Contracts.
  - `UNIQUE (organization_id, document_type, document_id, linked_type)`: one contract per document, many documents per contract.
- **Security (pre-existing, F1):** proformas and invoices accepted any `contact_id`. Finance lists showed that contact's name through an unscoped subquery, so another organization's contact name could appear. The contact must now belong to the user's organization and the currency must be active, checked server-side (`DocumentHeaderGuard`), otherwise 422.
- **Unchanged:**
  - tax, totals, numbering, payments, credit notes and financial periods;
  - Contracts, Projects, Sales and Core (Contracts is read only through the registry);
  - URLs (one query parameter added) and permissions.
- 12 new translation keys in all five languages.
- **Tests:** new suite `ContractFinanceTest` with 25 tests.
  - The F1 tests fail against the unpatched code.
  - Created totals are byte-identical with and without a contract.
  - 9 mutation checks, all caught.
  - Suite total 366, 0 failed.

### Follow-ups on contracts and projects
- A follow-up can now relate to a **contract** or a **project**:
  - A "Follow-ups" panel is added to the contract and project pages (owned by the Follow-ups module, through `RelatedPanelRegistry`).
  - Its "New follow-up" button uses the existing route `/followups/create?related_type=contract|project&related_id=N`.
  - The form shows the link read-only, the show page links back, and editing keeps the link.
  - Creating, completing, cancelling and rescheduling write on the contract's or project's timeline, as for contacts.
- **Access:** the record is read only through `RecordTypeRegistry::find()`. The user must be able to view that very contract or project: the module must be available, the user must hold the view permission, and the record must be in the same organization and within their record scope.
  - The check runs on the GET prefill (otherwise 404) and again on the POST (otherwise 422, nothing created).
  - Panel rows are filtered with the same rule as the follow-up page.
  - The "New follow-up" button appears only when the server would accept it.
- **Hardening:** `FollowUpService` accepts only `contact`, `lead`, `deal`, `contract` and `project` as `related_type`. A raw `related_type` posted to the create form is refused unless it is a valid contract or project link.
- **Unchanged:** no migration, permission or URL change; Contracts, Projects, Sales and Core are untouched. Followups never reads contract or project tables.
- **Translations:** 3 new keys in all five languages.
- **Tests:** new suite `FollowUpRelatedRecordsTest` with 23 tests covering tampering, cross-tenant, own scope, module off, records that are missing or not viewable, the allow-list, regressions and 5 languages. 8 mutation checks were run. Suite total 341, 0 failed.
- **Known, out of scope:**
  - `FollowUpService::validatedFields()` stores a null `type`/`priority` when the request omits them (the form always sends them).
  - G4–G6 in the design document.

### Security — notes, tags and attachments must belong to the record in the URL
- **Fixed:** in the same organization, one record's URL could delete another record's note or attachment: `/records/{type}/{id}/notes|attachments/{childId}/delete`.
  - The note route also deleted **before** checking the record type. An unknown type, a disabled module or a user without a role could still delete a note.
  - The Sales routes `/tasks/{id}/comments/{commentId}/delete` and `/tasks/{id}/attachments/{attachmentId}/delete` and the Support route `/support/tickets/{id}/attachments/{attachmentId}/delete` could delete **any** note or attachment in the organization.
- **Fixed (cross-tenant):**
  - removing a tag could delete another organization's tag link (`taggables` has no `organization_id`);
  - adding a tag could attach your tag to another organization's record, where its name then showed.
- **Fixed:** notes, tags and uploads on a missing record, or another organization's record, are refused instead of stored as orphans. This was pre-existing observation 7.3.
- **Rule now, before any write:**
  1. the record type is valid and viewable;
  2. the parent record exists in the user's organization;
  3. the note, attachment or tag belongs to that parent;
  4. otherwise 404.
  - An attachment's file is removed only after ownership is proven.
- **Unchanged:** URLs, permissions, schema and UI. Anyone who may view a record can still delete its notes (product rule kept).
- **Code:**
  - Additive Core methods: `NoteService::deleteFromSubject()`, `AttachmentService::findForSubject()` / `deleteFromSubject()`, `TagService::detachFromSubject()`. `TagService::detach()` is now a no-op for a tag outside the organization.
  - `RecordSupportController` checks the parent first.
  - `TaskService::deleteComment` / `deleteAttachment` and `TicketController::deleteAttachment` use the checked methods.
- **Tests:** new suite `RecordSupportOwnershipTest` with 15 tests (11 security, 3 regression, 1 rollback).
  - Against the old code, every security test fails.
  - Ten mutation checks are all caught.
  - Suite total 318, 0 failed.

### Projects module (Phase 5 Step 3)
- **New sellable module `projects`** (`modules/Projects`, type addon, no dependencies). It runs with Core alone and imports no other module. Design: `docs/projects-module-design.md`.
- **Projects:** name, `PRJ-0001` code (Core `number_series`), description, project manager, contact and/or company, start date, expected end date, actual end date.
- **Lifecycle:** `draft → planned → active → on_hold → completed / cancelled`, with reopen from completed to active.
  - Completing sets the actual end date.
  - Completed and cancelled projects are read-only.
  - Only draft, planned or cancelled projects can be deleted.
- **Members:** being a member lets a user see the project, nothing more. Adding or removing members needs `projects.manage_members`.
- **Permissions:** six keys, registered by the module (`projects.view/create/edit/delete/manage_members/manage_settings`). Default grants come from module migration `projects/0003`:
  - org_admin: all six, organization scope;
  - member: view and create at organization scope; edit and manage_members on their own projects.
- **Pages:** list (search, status, mine, paginated), show (status actions, members, linked records, Core notes/tags/files/timeline), create/edit form, and `/projects/settings`. Sidebar group "Projects" behind the module flag.
- **Panels** (via `RelatedPanelRegistry`) on contact, company, deal and contract pages. On deal and contract pages the panel has "New project" (prefilled from that record) and "Link existing project".
- **Deal and contract links:** stored in `project_links`, with no foreign key to other modules. Records are read only through `RecordTypeRegistry`.
- **Optional auto-create:** an organization setting, off by default. When a deal is won, Projects creates one draft project linked to it (at most one per deal). The listener subscribes to `DealStageChanged` by class-name string.
- **Unchanged:** Contracts, Sales and Core code.
- **Events:** `ProjectCreated`, `ProjectStatusChanged`.
- **Migrations:** module migrations `projects/0001`–`0005` (`projects`, `project_members`, grants, `project_links`, `project_settings`), each with `down()`.
- **Translations:** 76 new keys in all five languages.
- **Tests:** 100 new tests in 9 suites, including a standalone run with only Projects installed. Suite total 303, 0 failed, 0 skipped. `BackwardCompatTest`'s Step 1 assertion "no `project` type yet" now checks that the Projects module owns the type.

### Help center (user guide)
- **New sidebar group "Help" → "User guide"** (`/help`) for every signed-in user: a searchable list of guides and a page per guide (`/help/{slug}`).
- **Super Admin only:** create, edit and delete guides (`/admin/help/create`, `/admin/help/{id}/edit`, `/admin/help/{id}/delete`). Each guide has a stable slug, a display order, a published flag, and a title, summary and HTML body in each of the five languages. English is required and is the fallback for empty languages. Unpublished guides are visible only to the Super Admin. Org admins and members can only read.
- **Contextual help:** the new template helper `help_link('slug')` prints a "?" icon that opens the guide in a popup (`#help-dialog` in the layout, `/help/{slug}?fragment=1`), or as a normal page without JavaScript. It has been added to the Deals list and board, Contracts, Proformas and Invoices page titles.
- **HTML safety:** `App\Infrastructure\Security\HtmlSanitizer` (allow-list) cleans bodies on save and again on output. It removes scripts, frames, forms, event handlers, inline style and non-http(s)/mailto URLs, and adds `rel="noopener noreferrer"` to `target="_blank"` links.
- **Migration** `0077_create_help_articles_tables` (`help_articles`, `help_article_translations`; not tenant-scoped) seeds the first guide, "Deal, Contract, Sales and Project: what is the difference?", in all five languages from `database/help-content/deal-contract-sales-project/`.
- UI: `help-circle` icon; `.help-*` styles in `app.css` (prose, tables, `.help-callout`, `.help-example`, `.help-flow`). `app.js`: `[data-related-url]` can target another dialog with `data-related-dialog` and works on links.
- 35 new keys in all five languages.
- Tests: `tests/lite/HelpCenterTest.php` (13 tests: read access, Super-Admin-only management, CSRF, validation, fallback, unpublished visibility, sanitizer, helper). Suite total 203, 0 failed.

### Contracts module — step 2.10 (full test matrix)
- No application code changed in this step; tests only.
- New suites:
  - `StandaloneContractsTest`: Core + Contracts only, in a separate process on a temporary copy with every other module physically absent.
  - `ContractMatrixTest`: RBAC on all 21 routes × 3 roles, CSRF, module states, tenant isolation on every id route.
  - `ContractEndToEndTest`: the full deal-won → contract → renew/terminate → module off/on story.
  - `ContractTranslationsTest`: all keys in all five languages, and every page rendered in all five without a raw key.
- New shared test harness: `tests/lite/Support/LocalApp.php`.
- Suite total: 190 tests, 0 failed, 0 skipped. Real data is unchanged after a run (checked).
- Pre-existing Core observations, unchanged, listed in `docs/contracts-module-design.md`.

### Contracts module — step 2.9 (organization auto-create setting)
- **New settings page** `/contracts/settings` (`contracts.manage_settings`; "Settings" button on the contract list) with one option, **off by default**: "Create a draft contract automatically when a deal is won". Module migration `contracts/0005_create_contract_settings_table` (one row per organization; no row = off).
- **When the option is on and a deal is won,** the Contracts listener (subscribed by event name, no Sales import) creates a draft contract prefilled from the deal and linked to it. It acts only when Contracts is entitled to that organization.
  - At most one contract per deal: nothing is created when a contract is already linked, so there's no duplicate on a second "won".
  - Lost deals, ordinary stage moves and events without an acting user create nothing.
- **Owner** is the deal's owner (when they are an active user of the organization), otherwise the person who won it. The timeline says it was created automatically.
- 5 new keys in all five languages.
- Tests: `tests/lite/ContractAutoCreateTest.php`, 9 tests through the real Sales `DealService` and EventBus. Suite total 144.

### Contracts module — step 2.8 (manual "Create contract" from a deal)
- The deal page's "Contracts" panel has a "New contract" button for users with `contracts.create`. It opens the form prefilled from the deal: title, contact, company, amount, and currency (the deal's 3-letter code mapped to an active Core currency). A notice links back to the deal.
- On save, the contract is created and linked to the deal in one transaction. The deal is re-checked on the server through `RecordTypeRegistry::find()` (Sales available, `deals.view`, same organization, record scope). Hidden form fields are never trusted: an unavailable or tampered source returns an error and creates nothing.
- `?from_deal=` for a deal the user can't see returns 404. Creating a contract without a deal keeps working when Sales is off.
- Contracts still references no Sales code or URL: the deal page URL comes from Sales' own record type. The architecture test caught one hard-coded `/deals/` URL during development, and it was replaced.
- Tests: `tests/lite/ContractFromDealTest.php`, 9 tests through the real router. Suite total 135.

### Contracts module — step 2.7 (Deal → Contract via EventBus)
- **Sales:** registers its own `deal` record type (P3) with a tenant-scoped finder, `deals.view` and `recordSupport: false`. This is the only Sales change; Sales knows nothing about Contracts.
- **Core (backward compatible):** `RecordType` gains an optional `recordSupport` flag (default `true`). With `false`, the type is never accepted by the shared `/records/{type}/{id}` notes/tags/attachments routes, so `/records/deal/...` still returns 404 as before.
- **Contracts — links:** module migration `contracts/0004_create_contract_links_table`. It has no foreign key to any other module's table and stores a label snapshot. Contracts can be linked to records of other modules; the target is checked through `RecordTypeRegistry::find()` (module available, `deals.view`, same organization, record scope).
  - A link stays when Sales is later disabled and is then shown as plain text.
  - The contract page lists linked records, with unlink for users who may edit the contract.
- **Contracts — events:** `DealStageChangedListener` is subscribed by the class-name string, with no import. When a linked deal moves stage, is won or is lost, each linked contract's timeline gets an entry. This happens only when Contracts is active and entitled for that organization; an event of an unexpected shape is logged and ignored.
- **Deal page:** a "Contracts" panel (via `RelatedPanelRegistry`) lists the contracts linked to the deal.
- **Architecture test:** allows exactly one string, the event class name, once, in `ContractsModule.php`.
- Tests: `tests/lite/ContractDealLinkTest.php`, 15 tests, including real `DealService::changeStage()` → `EventBus` → Contracts listener, and a deal of another organization. Suite total 126.

### Contracts module — step 2.6 (related panels)
- Contact and company pages show a "Contracts" panel. It is contributed by the Contracts module through `RelatedPanelRegistry` (`Modules\Contracts\ContractPanels`); the contact and company pages themselves are unchanged.
  - The panel appears only when Contracts is available to the user and they hold `contracts.view`.
  - It lists the newest 5 contracts with that party, within the user's own/team/department scope, with number, status, period and amount.
  - "New contract" opens the form with the contact or company preselected (only with `contracts.create`).
  - "All N contracts" opens the list filtered by that party.
- Contract list: new `?contact=` and `?company=` filters, kept when other filters are applied.
- 2 new keys (`contracts.view_all`, `contracts.panel_empty`) in all five languages.
- Tests: `tests/lite/ContractPanelsTest.php`, 10 tests through the real router. Suite total 111.

### Contracts module — step 2.5 (pages)
- Contract pages under `/contracts`:
  - **list:** search, status (including computed "expired"), category, "ending within 30 days", "only mine"; paginated. Columns: title with number, party, status, period, amount, row actions.
  - **show:** facts, contact/company links, lifecycle buttons only when allowed (submit, return to draft, approve or activate with a signing date, cancel, terminate with optional reason, renew), terms, renewal history, related-panel slot, Core notes/tags/attachments/timeline.
  - **create/edit form:** Jalali/Gregorian datepickers.
  - **categories page:** `contracts.manage_settings`.
- Routes: every route is behind `AuthMiddleware`, `ModuleMiddleware('contracts')` (404 when the module isn't available to the user) and `RbacMiddleware`; CSRF on POST. The edit page is refused unless the user may save it, i.e. permission on this record and an editable status.
- Sidebar: new "Contracts" group, shown only when the module is available.
- Translations: 70 new keys in all five languages. Dates show in Jalali for fa; amounts use locale grouping.
- **Shared fix (all lists):** the CSS tooltip on disabled row actions stayed in the layout while hidden (`opacity: 0`) and widened its table, giving a horizontal scrollbar on lists such as contacts or leads for rows the user can't edit. Hidden tooltips are now `display: none`, and long tooltips wrap (`max-width: 18rem`).
- Tests: `tests/lite/ContractPagesTest.php`, 14 tests through the real router and middleware. They found one real bug, now fixed: the edit form could be opened for a contract the user can't save. Suite total 101.

### Contracts module — step 2.4 (service + repository)
- Core: generic per-organization document numbering. Core migration `0076_create_number_series_table` and `App\Infrastructure\Repositories\NumberSeriesRepository::next($type, $prefix)`: row-locked (`FOR UPDATE`), joins the caller's transaction, rows are created lazily. Finance's own series are untouched.
- Contracts: `ContractRepository` (search by text, status, effective status, owner, team, department, contact, company, category and expiring-within-days; explicit organization scoping because it joins tables), `ContractCategoryRepository`, and `Services\ContractService`:
  - **create/update:** title required; dates must be Gregorian ISO with end ≥ start; amount kept as an exact decimal and needs an active currency; contact, company and category must belong to the organization.
  - **lifecycle:** submit, return to draft, approve, activate (assigns `CTR-0001`… and the signing date atomically), cancel, terminate, renew (new linked draft; dates follow on).
  - **expiry:** computed on read; an active contract past its end date counts as expired.
  - **access:** record-level RBAC, and list scope (own/team/department) applied in SQL.
  - **errors:** returned as translation keys, `contracts.error.*` (20 keys in all five languages).
  - `allowedActions()` for the pages.
- Events: `Modules\Contracts\Events\ContractActivated` and `ContractTerminated`.
- `ContractsModule` now implements `ProvidesServices` (exposes `ContractService`).
- Tests: `tests/lite/ContractServiceTest.php`, 20 tests against `crm_local` inside a rolled-back transaction. Suite total 87.

### Contracts module — step 2.3 (permissions)
- Core (P1, backward compatible): `Permission::registerModuleKeys($module, $keys)` lets a module add keys under its own prefix. `all()`/`exists()` include them, Core keys can't be taken over, and invalid entries are skipped. The Core list itself is unchanged (moved into a private `coreKeys()`).
- Contracts: `Modules\Contracts\ContractPermission` defines 9 keys (`contracts.view`, `create`, `edit`, `delete`, `approve`, `activate`, `terminate`, `renew`, `manage_settings`), registered from the module's `register()`. They appear on `/org/roles` and can be saved on custom roles.
- Module migration `contracts/0003_grant_contracts_permissions`: org_admin gets all 9 keys at organization scope; member gets view and create at organization scope and edit on own contracts. Its `down()` removes them. Grants have no effect while the module isn't entitled to the organization.
- Tests: `tests/lite/ContractsPermissionsTest.php`, 15 tests. Two 2.2 tests were adjusted to the new defaults (member scope set explicitly; the grant count is compared to the pre-test value). Suite total 67.

### Contracts module — step 2.2 (skeleton + record type)
- New module `modules/Contracts` (key `contracts`, type addon, no dependencies). The Super Admin must sync it on `/admin/modules` and entitle an organization before anything is usable; until then it is invisible.
- Module migrations `contracts/0001_create_contract_categories_table` and `contracts/0002_create_contracts_table`. Foreign keys point only to Core tables (organizations, users, teams, departments, contacts, companies, currencies) and to the module's own tables.
- Registers the `contract` record type: view permission `contracts.view`, page `/contracts/{id}`, with a finder. Notes, tags and attachments on contracts go through the existing `/records/contract/{id}` routes.
- Core (extension of step 1, backward compatible): `RecordType` gains an optional `finder`; `RecordTypeRegistry::find($type, $id, $user)` returns another module's record only when it is viewable, in the user's organization and within the user's record scope; `ModuleGate::allows()` takes an optional record for own/team/department scope.
- Not yet: permissions and grants (2.3), service (2.4), pages and routes (2.5). `/contracts/{id}` does not exist yet.
- Tests: `tests/lite/ContractsRecordTypeTest.php`, 15 tests. Suite total 52.

### Module extension points (Phase 5 · Step 1)
- Core extension points so future sellable modules (Contracts, Projects) can plug in without Core naming them — see `docs/module-extension-points.md`. **ProvidesServices**: optional module interface; Application binds the returned services (first registration wins, a module can never replace a Core binding, invalid entries skipped and logged). **RecordTypeRegistry**: Core registers contact/company/lead; modules register their own types. **RelatedPanelRegistry** + `partials/related-panels.php`: modules add panels to other records' pages; the slot is on the contact, company, lead and deal pages (renders nothing until a module registers a panel). All access goes through the new `ModuleGate`, a thin wrapper over the existing `ModuleAccessResolver` + `AuthorizationEngine` — no second access system.
- Notes/tags/attachments (`/records/{type}/{id}`) now accept any registered type the user can view instead of a hard-coded list; contact/company/lead behave as before, and the routes now also require the type's view permission (`contacts.view` / `companies.view` / `leads.view`).
- Plain-PHP test runner `php tests/lite/run.php` (PHPUnit is not installed): 37 tests for the registries and backward compatibility (local `crm_local` only, inside a rolled-back transaction).
- No database schema changes. No Contracts/Projects code, tables or UI.
- Also in this release: a lead free-text description (migration `0075`), tags on the new-lead form, follow-ups linkable to a contact or a lead (two pickers), a "New follow-up" button per lead row and on lead/contact pages, and list tables no longer push row actions out of view.

### Companies
- **Companies**: categories (`/companies/categories`, org admin via new permission `companies.manage_categories`; category filter and column on the list). New fields: brand (the name the company is known by), category, CEO, national ID, economic code, registration number, description — shown on the company page as a facts row. The create form now also takes tags (pill input), files (attachments, up to 10 MB each) and linked contacts (picked from contacts the user may edit; a contact already in another company is moved). Editing now really clears an emptied field, while partial forms (custom fields on the company page) leave other fields untouched. Company flash messages translated. Migrations `0072`–`0074`.

### Internal mail forward
- Internal mail: **Forward**. Every message in a conversation has a "Forward" button (`/mail/forward/{id}`): the compose form opens with "Fwd:" subject, the original quoted under a "Forwarded message" header (from, date, subject) and the original's attachments as checkboxes (all ticked); new files can be added too. Sent as a new conversation; chosen attachments are copied, so deleting either message never affects the other. Only participants of the original can forward it. New forward icon. Keys in all five languages.
- Internal mail cleanup: every flash and validation message (sent, draft saved/deleted, attachment removed, moved to trash, restored, broadcast results, subject/body/recipient/attachment errors) is now translated in all five languages instead of fixed English; services return translation keys. Inline `style=""` removed from compose, conversation and trash pages, replaced by shared classes `.form-actions` (button row), `.card--narrow`, `.section-heading`.

### French language
- **French (Français) added — the system is now 5-language** (fa, ar, tr, en, fr). `lang/fr.php` with all 1609 keys (checked: no missing keys, every `:placeholder` kept). French in the language picker, profile and organization default-language lists; French number format (1 234 567,89 with no-break space), dd/mm/yyyy dates, amounts in words in French on invoice print (vingt et un, quatre-vingts, cent/cents, mille, millions…), datepicker "Aujourd’hui". Landing page (`public/index.html`): FR button, French for every text and module card, "5 languages". CLAUDE.md: new keys go into all five language files.

### Announcements (Super Admin → org admins)
- Super Admin → org admin announcements (one-way). Super Admin → Announcements (`/admin/announcements`): send a subject + message to all organization admins or to one; list of sent messages with "seen X / Y", a per-recipient read report (seen time, removed time) and delete-for-everyone. Org admins get a bell next to the language picker that turns red with an unread count; its panel lists the messages with "I've seen it" and "Delete" (removes it from their list; the report keeps the row), plus an "All messages" page (`/announcements`). Core migration `0071` (`admin_announcements`, `admin_announcement_recipients`).

### Contact history wording
- Client Records renamed to industry-neutral wording, structure unchanged: fa «سوابق مخاطب» (مراجع→مخاطب, جلسه→مراجعه, متخصص→کارشناس, پذیرش→اطلاعات اولیه, آلرژی→نکات احتیاطی, درمان→خدمت); en "Contact history" (client→contact, session→visit, specialist→expert, intake→initial information, allergies→precautions); ar and tr updated the same way. Custom-field examples now device model / serial number / website address. Module manifest name: "Contact History".

### Appointments → قرار ملاقات, internal meetings
- Appointments: the customer is now optional — leave it empty to book an **internal meeting** (shown as "Internal meeting"; same calendar, resources and conflict checks; no contact timeline entry). Module migration `appointments/0005` makes `appointments.contact_id` nullable.
- Persian wording: «نوبت‌دهی/نوبت» renamed to «قرار ملاقات/قرار» across the module, settings and the client record tab (fa only; en/ar/tr unchanged).

### Wallet transfer fee
- Wallet transfers: the transfer fee is now its own ledger line ("Transfer fee") instead of being added into the "Transfer sent" amount (e.g. 1,000,000 + 15,000 fee used to show as one -1,015,000 line). Balance is checked against amount + fee before anything is written. The transfer form shows the fee (percent + fixed), and the success message states the fee deducted.

### Gift credit (wallet)
- Super Admin → Gift codes (`/admin/wallet/gift-codes`): generate 1–100 codes (`GIFT-XXXX-XXXX-XXXX`, unambiguous characters) for an amount, optionally locked to one organization and/or with an expiry date, with a note; new codes are shown once for copying; list with status (unused / redeemed by whom, when / revoked) and revoke for unused codes.
- Org admin (`wallet.adjust`) on `/org/wallet`: "Redeem a gift code" — the amount is credited to their wallet as **gift credit** (code claimed atomically, works once; typed with or without dashes/case); "Send gift credit to a colleague" — paid from their wallet, received by the colleague as gift credit, no fee, reversed if the credit fails.
- Wallet migration `0007`: `wallet_gift_codes` table; ledger types `gift_credit` (اعتبار هدیه) and `gift_sent`; both appear in transaction lists and the type filter.
- The "Redeem a gift code" box is also on the personal wallet page (`/wallet`, sidebar "My wallet") for org admins, with a link to sending gift credit; after redeeming there, you return to `/wallet`.

### Bulk delete and row actions: products, proformas, invoices
- Same as contacts/companies/leads: checkbox column with "select all on this page", a "Delete selected" bar (`POST /finance/{products|proformas|invoices}/bulk-delete`) and Edit/Delete buttons on each row. Rules come from the existing permissions plus document state: org admin — every row; member — own proformas/invoices only (no product edit/delete); issued invoices and proformas converted to an invoice are locked for everyone (buttons disabled with the reason; bulk delete skips them). `partials/row-actions.php` now accepts a per-row `reason`.

### Manual proforma / invoice number
- Optional "Document number" field on the proforma and invoice forms. When filled it is shown instead of the system number on the document page, in the list and in print/PDF, and list search finds it; when empty the system number (invoice series / `PF-000123`) is used exactly as before. Finance migration `0015` (`custom_number` on `proformas` and `invoices`).

### Date picker
- Month and year are separate buttons in the header: the month opens the 12 months, the year opens a page of 12 years (prev/next pages by 12).
- The date can be typed or corrected by hand in the calendar shown (`1405/07/13`, `1405-7-13`, Persian/Arabic digits); invalid text reverts on blur, Enter applies.
- Mobile: the popup is never wider than the screen, opens above the field when there's no room below and follows scrolling. "Today" is localized.

### Proforma / invoice line items: product code, name, description
- The "free text row" dropdown is gone. Each row has product code, product name and description (شرح), then quantity, unit price and discount, in a tidy two-line card (`finance/_line-items.php`, shared by both forms). Code and name are search-as-you-type fields (`GET /finance/products/lookup?q=`, allowed with products.view, quotes.create or invoices.create; exact code first, max 15); picking a product fills code, name, description, price and product_id. Works with any number of products (no product dropdown). A row can still be written by hand.
- Finance migration `0014`: `item_code`, `item_name` snapshot columns on `proforma_items` and `invoice_items`; carried over when a proforma becomes an invoice. Older rows fall back to the product's current sku/name.
- Print/PDF: new "Code" column; the name is shown with the description under it when they differ.

### Number and price inputs
- Every amount-like number field (decimal step or a price/amount/cost/value/discount/rate/fee name) shows thousand separators while typing, accepts Persian/Arabic digits, allows at most 3 decimals and clears a "0" on focus (restored if left empty). Stored values like `10000.000000000000000000` display as `10,000`. The form still posts the plain number (hidden twin field); scripts reading/setting `input.value` keep working. Rows added later (invoice/proforma items) are handled too. Opt out with `data-no-format`. (`app.js`, presentation only.)

### SMS providers per country/language
- `SmsProviderRegistry` + `SmsProviderDefinition` (like payment gateways): each provider declares the languages/countries it serves, its credential fields and a factory. Kavenegar = `fa` / IR. Organizations whose language no provider serves don't see SMS settings and can't send; the platform account is only offered where its provider serves the organization's language.
- Kavenegar sender line is now required (Kavenegar returns 412 without it) and there is a separate optional international line: `KAVENEGAR_SENDER`, `KAVENEGAR_SENDER_INTERNATIONAL`; numbers are sent on the matching line. `config/sms.php` now has `platform.provider` + per-provider credentials (`SMS_PLATFORM_PROVIDER`).
- Organization own accounts store provider + all credential fields as one encrypted JSON object (migration `0070`); the SMS settings page renders the selected provider's fields from the registry. Rows saved under `0069` are still read.

### SMS: platform account or the organization's own Kavenegar key
- New page Organization settings → SMS (`/org/sms`, `organization.edit`): "our SMS service" (default) or "your own Kavenegar account" with API key + line. The key is verified with Kavenegar before saving and stored encrypted (`SecretBox`); migration `0069` (`organization_sms_settings`).
- Platform mode is billed per SMS part from the organization's prepaid notification credit (charged from Wallet, which is topped up through the payment gateway) at the Super Admin's SMS price; charged before sending, refunded when the provider rejects it (`SmsBillingInterface`, implemented by the Notification module's `NotificationSmsBilling`; `NotificationCreditService::refund()`). Own-key mode is never billed. Notification SMS are no longer deducted separately, so they are not charged twice.

### SMS via Kavenegar
- Core `SmsService` (`$container->make(SmsService::class)`) for sending SMS from anywhere: `send()` (free text, one or many numbers) and `sendTemplate()` (Kavenegar verify/lookup, for OTP codes). Numbers are normalized (+98 / 0098 / 98 / Persian digits). Driver `KavenegarSmsSender` (REST API checked against the official docs; `account/info` is GET-only), `NullSmsSender` when no key is set. Config: `KAVENEGAR_API_KEY`, `KAVENEGAR_SENDER` (`config/sms.php`).
- Every attempt is logged in `sms_logs` (migration `0068`); template tokens are never stored.
- Super Admin page `/admin/sms`: connection status, account credit, test send, log.
- The Notification module's SMS channel now sends through this service (`SmsServiceGateway`) instead of the old placeholder; `NotificationDispatchService` now takes the gateway interface. See `docs/sms.md`.

### Bulk delete and row actions: contacts, companies, leads
- Checkbox column with "select all on this page" and a "Delete selected" bar (`partials/bulk-bar.php`, `POST /{contacts|companies|leads}/bulk-delete`). Every id goes through the normal `delete()` and its record-level check; rows the user may not delete are skipped and counted in the flash message. Returns to the same filtered page.
- Edit / Delete buttons on every row of all three lists (`partials/row-actions.php`); a button for someone else's record is shown disabled with a tooltip. Org admin: all rows; Member: own rows only.
- Migration `0067`: the system Member role gets `companies.delete` and `leads.delete` at `own` scope (same as `contacts.delete` in `0066`).

### Vazirmatn font, self-hosted
- Vazirmatn 33.0.3 variable font (one woff2, weights 100–900, SIL OFL 1.1) in `themes/default/assets/fonts/vazirmatn/`, declared with `@font-face` in `app.css` — the existing `--font-rtl` / `--font-sans` tokens already named it but it was never loaded. Also used by the invoice/proforma print view and the landing page (`public/index.html`).
- The separate `mirsys.ir` site gets its own copy in `mirsys.ir/fonts/`.

### Public landing page
- The landing page (former `index-2.html`) is now `public/index.html`. Visitors opening the site address see it; logged-in users still get the dashboard at `/`. `public/.htaccess` sets `DirectoryIndex index.php` so `/` always goes through the app.
- Login and sign-up buttons in the landing header, hero, a closing call-to-action and the footer. They pass through `/lang/switch`, so the language chosen on the landing page carries over to the login/register screens.
- Root `.htaccess` no longer rewrites requests for the separate `mirsys.ir` site into the CRM; `mirsys.ir/.htaccess` also turns the rewrite engine on so it can't inherit the CRM's rules.

### Pagination and filters on every record list
- New `App\Infrastructure\Pagination\Pagination` + `partials/pagination.php` (summary "showing X–Y of Z", rows per page 25/50/100, page links that keep the active filters), placed above each list under its filter bar.
- Database-side paging (`LIMIT/OFFSET` + `COUNT`, only one page read): contacts, companies, leads, invoices, proformas. In-memory paging of the already-loaded list: deals, follow-ups, appointments, client records, products, tickets, knowledge-base articles, files, mail inbox/sent/drafts, wallet ledger and organization transactions (now up to 1000 instead of the latest 30/100), team members, organizations (super admin).
- New filters: contacts (also phone search, "only mine"), companies (phone, "only mine"), leads ("only mine", phone), deals (status), invoices & proformas (search by number/contact, status, issue-date range), mail (subject/sender, inbox "unread only"; trash search), wallet (ledger type), team members (name/email, role, status), organizations (name/slug). Every filter bar has "Clear filters".
- Mail trash keeps its two short tables without paging (search only).

### Contacts: CSV import/export, rollback, contact page
- CSV import/export stays org-admin only by default; an Organization Admin can now grant/revoke "CSV import" and "CSV export" to individual teammates on the team members page. New table `user_permission_grants` (migration `0064`); `AuthorizationEngine` falls back to it only for the allowlist `Permission::USER_GRANTABLE`, always at organization scope. The contacts list hides the import/export buttons for users without the permission.
- Fix: large imports silently imported only the first ~70 rows. The review form posted every row back (≈13 fields per row) and PHP's `max_input_vars` (1000) dropped the rest. The uploaded file is now kept server-side (`storage/cache/contact-imports`, removed after 24 h) and the form only sends the column mapping and the unchecked row numbers.
- Fix: an over-long value (e.g. address line 1 + 2) aborted the whole import with a database error; values are now cut to the column length and a failing row is reported and skipped.
- Fix: imported mobile/email were written only to `contacts.phone/email`, so the edit form (which reads `contact_phones`/`contact_emails`) showed them empty. Import now fills both; the edit form and contact page also fall back to the legacy columns for contacts imported earlier.
- Migration `0065` clears the literal text `NULL` that earlier imports stored in contact fields; the importer now treats `NULL` cells as empty.
- Column mapping: every file column is auto-matched (English, Persian and WooCommerce `billing_*` headers, existing custom-field labels) and can be changed per column with a dropdown — including "create a new custom field". Iranian province codes (THR, ESF, …) become province names. All mapped columns (address, city, province, postal code, country, …) are shown in the preview.
- Rows without a first name take the part of the email before `@` (or the mobile number).
- One category and/or company can be chosen (or created) for the whole import; the company only applies to rows whose own company column is empty.
- Rollback of the latest import (migration `0065`, `contact_import_batches` + `contact_import_batch_items`): deletes contacts it created, restores contacts it updated from a snapshot, removes companies/categories/custom fields it created when unused. Contacts edited or used (invoice, deal, task, note, …) since the import are left untouched and listed.
- Contact edit form: the three tabs now really switch (a CSS rule overrode `hidden`). Tab 1 = details up to country, tab 2 = additional phones/emails + social networks, tab 3 = description + interests.
- Contacts list: Edit and Delete buttons on every row, enabled per the same record-level check the service enforces (org admin: all contacts; Member: only contacts they own, otherwise shown disabled with a tooltip). Migration `0066` gives the system Member role `contacts.delete` at `own` scope (it already had `contacts.edit` = own). Fixed RTL display of phone/email in the list. Also fixed a missing `use` in `RbacMiddleware` that caused a 500 on every RBAC-protected page.
- Contact page: summary tiles for invoices, proformas, deals, tasks, follow-ups, appointments and tickets (shown per module access + view permission, OWN scope respected); each list opens in a dialog loaded on demand (`GET /contacts/{id}/related/{type}`), invoices with total/paid/balance. Details, custom fields and notes/activity are tabs.

### Appointments module (نوبت‌دهی)
- New optional module `appointments` (`modules/Appointments/`): staff book time for a contact on a bookable resource (person, place or equipment) with appointment types (default length + buffer after), weekly working hours (split shifts allowed), capacity, and status flow (requested → scheduled → confirmed → in progress → completed, plus cancelled with reason / no-show). Industry-neutral.
- Rules: resource capacity is a hard limit, checked under a row lock; outside working hours or a double-booked staff member are warnings with "save anyway". Every change goes to the contact's timeline.
- Views: day grid (time × resources, free slots link to a pre-filled booking), week overview (Saturday-first in fa/ar), filterable list, appointment page with status actions, settings for resources/hours/types.
- Ready for a future public booking page / customer portal without schema changes: `appointments.source`, status `requested`, `appointment_types.is_public`, and `AvailabilityService::freeSlots()`.
- 4 module migrations + core `0063_grant_appointments_permissions` (5 new `appointments.*` permissions). Small additive changes: sidebar item, `badge()` tones for `requested`/`confirmed`, an "Appointments calendar" CSS section, translations in en/fa/ar/tr. See `docs/appointments-module.md`.

### Invoice & proforma print / PDF
- New A4 print view (`/finance/invoices/{id}/print`, `/finance/proformas/{id}/print`) and real server-side PDF download (`…/pdf`), from one shared template so screen and PDF match. Seller from the organization profile (falls back to the Finance profile's legal name/tax ID), buyer from the contact. Persian: Jalali dates and Persian digits; Arabic: Arabic-Indic digits; amount in words in fa/en/ar/tr.
- PDF engine: mPDF 8.2.7 (+ deps) bundled in `vendor/` from the official GitHub releases, with a hand-written `vendor/autoload.php` (no Composer at runtime). Only XB Riyaz (fa/ar) and DejaVu Sans (Latin/Turkish) fonts are kept. Core wrapper: `App\Infrastructure\Pdf\PdfRenderer`; formatting: `App\Infrastructure\Localization\LocaleFormatter`.
- Migration `0062`: organization profile gets address, postal code, phone, national ID, economic code, registration number and a logo (PNG/JPG ≤ 1 MB, stored outside the web root, served to members via `/org/logo`); contacts get national ID, economic code, postal code.
- Tax label renamed to VAT everywhere (fa «مالیات بر ارزش افزوده», ar «ضريبة القيمة المضافة», tr «KDV»).
- Security fix: `POST /org/settings` had no permission check (any member could rename the organization); it now requires `organization.edit`.

### Client Records module (پرونده مراجع)
- New optional module `client_records` (`modules/ClientRecords/`): a structured client-history layer around the existing Contact — intake, service history, sessions, appointments (scheduled sessions), before/after media, documents, follow-ups, invoices/payments and a unified timeline. Industry-neutral (clinics, salons, physio, consulting); not an EMR.
- Reuses instead of duplicating: contacts (1:1, contact data never copied), users, RBAC (`AuthorizationEngine`), module entitlement (`ModuleMiddleware`), Core attachments/notes/custom fields/timeline, Finance products/invoices/payments (read-only) and the Follow-ups engine (follow-ups are created by `FollowUpService`).
- 4 module migrations (`client_records`, `client_record_services`, `client_record_sessions`, `client_record_files`) + core `0060_grant_client_records_permissions` (9 new `client_records.*` permissions).
- Web UI under `/client-records`, REST under `/api/v1/client-records` (same services, same RBAC, `_csrf` on writes).
- Small additive integration points in existing code: `InvoiceService::list()` accepts an optional `contact_id` filter; `FinanceModule` exposes `PaymentService` like it already exposed `InvoiceService`; the search page, contact page, custom-fields admin and sidebar show Client Records only when the module is available to the user; `badge()` knows `planned`/`no_show`; 5 icons added to the sprite; new CSS components (profile header, fact row, event list, course cards, media grid, `.form-grid`).
- Translations for en/fa/ar/tr. See `docs/client-records-module.md`.

### UI/UX redesign (presentation layer only)
- New design system in `themes/default/assets/css/app.css` (tokens, buttons, forms, tables, badges, alerts, tabs, dropdowns, dialog, empty/loading states). Every pre-existing class name still works.
- New app shell: grouped sidebar with icons (module → section), collapsible on desktop, off-canvas drawer on tablet/mobile; topbar with breadcrumb, global search, language switcher (native language names) and account menu.
- One icon set (inline SVG sprite) via a new `icon()` template helper; ~210 action buttons now show icon + label. Status values render as colored pills via `badge()`.
- Delete actions are styled as danger and ask for confirmation (`data-confirm`) before submitting — the form/request itself is unchanged.
- Dashboard redesigned (KPI cards, grouped tasks, follow-ups, quick actions) using only the data the controller already provided.
- Org settings is now a settings hub grid; 404/500 pages are styled standalone documents.
- RTL fixes: date picker anchors/arrow direction, mixed-script text, mirrored chevrons. Accessibility: skip link, landmarks, focus rings, labels on unlabeled selects/file inputs.
- No controller, service, repository, route, migration, RBAC or config changes. See `docs/ui-design-system.md`.

### Phase 11 — Localization (infrastructure complete, content partial)
- Real `Translator`/`Locale` system, replacing the Phase 1 `t()` stub: 4 language files (`en`/`fa`/`ar`/`tr`), English as the required fallback for any missing key in another locale, automatic RTL/LTR direction.
- **Honest scope note**: Phase 1 set up templates to call `t('key')` specifically so this phase would be a clean swap — that convention wasn't actually followed for any template built across Phases 5–10, all of which hardcode English text directly. This phase converted only the nav/header and the login/register pages; every other template still shows English regardless of locale, and will be converted module by module in further deliveries.
- Locale resolution: logged-in user's own `users.language` → guest's session-stored choice → platform config default. A language switcher in the nav persists the choice via a new `/lang/switch` endpoint (`return_to` validated as same-origin only, never an open redirect).
- `users.language`/`organizations.default_language` validation expanded from `[fa, en]` to all 4 locales.
- **Real gap found, documented rather than silently left**: a guest's session-stored language choice on the login page doesn't carry through to their new account's default language at registration — `AuthService::register()` still defaults from platform config, not the guest's in-session pick.
- Documented in `docs/phase11-localization.md`, including exactly which templates are and aren't converted yet.

### Date picker — clickable year/month quick-jump
- The date picker's month/year header label is now clickable, opening a 12-month grid for the current year with year-stepping prev/next buttons — reaching a date years away no longer requires dozens of single-month clicks. Requested alongside Phase 11.

### Phase 10 — Knowledge Base & File Library
- Added the Knowledge module (toggleable, `modules/Knowledge/`): `kb_categories`, `kb_articles`, `file_library_documents`, `file_library_uploaders`.
- Knowledge base: categories, articles with title/body/category, search by title/body.
- File library: any file type accepted, safely — reuses `AttachmentService`'s non-web-accessible storage architecture (random hex filenames, served only through a controlled download response) rather than an extension allow-list, so an uploaded `.php`/`.exe` is inert bytes on disk, never executed.
- Tags reuse Phase 5's existing `tags`/`taggables` tables rather than a new system — added the one genuinely missing piece to Core: a reverse lookup (`TagRepository::subjectIdsWithTag()`/`TagService::subjectIdsWithTagName()`), since the existing code could only answer "what tags does X have," never "what has this tag."
- New system-wide convention: a tag-pill input widget (`tag-input.js`) — `<input class="crm-tag-input">` anywhere auto-upgrades into a type-and-press-Enter pill widget, same auto-init pattern as the date picker.
- Per-user upload/delete access via a `file_library_uploaders` allow-list, managed by org_admin from `/files/uploaders` — org_admin can always manage files; a member additionally can if granted, per the explicit "access determined and managed by org admin, for some or all users" requirement.
- **Caught in review, not after shipping**: `FileLibraryUploaderRepository::forOrganization()`'s correlated subquery (for the user's display name) would have been mis-scoped by `tenantFindMany()`'s automatic first-`WHERE`-only logic — the subquery's own `WHERE` appears earlier in the raw SQL than the outer one. Fixed with explicit org-qualification before it ever ran.
- **Real Core gap found and fixed**: `Container` had no way to resolve the app's storage directory (only `Application` had `basePath`, privately). Added a small, reusable `app.storage_root` binding rather than a one-off workaround — any future module needing its own storage subdirectory can use the same one.
- Documented in `docs/phase10-knowledge.md`, including the deliberate trims (no article versioning, no per-file granular visibility, no previews).

### Bank accounts — layout bug, Super Admin crash, bank dropdown
Four issues reported together from real usage, all fixed:

- **Real bug, found in review**: the bank-account "add" form had
  `class="card--form"` on the `<form>` element itself, not on a
  wrapping `<section>`. The grid layout rule is `.card--form form`
  (a descendant selector) — a form that IS the `.card--form`, rather
  than being INSIDE one, never matched it, so the fields fell back to
  default inline flow and rendered jumbled (exactly the same failure
  mode as the Contact form bug fixed earlier this project, reintroduced
  here by a fresh mistake in new code). Fixed by wrapping the form in
  its own `<div class="card--form">`. A repo-wide scan confirmed this
  was the only place with the mistake.
- **Real bug, found from a live server log**: Super Admin has no
  organization, and both Bank accounts and Notification channels are
  stored in tenant-scoped tables — visiting `/profile` as Super Admin
  threw "No OrganizationContext is set" instead of loading. Both
  sections are now skipped entirely for Super Admin (they have no
  org-scoped wallet to withdraw from either), guarded in
  `AuthController` rather than in the tenant-scoped repositories
  themselves.
- **Bank name is now a dropdown**, not free text — a new platform-wide
  `banks` table (Core, like `currencies`), seeded with a solid starter
  list of major Iranian banks (not guaranteed exhaustive — bank
  licenses do occasionally change) that Super Admin can extend from
  `/admin/wallet/settings` (grouped there per an explicit request,
  reusing the existing platform wallet settings page rather than a new
  one). `BankAccountService` now resolves and validates the selected
  bank server-side rather than trusting whatever a tampered dropdown
  might submit.
- **Card number validation changed to 12 digits**, per an explicit
  instruction — flagged in code and here: real Iranian bank cards
  (Shetab network, ISO/IEC 7812 PAN format) are standard 16-digit
  numbers, so this may reject real card numbers if 12 wasn't
  intentional. Implemented as asked rather than silently overridden;
  worth confirming.

### Bank accounts for wallet withdrawals (Profile)
- Added `user_bank_accounts` (Core, not Wallet-owned — a bank account is
  profile/identity data, same reasoning as `mobile_phone`/`national_id`).
  A user can save more than one account, each with bank name, account
  number, Sheba number (stored as 24 digits, without the "IR" prefix,
  per the explicit requirement), card number, and branch code.
- New section on `/profile`: list + add/delete, with the required
  plain-text notice that an account must belong to the same national ID
  and mobile phone as the profile itself (stated to the user, not
  automatically verified — no bank-identity API is connected).
- Wallet's withdrawal request form no longer takes free-typed
  destination text — it's a dropdown of the user's own saved bank
  accounts (bank name + account number). `WalletController` looks up
  the selected account, verifies it actually belongs to the requesting
  user (never trusts the submitted ID alone), and formats it into the
  existing `wallet_withdrawal_requests.destination_info` text column —
  no schema change needed on the Wallet side.
- A module (Wallet) depending on this new Core service is the normal,
  expected direction (the same way Sales depends on CRM Core's
  Contacts) — unlike the Notification-channel fields on the same
  Profile page, this needed no lazy/optional lookup trick.
- **Caught before shipping**: `BankAccountService::requireOwnAccount()`
  throws `NotFoundException` for a missing/mismatched account, not
  `ValidationException` — the withdrawal controller's error handling
  only caught the latter at first, which would have surfaced a raw 404
  error page instead of a friendly flash message for that one case.

### Profile consolidation — personal settings move out of admin panels
- Per-user settings that were scattered across nav items and an
  admin-adjacent page now live on `/profile`, where they actually
  belong: mobile phone, national ID (new core `users` columns), and
  (when the Notification module is installed) Bale/Telegram chat IDs
  plus the "send test Bale message" button — all previously on a
  standalone `/notifications/settings` page.
- `users.mobile_phone` is now the single source of truth for SMS —
  `NotificationDispatchService` reads it directly instead of keeping
  a second, separate copy in `notification_recipients.phone_number`
  (that column stays in the schema, unused, rather than a risky
  `DROP COLUMN`).
- Added `notification_recipients.telegram_chat_id` — groundwork only,
  same pattern as the SMS phone number before a provider existed; no
  Telegram gateway is implemented yet.
- `/profile` (Core) reads the optional Bale/Telegram data via the same
  lazy, non-hard-dependency container lookup Internal Mail already
  uses to trigger Notification's dispatch — Core still never directly
  references a module's classes; it degrades to showing nothing if
  Notification isn't installed.
- Also consolidated: the main nav no longer lists Team/Roles/
  Departments/Modules/Custom fields/Org Wallet/Org notifications
  individually — they're reachable from one "Org settings" hub page
  now, grouped by area and only shown when the relevant module is
  actually enabled for the org.

### Notification module (Bale + SMS)
- Added the Notification module (toggleable, `modules/Notification/`, **depends on Wallet** — the first real use of the module dependency system): a Wallet-funded credit balance, delivery log, per-user Bale chat ID/phone storage, Super Admin pricing.
- Real `BaleGateway` verified against the official docs.bale.ai documentation (fetched directly, not assumed) — confirmed there is no documented way to message a Bale user by phone number alone, only by a `chat_id` obtained after they've started a chat with the bot (same restriction as Telegram bots). `NullSmsGateway` is an explicit, honest placeholder — no real SMS provider connected yet, per instruction.
- `NotificationCreditService`: "charging the module" is a real transfer — the org's Wallet is debited and this module's own balance is credited by the same amount, with rollback-on-failure.
- Added an opt-in "notify via SMS/Bale" checkbox to Internal Mail's compose and reply forms — sends a short, fixed announcement only (never the message body), per the explicit requirement.
- **A real cross-module wiring bug was caught and fixed before shipping**: `MailController` is built before `NotificationModule` registers (alphabetical module order), so eagerly resolving the dispatch service at construction time would always have failed. Fixed by resolving it lazily, at request-time, via the container — Internal Mail works identically whether Notification is installed or not.
- Documented in `docs/phase9b-notifications.md`, including the deliberate trims (no SMS provider yet, no way around Bale's chat_id-only messaging).

### Phase 9 — Internal Mail
- Added the Internal Mail module (toggleable, `modules/InternalMail/`): `messages` (self-referencing `thread_id` for threading), `message_recipients` (per-recipient read status), `contact_broadcasts` (a send log).
- Added `mail.view`/`mail.send` (everyone) and `mail.broadcast_team`/`mail.broadcast_customers` (org_admin only) permissions.
- `MessageService`: inbox, sent, compose (multi-recipient), reply (recipients inherited from the root message), thread access control that checks every message in a thread, not just the root.
- `BroadcastService`, per the explicit "send to all" requirement: broadcasting to the **team** reuses `MessageService::compose()` (everyone already has an inbox here); broadcasting to **customers** sends a real email via the existing `MailerInterface` (Phase 2), since Contacts have no login/inbox. No queue system exists yet, so a customer broadcast sends synchronously — documented plainly as a real scaling limitation, not glossed over.
- Documented in `docs/phase9-internal-mail.md`, including the deliberate trims (no thread-grouped inbox, no delivery/open tracking, no background sending yet).

### Phase 8 — Support
- Added the Support module (toggleable, `modules/Support/`): `ticket_categories`, `tickets`, `ticket_replies`.
- Added `tickets.*`/`ticket_categories.manage` permissions — member granted `organization` scope (not `own`), a deliberate departure from Deals/Tasks: support is a team effort, and restricting a member to only their own assigned tickets would block them from covering a colleague's queue.
- `TicketService` (CRUD, status changes, assignment), `TicketReplyService` (public replies and internal-only notes share one thread, distinguished by a boolean, not separate tables), `TicketCategoryService`.
- Kanban board (reusing Sales' existing `.kanban` CSS, not rebuilt), list view, detail page with a reply thread and file attachments (reusing Phase 5's `AttachmentService`, not rebuilt).
- Every list query needing a related name (contact/category/assignee) used an explicitly org-qualified correlated subquery from the start — the join/scoping bug class that bit Contacts for real and nearly repeated in Wallet was applied proactively this time, not caught after the fact.
- Documented in `docs/phase8-support.md`, including the deliberate trims (no customer portal, no SLA/routing, no email integration yet).

### System-wide date picker (Gregorian / Jalali / Hijri)
- New standing rule, documented in `docs/conventions.md`: every date field
  in the app uses `<input type="text" class="crm-datepicker">`, never the
  native `<input type="date">`. Auto-initialized on every page via a
  bootstrap script in `layout.php` — no per-page JS needed.
- `themes/default/assets/js/datepicker.js`: a dependency-free widget
  supporting Gregorian, Jalali (Shamsi), and Hijri (Qamari) calendars,
  switchable by the user (remembered in `localStorage`), defaulting to
  the organization's language (`fa` -> Jalali, else Gregorian). Storage
  is always Gregorian ISO underneath, via a hidden sibling input — the
  calendar system only changes what's displayed.
- Replaced every existing native date input (Contact's date of birth,
  Deal's expected close date, every Task due-date field) with the new
  pattern.
- **The conversion math was genuinely wrong twice before shipping**,
  caught by actually testing it rather than trusting a plausible-looking
  formula: a Gregorian<->day-count formula sourced from a real published
  article failed round-trip verification (thousands of dates converted
  to the wrong date and back) when numerically tested. Replaced entirely
  with JavaScript's own `Date` object (spec-guaranteed-correct proleptic
  Gregorian arithmetic) as the pivot, with the Hijri epoch constant
  recalibrated to match. Re-verified after the fix: zero round-trip
  failures across tens of thousands of random dates, known reference
  anchors (e.g. 2024-03-20 = 1 Farvardin 1403; 1992-10-15 = 17 Rabi
  al-Thani 1413 AH), and a full simulated calendar-grid render (every day
  of 100 sampled months in both calendars, checking for gaps or
  duplicate days) before being considered safe to ship.
- Hijri uses the standard tabular/civil ("Kuwaiti algorithm") Islamic
  calendar — a fixed arithmetic cycle, not real moon sightings, accurate
  to within about 1-2 days of the observation-based Umm al-Qura calendar
  used in Saudi Arabia. Documented plainly so it's never mistaken for
  moon-sighting-exact.

### Contacts — date of birth and interests
- Added `date_of_birth` (plain date field) to Contacts' default fields.
- Added Interests — a new `contact_interests` table (multi-value, its own table rather than reusing the general Tags feature, since it's a distinct concept scoped to the Details tab of the contact form). Entered as a comma-separated field, stored as discrete rows, and displayed as pill-styled tags on the contact detail page — matching the request that it look and behave like tags both ways.
- Both wired through the same `full_contact_form` sentinel already protecting phones/emails/social from the contact detail page's small "save custom fields" quick-form.

### Phase 7 — Wallet
- Added a minimal `currencies` reference table (IRR/USD/EUR) and `wallet_platform_settings` (Super Admin's platform-wide top-up cap) to core.
- Added the Wallet module (toggleable, `modules/Wallet/`): `wallets`, an append-only `wallet_ledger`, `wallet_transactions` (gateway top-up lifecycle), `wallet_withdrawal_requests`, `wallet_org_settings`.
- `WalletService` is the sole path to every balance/ledger write, row-locked and transaction-wrapped (`bcmath` throughout — money is never a float).
- Real ZarinPal v4 REST API integration (verified against current official docs, not memory), behind a `PaymentGatewayInterface`/`GatewayRegistry` so Mellat/Saman Kish can be added later as additional drivers.
- Idempotent top-up callback handling: a DB unique constraint plus an atomic "claim" step (new `processing` status, migration 0006) close both the simple double-submit case and a real concurrent-callback race that was caught and fixed before delivery.
- User-to-user transfers (with optional fixed+percent fee), withdrawal requests (debited on request, refunded on rejection), and org-admin manual credit/debit adjustments (always require a reason, always land on the ledger).
- Two-level top-up limits exactly as requested: Super Admin sets a platform-wide cap; each Org Admin sets their own organization's min/max, always clamped into the platform's range even if the platform cap changes later.
- `BaseRepository::transaction()` widened from `protected` to `public` (app-wide, backward-compatible) — a Service orchestrating a transaction across more than one repository needed this and would otherwise have hit a PHP fatal error.
- Two more near-misses of the Phase-5-established "correlated subquery vs. naive first-WHERE tenant scoping" bug were caught and fixed before shipping (`WalletLedgerRepository`, `WalletWithdrawalRepository`).
- Documented in `docs/phase7-wallet.md`, including the deliberate trims (single platform-wide currency, no Mellat/Saman Kish yet, no automated payout, no fee revenue account).

### Contacts — extended profile fields
- Added `gender`, `province`, `description` columns to `contacts`, plus three new multi-value child tables: `contact_phones` (mobile/landline, one marked primary), `contact_emails` (one marked primary), `contact_social_profiles` (Facebook/Instagram/YouTube/Twitter/LinkedIn/Telegram/WhatsApp, or a user-typed custom network).
- `contacts.phone`/`contacts.email` are kept as a synced "primary" cache — existing search, CSV import/export, and duplicate detection keep working unchanged; the multi-value tables are additive.
- Added `IranProvinces` (all 31 provinces, confirmed against current sources) — City is a free-text field with a `<datalist>` of well-known cities as suggestions, deliberately NOT a closed dropdown, since a reliable, complete database of Iran's 1000+ cities isn't something to guess at; extend `IranProvinces::PROVINCE_CITIES` freely as better data becomes available.
- Contact form reworked into three tabs: Details (incl. title/Mr./Ms., province, city, description), Phones & Emails (add any number of each, one marked default via radio buttons), Social Networks (add any number, built-in platforms or a custom-named one) — plain vanilla JS for the tabs and dynamic add/remove rows, no library.
- **Real bug caught before delivery**: the contact detail page's small "save custom fields" quick-form only ever submits `first_name` + `custom[]` — since phone/email are now managed as full-replace collections, that quick-save would have silently wiped every phone and email on the contact. Fixed with a "was this section actually part of the submitted form" sentinel (hidden `phones_submitted`/`emails_submitted`/`social_submitted` fields on the real edit form, absent from the quick-save form) — a request that doesn't mention a section no longer touches it.
- CSV import/export intentionally NOT extended to the new multi-value fields in this pass (still exports/imports only the primary phone/email) — a natural next step, not done now.

### Contacts — CSV import/export
Per Phase 0 §47 (explicitly deferred out of Phase 5's initial scope,
now built): full CSV import/export for Contacts, including round-tripping
custom field values and tags.

- Added `contacts.import` permission (granted to `org_admin` only, same
  tier as `contacts.export`) via a new migration.
- Added `ContactImportExportService`: CSV export (with a UTF-8 BOM for
  correct non-ASCII rendering in Excel); a stateless two-step import —
  `parsePreview()` reads the uploaded file once and validates every row
  (required fields, email format, duplicate detection by email for
  upsert instead of creating duplicates, company-name resolution) without
  writing anything; the browser renders an **editable** preview table;
  `commit()` receives that same (possibly hand-corrected) data back from
  the resubmitted form and performs the actual writes. No server-side
  session state or staging table — the round trip is carried entirely by
  the form itself, consistent with the rest of the app's stateless-per-request design.
- Added `ContactImportController` (`/contacts/import`, `/contacts/import/preview`,
  `/contacts/import/commit`, `/contacts/export`) and three new templates.
- Routing note: `/export` and `/import` had to be registered *before*
  the `/{id}` wildcard route in the `/contacts` group — otherwise the
  wildcard would have greedily matched them as if they were a numeric
  contact id, the same class of ordering concern already handled
  correctly for `/create`.
- XLSX (Excel) import/export intentionally deferred — building a
  dependency-free XLSX reader/writer (no Composer at runtime, per the
  hosting constraints) is real, untestable-here work; CSV opens fine in
  both Excel and Google Sheets in the meantime. Revisit once on a VPS
  where installing a proper library becomes practical.

### Architecture correction — module entitlement vs. per-user restriction
A genuine design mistake from Phase 4, caught by real usage rather than
by review: Org Admin was able to enable/disable a module for their own
organization via `/org/modules`. That's wrong for a commercial product
where a module (e.g. Sales) is something an organization is **sold** —
per Phase 0 §6's own resolution chain, "organization entitled" sits
between "globally enabled" and "user override," and entitlement should
never be a decision the customer's own admin can make unilaterally.

- **`ModuleService::setOrganizationEnabled()` removed**, replaced by
  `setOrganizationEntitlement()` — Super Admin only, takes an explicit
  `$organizationId` (temporarily pinning `OrganizationContext` to that
  org via a new `OrganizationContext::runAs()` helper, since a Super
  Admin has no organization of their own).
- Added `OrganizationModuleAdminController` and
  `/admin/organizations/{id}/modules` — the real commercial "grant/
  revoke" control, reachable from a new "Manage modules" link on
  `/admin/organizations`.
- **`OrgModulesController` reworked**: `/org/modules` is now read-only
  for entitlement. What Org Admin actually controls is new: per-user
  restriction on an already-entitled module (`/org/modules/{id}/access`),
  using the `user_module_overrides` table and repository that existed
  since Phase 4 but had no write path until now (`ModuleService::userAccessForModule()`/`setUserAccess()`).
- No new permission migration needed — `modules.manage` (already
  granted to `org_admin` since Phase 4) is repurposed in code to mean
  "manage per-user access within my org," not "manage my org's
  entitlement." Super Admin's entitlement actions bypass RBAC entirely
  (a direct `is_super_admin` check, same pattern as `/admin/modules`).

### Phase 6 — Sales (Tasks enhancement)
- Tasks now support multiple assignees (new `task_assignees` pivot table, module-owned) instead of a single assignee — `tasks.assigned_to_user_id` is kept as a denormalized "primary assignee" for simple queries, but authorization's OWN scope now checks the full assignee list (plus the task's creator).
- Tasks now have a dedicated detail page (`/tasks/{id}`) with an editable description, comments, and attachments — comments and attachments deliberately reuse Phase 5's shared `NoteService`/`AttachmentService` (subject type `'task'`) rather than inventing new concepts or tables.
- Both "add task" forms (My Tasks page, Deal detail page) updated to a multi-select assignee picker.

### Phase 6 — Sales
- Added the first real toggleable module (`modules/Sales/`): `pipelines`, `pipeline_stages`, `deals`, `tasks` tables (module-owned, tracked in `module_migrations` separately from core).
- Added `deals.*`, `pipelines.manage`, `tasks.*` permissions granted to `org_admin`/`member` via a new core migration.
- Added `PipelineService` (with auto-created default pipeline), `DealService` (CRUD + stage changes with won/lost handling), `TaskService`, and their controllers, wired through the exact `ModuleInterface::register()`/`routes()` mechanism Phase 4 built — the first real proof that mechanism works end to end, not just for the Demo module.
- Added Kanban board (simple "move to stage" dropdown, not drag-and-drop), deal list/form/detail, pipeline/stage settings, and a "My Tasks" page (the agreed calendar trim for this phase).
- Nav links for Deals/Tasks only appear when Sales is actually enabled for the viewer, via `ModuleAccessResolver` (a convenience check — `ModuleMiddleware` is what actually enforces it).
- Proactively avoided, rather than re-discovered by a crash, the three bug classes Phase 5 surfaced: correlated-subquery vs. `tenantFindMany`'s WHERE-injection, duplicate named PDO placeholders, and closure-variable-scoping in route groups — all swept clean before delivery.
- Documented in `docs/phase6-sales.md`, including the three MVP trims agreed at kickoff (simple Kanban, placeholder currency column, tasks list instead of a calendar) and the still-open gap around wiring Tasks into Core entity pages (needs a proper extension-point mechanism, not built yet).

### Phase 5 — CRM Core
- Added `tags`, `taggables`, `notes`, `custom_field_definitions`, `custom_field_values`, `timeline_events`, `attachments`, `companies`, `contacts`, `leads` tables; granted the new permissions to `org_admin`/`member` via a new migration.
- Added shared, reusable infrastructure (usable by any future module): `TimelineService`, `TagService`, `NoteService`, `CustomFieldService`, `AttachmentService` (secure upload — real MIME check, extension allow-list, randomized filenames, 10MB cap).
- Added `CompanyService`, `ContactService`, `LeadService` (with lead→contact/company conversion), `SearchService` (SQL LIKE-based, permission-filtered).
- Extended `Request` with `file()` for uploaded-file access.
- Added `ContactController`, `CompanyController`, `LeadController`, a shared `RecordSupportController` (notes/tags/attachments across all three entity types), `CustomFieldAdminController`, `SearchController`, and the full web UI for all of it.
- Fixed a real bug caught during development: a contacts↔companies join would have hit the same "ambiguous organization_id" trap as any join across two tenant-scoped tables — fixed with a correlated subquery instead, and flagged for future joins.
- Documented in `docs/phase5-crm-core.md`.

### Phase 4 — Module Manager
- Added `modules`, `organization_modules`, `user_module_overrides` tables; added `modules.manage` permission to the `org_admin` system role via a new migration (not an edit to the already-applied seed migration).
- Added `ModuleAccessResolver` (the Phase 0 §6 chain: installed → globally enabled → org entitled → user override), shared by `ModuleMiddleware` and `ModuleService` so the logic exists in exactly one place.
- Added `ModuleService`: sync-from-disk, Super Admin global enable/disable, Org Admin per-organization enable/disable — both with dependency validation (§8).
- `ModuleMiddleware` now enforces the full chain (Phase 1 only checked "installed").
- Modules can now register their own routes/services via a `bootstrap` class named in `module.json`, wired up in `Application::bootstrapModuleRoutes()`.
- Added Super Admin (`/admin/modules`) and Org Admin (`/org/modules`) UI.
- Added a working example module (`modules/Demo/`) — not a real feature, built to prove the whole pipeline (discovery → DB sync → global/org toggle → dependency checks → per-module migration → module-owned tenant-scoped data) on the live deployment before any real CRM module exists.
- `/system/migrate` now also discovers and runs every installed module's own migrations, tracked separately from core (`module_migrations`).
- Documented in `docs/phase4-module-manager.md`.

### Phase 3 — Organizations / RBAC
- Added `organizations`, `departments`, `teams`, `roles`, `role_permissions` tables; extended `users` with department/team/role/is_super_admin and the organization FK deferred from Phase 2.
- Seeded system role templates (`org_admin`, `member`); promoted pre-Phase-3 accounts to Super Admin (one-time backfill).
- Added `Scope` enum, `Permission` catalogue, and the single reusable `AuthorizationEngine`.
- Added `OrganizationService`, `UserManagementService`, `RoleService`, `OrgStructureService`, each permission-checked.
- `AuthMiddleware`/`RbacMiddleware` are now fully real; `TenantMiddleware` retired to a documented no-op now that `OrganizationContext` is resolved globally per request in `Application.php` (same fix pattern as Phase 2's `CurrentUser` global-resolution fix).
- `AuthService::register()` now creates a new organization per signup (self-service = new tenant); a separate `UserManagementService::create()` flow invites teammates into an existing org.
- Added org admin web UI: `/org/settings`, `/org/users`, `/org/roles`, `/org/structure`.
- Fixed a real bug caught during development: `OrganizationRepository::update()` originally had a signature incompatible with its parent class (fatal PHP error) — renamed to `updateOrganization()`.
- Cleaned up leftover `use PDO;` imports in three Phase 2 migration files for consistency with the Phase 2 `ErrorHandler`/import fix.
- Documented in `docs/phase3-organizations-rbac.md`, including this phase's decisions (org-per-signup, Super Admin backfill, single role per user, `is_super_admin` as a separate flag, ALL/ORGANIZATION scope equivalence, global tenant-context resolution, defense-in-depth RBAC checks).
- Post-delivery fixes from real-device testing: made the admin pages/header responsive (wrapping nav, scrollable tables, a mobile breakpoint), fixed a CSS cascade bug (`.btn` was accidentally redefined twice), and added a minimal read-only Super Admin panel (`/admin/organizations`) so a Super Admin account isn't a dead end.

### Phase 2 — Authentication
- Added `users`, `auth_tokens`, `login_attempts` core migrations.
- Added `AuthService`: registration, login, logout, email verification, password reset, profile updates.
- Implemented `AuthMiddleware` for real (was a Phase 1 placeholder); added `CurrentUser` per-request context mirroring `OrganizationContext`.
- Added brute-force login throttling (`LoginThrottle`), a minimal `Validator`, and a `MailerInterface` (log-file driver by default, `php_mail` driver for real delivery on shared hosting).
- Added web auth pages (login/register/forgot-reset password/email verification/profile) and `/api/v1/auth/*` endpoints.
- Added a token-gated `/system/migrate` route as a shared-hosting-without-SSH stopgap for running migrations.
- Added `config/auth.php`, `config/mail.php`; extended `.env.example`.
- Documented in `docs/phase2-authentication.md`, including the decisions made this phase (nullable `organization_id` now, platform-wide-unique email, session-based API auth for now).

### Phase 1 — Foundation
- Added application bootstrap/kernel (`App\Core\Application`).
- Added zero-dependency autoloader, config loader, and `.env` support.
- Added PDO connection wrapper and migration runner (`Migrator`).
- Added router (static/param/wildcard routes, groups, middleware), request/response objects.
- Added middleware pipeline with `AuthMiddleware`/`TenantMiddleware`/`RbacMiddleware` placeholders, and functional `ModuleMiddleware`/`CsrfMiddleware`.
- Added `BaseController`, `BaseApiController`, `BaseService`.
- Added `BaseRepository`, `TenantScopedRepository`, `OrganizationContext`, `SuperAdminContext` (tenant isolation mechanism).
- Added file logger with defensive secret redaction, and a safe global error handler.
- Added session/CSRF/password-hashing security foundation.
- Added theme loader + template engine, default baseline theme (RTL/LTR aware).
- Added Module Manager foundation: `module.json` manifest parsing and filesystem discovery (`ModuleRegistry`). No modules exist yet.
- Added PHPUnit test suite (dev-only) covering the router, container, tenant context, and module manifest parsing.
- Added Phase 1 documentation (`docs/phase1-foundation.md`) and deployment notes for shared cPanel hosting.

### Phase 0 — Architecture
- Rev. 1: initial architecture proposal (module map, RBAC/scope model, multi-tenancy, wallet ledger, folder structure, migration strategy, roadmap).
- Rev. 2: added centralized Currency Management, multi-currency, exchange-rate architecture, crypto-readiness.
- Rev. 3: finalized all previously open approval items (Currency Management as CORE, one-wallet-per-currency, `DECIMAL(38,18)` precision, `currency_id` FK principle, transaction-time default reporting policy) as recorded architecture decisions.
