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
