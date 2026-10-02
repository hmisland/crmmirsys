# Notification module (Bale + SMS)

Status: **implemented** — Wallet-funded credit balance, Super Admin org-level entitlement, real Bale bot delivery, an SMS placeholder, and an opt-in "notify" checkbox on Internal Mail.

## What this added

| Piece | Where |
|---|---|
| Notification module (toggleable, `modules/Notification/`, **depends on Wallet** — the first real use of the module dependency system): `notification_credits`, `notification_credit_transactions`, `notification_logs`, `notification_recipients`, `notification_platform_settings` | `modules/Notification/Migrations/0001`–`0005` |
| `notifications.charge`/`notifications.view_log` permissions (org_admin only) | `Permission.php`, `database/migrations/0041` |
| `NotificationCreditService` (charges from the org's Wallet — a real debit/credit transfer, not just a number bump), `NotificationDispatchService` (the actual send, per recipient, per channel), `NotificationRecipientService` (self-service — a user's own Bale chat ID / phone), `NotificationSettingsService` (Super Admin pricing) | `modules/Notification/Services/` |
| `NotificationGatewayInterface` + real `BaleGateway` (verified against the official docs.bale.ai documentation, fetched directly) + `NullSmsGateway` (explicit placeholder — no provider connected yet) | `modules/Notification/Gateways/` |
| Settings/admin/pricing pages | `themes/default/templates/notification/` |
| An opt-in "notify" checkbox on Internal Mail's compose and reply forms, wired through a lazy, optional container lookup so Internal Mail works identically with or without this module installed | `modules/InternalMail/Controllers/MailController.php` |

## Architectural decisions made this phase

- **"Charging the module" is a real transfer, not a separate payment.** Org Admin's own org Wallet is debited (via `WalletService::debit()`) and this module's `notification_credits` balance is credited by the same amount — if the credit step somehow failed after a successful wallet debit, the wallet debit is rolled back by crediting it straight back (mirrors `WalletTransferService`'s own reversal handling).
- **First real use of the module dependency system.** `modules/Notification/module.json` declares `"dependencies": ["wallet"]` — Super Admin cannot grant an organization access to Notifications unless Wallet is already granted to that org (enforced by `ModuleService::assertDependenciesEnabledForOrganization()`, built back in Phase 4/6 but never actually exercised by a real dependency until now).
- **A genuine cross-module wiring bug was caught and fixed before shipping**: `MailController` is constructed inside `InternalMailModule::register()`, which — by the alphabetical order modules are discovered in — runs BEFORE `NotificationModule::register()`. Eagerly resolving `NotificationDispatchService` at that point would always fail, since it isn't bound into the container yet. Fixed by injecting the `Container` itself into `MailController` and resolving the dispatch service LAZILY, inside the request-handling method — by the time an actual HTTP request reaches `compose()`/`reply()`, every module has finished registering, so the binding is reliably there (or reliably absent, if Notification isn't installed at all).
- **Internal Mail has no hard dependency on Notification, in either direction.** `MailController::maybeNotify()` checks `$container->has(NotificationDispatchService::class)` before doing anything — if the Notification module isn't installed, mail sending behaves exactly as it did before this module existed. The one narrow exception to "Core doesn't know about modules" is in `Application::bootstrapModuleRoutes()`, which checks `instanceof \Modules\Notification\NotificationModule` specifically to bind its dispatch service into the container if present — a small, explicit, documented coupling point, not a general pattern.
- **Verified against Bale's actual documentation, not assumed**: `sendMessage` takes a `chat_id` (numeric ID or `@channelusername`) and `text`. There is NO documented way to message a user by phone number alone — a bot can only message a chat_id that has already started a conversation with it, the same restriction Telegram bots have. Every CRM user who wants Bale notifications has to start a chat with the org's bot themselves and paste their own numeric chat ID into `/notifications/settings` — there is no way around this on Bale's side.
- **Notification text is a short, fixed announcement, never the message body** — "You have a new message in [app name], check the panel" — per the explicit requirement that the full message text isn't needed. This also means notifications never leak message content over SMS/Bale even if those channels are less secure than the panel itself.
- **Both channels are attempted per recipient**, not "pick one" — a recipient with only a phone number gets an SMS attempt (which fails gracefully with "no provider connected" for now); a recipient with only a Bale chat ID gets a Bale attempt; a recipient with both gets both tried.
- **SMS is an explicit, honest placeholder** (`NullSmsGateway`) — always reports "not configured," never silently pretends to send. Connecting a real provider later (Kavenegar, Ghasedak, or any other) means writing one class implementing `NotificationGatewayInterface` and swapping it into `NotificationModule::register()` — nothing else changes, the same pattern as ZarinPal for Wallet.
- **Cost is deducted per successful attempt** (well, per attempt that clears the "has a destination + has credit" checks — see `NotificationDispatchService`), tracked in `notification_logs` and `notification_credit_transactions`, both scoped to the organization. Insufficient credit skips that one send (logged `skipped_no_credit`) without blocking the rest of a multi-recipient batch or the underlying Internal Mail send itself.

