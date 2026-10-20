# Integrations, public links and Customer Satisfaction (Phase 1)

Target flow:

```
WooCommerce ──webhook──▶ /webhooks/integrations/{key} ──▶ integration_jobs (MySQL queue)
     ▲                                                         │
     └──── cron: incremental REST sync (recovers missed hooks) ┤
                                                               ▼
                         app/bin/scheduler.php → IntegrationWorker → WooCommerceProvider
                                                               │ woo_customers / woo_orders / items / products
                                                               ▼
                         Core event ExternalOrderStatusChanged (source-neutral)
                                                               ▼
                         Customer Satisfaction → cx_invitations (scheduled)
                                                               ▼
                         scheduler "cx.invitations" → PublicLinkService (/p/{token})
                                                               ▼
                         Core TransactionalDeliveryInterface  (implemented by Marketing — Phase 2, done)
                                                               ▼
                                                     SMS / Email / Bale → customer
```

## Layers and ownership

| Piece | Where | Owns |
|---|---|---|
| Integration framework | `app/Infrastructure/Integrations` (Core) | connections per organization (many per provider), encrypted credentials, enable/disable, status, last attempted/successful sync, last error, webhook URL + secret, job queue, retries, scheduling, Organization settings → Integrations (`/org/integrations`, `organization.edit`) |
| WooCommerce | `modules/WooCommerce` (module key `woocommerce`) | REST client, provider (validation, test, sync, webhook verification), its own data tables |
| Public links | `app/Infrastructure/PublicLinks` (Core) | secure tokens, expiry, revoke, single use, `/p/{token}`, `public_layout` |
| Customer Satisfaction | `modules/CustomerSatisfaction` (module key `customer_satisfaction`) | surveys, invitations (why/who/which survey/which link/when), responses, follow-up, `/cx` |
| Delivery | `app/Infrastructure/Messaging/TransactionalDeliveryInterface` (Core contract) | Marketing implements it (`Modules\Marketing\Services\TransactionalDelivery`, Phase 2); CX never calls a provider |
| Scheduler | `app/Infrastructure/Scheduling/ScheduledTasks` + `app/bin/scheduler.php` | one cron line for integration + CX background work |

Adding another integration = one class implementing `IntegrationProviderInterface`, registered in
`IntegrationRegistry` from a module's `register()`. Settings page, storage, webhook endpoint, queue and
cron need no change.

## Sync

* **Manual** — "Sync now" queues one `sync` job per resource; never calls the store in the request.
* **Cron** — `scheduler.php` (every minute) queues a sync for each enabled, entitled connection whose
  interval (`WOOCOMMERCE_SYNC_INTERVAL_MINUTES`, default 15) has passed, then processes the queue
  within ~50 s.
* **Webhook** — signature checked (`X-WC-Webhook-Signature`, HMAC-SHA256 of the raw body), one job
  queued, `200` returned. The verified payload is imported later by cron without an API call.

WooCommerce sync reads orders only (`orderby=modified`, `modified_after` keyset cursor, `per_page`
50, 2 pages per job, continuation jobs). Customers and products come from the orders. A new store
starts `WOOCOMMERCE_INITIAL_DAYS` (30) back. Imports are idempotent (unique external ids per
connection, older payloads never overwrite newer data).

Queue guarantees: `dedupe_key` (one pending sync per connection/resource; a webhook delivery is
stored once), atomic claiming, retry with back-off (1 min → 3 h, 5 attempts), stale-job recovery,
old jobs purged after 14 days.

## Security

* Credentials: `SecretBox` (AES-256-GCM) JSON blob; UI shows only a hint; sent only in the
  `Authorization` header; never logged. Store URLs must be public `https` (no IP in private ranges).
* Webhook URL: random 40-hex key (identifies the connection), signature authenticates it.
* Public tokens: 256-bit random, only `sha256(token)` stored, URL carries no ids; organization,
  subject, expiry, single use and revoke are server-side. Invalid links all give the same 404 page.
  `no-store`, `no-referrer`, `noindex`, `DENY` framing, POST rate limit per IP, CSRF on the form.
* Every query is organization-scoped; worker and public pages run inside the row's own organization.

## Cron (cPanel)

```
* * * * * /usr/local/bin/php /home/ACCOUNT/public_html/app/bin/scheduler.php >/dev/null 2>&1
```

## Phase 2 — delivery (done)

* Marketing implements `TransactionalDeliveryInterface` (`Services/TransactionalDelivery`, bound via
  `ProvidesServices`): one transactional message through its existing SMS / Email / Bale channels,
  honouring the channel's opt-out list, idempotent per reference, logged in
  `marketing_transactional_messages` (Marketing migration `0013`) and on the contact timeline. Details:
  `docs/marketing-module.md` §5a.
* An organization needs `marketing` (plus `marketing_email` / `bale_messaging` for those channels) and a
  ready provider; otherwise invitations wait as "waiting for a channel", as before.
* Super Admin: run module sync so `woocommerce` and `customer_satisfaction` appear, then entitle
  organizations.

## Phase A — WooCommerce customers → CRM contacts (done)

Per shop: Organization settings → Integrations → shop → **Contact sync**
(`/org/integrations/{id}/contact-sync`, `organization.edit` + module `woocommerce`). Code:
`modules/WooCommerce/ContactSync/`.

* **Settings** (`woo_contact_sync_settings`, one row per connection): on/off, the contact group
  (`contact_categories` — a contact has exactly one; required before switching on; a missing group can be
  created there with `contacts.manage_categories`, an existing name is reused), optional tags (many per
  contact), the source for new contacts (default: the shop's domain), the field mapping, and the last run.
  Switching on needs `contacts.create` + `contacts.edit`; new contacts belong to the user who switched it on.
* **Mapping** (`ContactFieldMapper`): billing first/last name, address (lines 1+2 or line 1), city, province
  (WooCommerce's Iranian state codes or a Persian province name → the CRM's province list; anything else is not
  imported), postcode, country (ISO code → name in the organization's language when PHP intl is available,
  else the code), email, and the generic WooCommerce phone: *auto* (Iranian mobile → Mobile, Iranian landline
  → Landline, unclear numbers skipped), *mobile only*, *landline*, or not imported — never both.
* **Matching** (`CustomerContactSync`): a link made by an earlier sync run is kept; otherwise contacts are looked
  up by normalized email (main + additional emails) and Iranian mobile (main + additional numbers, common stored
  forms). Several contacts for one identifier, or email and mobile on different contacts → *conflict*: nothing
  merged or changed. One contact → only its empty fields are filled (never overwritten, never erased), its
  source kept, the shop's group set only if it has none, tags added once. No contact → created only with a first
  name and a valid email or Iranian mobile; otherwise *skipped* with a reason.
* **When:** right after an order/customer import (before the order event, so Customer Satisfaction gets the
  contact), by the cron task `woocommerce.contacts` (pending customers of enabled shops), and by "Sync contacts
  now" — which first fetches the shop's latest orders (queues the order sync and runs this shop's queued jobs in
  the request, ~15 s, same jobs and claims as cron; a job waiting to retry is made due now), then syncs one
  bounded batch of customers. A customer is pending only when its data changed; claims make runs idempotent.
  Phone-only guest checkouts are now stored as customers (`guest-phone:` key).
* **Orders on the contact page:** "Shop orders" panel (`contacts.view`) reads the existing `woo_orders` through
  `woo_customers.contact_id` — no copy of the orders.
* Migrations: core `0087_add_contact_lookup_indexes` (indexes only), WooCommerce
  `0002_add_contact_sync` (customer address + sync state columns, settings table). The sync never sends a
  message or starts a campaign.

## Phase E — test survey invitation (done)

Customer Satisfaction → "Send a test invitation" (`POST /cx/test`, `cx.manage`, 10 per user per hour): a real
invitation for any survey of the organization (also one not switched on yet) goes to the user's own profile
mobile or email through the normal flow — fresh single-use public link, Marketing `TransactionalDeliveryInterface`
(purpose `cx_survey_test`), only on a channel that is ready. The invitation is marked `is_test` (Customer
Satisfaction migration `0003`) and the message starts with "[Test]": no order, no contact, never retried (a
failure is shown at once and the row is closed), shown as "Test" in the invitations list, and answering it shows
the real thank-you page and uses up the link but stores no survey response — statistics and follow-ups stay clean.