## Deliberately not done yet

- No real SMS provider — explicitly deferred per your instruction. The architecture is ready; only a driver is missing.
- `BALE_BOT_TOKEN` is empty — nothing will actually send via Bale until you provide your bot's token (from `@botfather` in Bale) and set it in `.env`.
- No way to message a Bale user by phone number — not a limitation of this implementation, a limitation of Bale's own Bot API (see above).
- No automated way to discover a user's Bale chat ID — they paste it in themselves, after finding it via a third-party "chat ID" helper bot (the same pattern almost every Telegram-API-compatible bot integration uses for this exact problem).
- No retry/backoff for failed sends — a failure is logged once and the batch moves on.
- No per-message customization of the notification text (always the same fixed announcement).

## Deploying this phase

1. Upload everything (full re-upload, overwrite).
2. Set `BALE_BOT_TOKEN` in `.env` once you have it — from `@botfather` inside Bale, per Bale's own bot-creation flow.
3. Run `/system/migrate?token=YOUR_TOKEN` — applies core migration 0041 (permission grants) and the Notification module's own 5 migrations.
4. As Super Admin: `/admin/modules` → sync from disk → confirm **Notifications** appears (and that it correctly refuses to be granted to an org that doesn't already have Wallet — try it on an org without Wallet first to confirm the dependency check works).
5. Grant your org **Wallet** first (if not already), then **Notifications**, from `/admin/organizations` → "Manage modules".
6. As Super Admin, visit `/admin/notifications/settings` and set your pricing (Bale can stay 0; SMS can be anything since nothing sends yet).
7. As Org Admin, visit `/notifications/admin`, charge some credit from your wallet, and confirm your Wallet balance decreased by the same amount and the notification credit balance increased.
8. As a member, visit `/notifications/settings` and paste in your Bale chat ID (get it from Bale's own "chat ID" helper bot after starting a chat with your org's bot).
9. Send an internal mail message to that member with the "notify" checkbox checked. Confirm: a Bale message arrives (if the token and chat ID are both set), the notification credit balance decreased by the Bale cost (if non-zero), and the attempt shows up in `/notifications/admin`'s delivery log.
10. Confirm the module boundary and dependency both work: disabling Notifications directly should make `/notifications/*` 404 and mail sending should keep working exactly as before, with no checkbox errors.

## Definition of Done checklist (per Phase 0 §108)

- [x] Functionality implemented (scope above, with the documented SMS/phone-number trims)
- [x] Security reviewed — every admin/Super Admin action permission-checked; the credit balance is only ever touched by `NotificationCreditService`, mirroring Wallet's own "one place touches the balance" discipline
- [x] Permissions tested manually (see deployment steps)
- [x] Tenant isolation — every repository is `TenantScopedRepository`
- [x] Module activation AND dependency tested (deployment steps 4 and 10) — the first real exercise of the module dependency system
- [x] Database migrations tested via `/system/migrate`
- [ ] API — web-only this round
- [x] UI tested — settings, admin, platform pricing, the compose/reply checkbox
- [x] Errors handled — insufficient wallet balance, insufficient notification credit, missing destination, unconfigured gateway, all logged or surfaced without crashing
- [x] Documentation updated (this file)
- [ ] Automated tests — none added this phase
- [x] CHANGELOG updated
- [x] Deployment considerations documented (above), including the still-missing `BALE_BOT_TOKEN`
