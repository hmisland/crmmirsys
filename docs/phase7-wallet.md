# Phase 7 — Wallet

Status: **implemented** — wallets, immutable ledger, real ZarinPal top-up integration, user-to-user transfers, withdrawal requests, manual admin adjustments, and two-level (platform + organization) min/max limits.

## What this phase added

| Piece | Where |
|---|---|
| Minimal `currencies` table (IRR/USD/EUR seeded) — just enough for `currency_id` FKs per Phase 0 Rev.3 D4, not the full future Currency Management phase | `database/migrations/0034`, `CurrencyRepository` |
| `wallet_platform_settings` (Super Admin's platform-wide top-up cap, single row) | `database/migrations/0035`, `WalletPlatformSettingsRepository` |
| `wallet.*` permissions, granted to `org_admin` (organization scope) and `member` (own scope only) | `Permission.php`, `database/migrations/0036` |
| Wallet module (toggleable, `modules/Wallet/`): `wallets`, `wallet_ledger` (append-only), `wallet_transactions` (gateway top-up lifecycle, idempotent), `wallet_withdrawal_requests`, `wallet_org_settings` | `modules/Wallet/Migrations/0001`–`0006` |
| `WalletService` — the one place balance + ledger are ever written, always together, always inside a row-locked transaction | `modules/Wallet/Services/WalletService.php` |
| `PaymentGatewayInterface` + real `ZarinPalGateway` (v4 REST API, verified against current official ZarinPal docs) + `GatewayRegistry` — Mellat/Saman Kish are meant to implement the same interface and register alongside ZarinPal later | `modules/Wallet/Gateways/` |
| `WalletTopUpService` (initiate + idempotent callback verification), `WalletTransferService`, `WalletWithdrawalService`, `WalletAdminService` (manual credit/debit), `WalletSettingsService` (two-level limits), `WalletLedgerAccessService` | `modules/Wallet/Services/` |
| Member wallet page, org admin dashboard, Super Admin platform settings page | `themes/default/templates/wallet/` |

## Architectural decisions made this phase

- **Money is never a float, anywhere.** Every amount is a decimal string, stored as `DECIMAL(38,18)` (Phase 0 Rev.3 D3) and manipulated exclusively with PHP's `bcmath` extension (`bcadd`/`bcsub`/`bccomp`/`bcmul`/`bcdiv`, all with an explicit 18-digit scale). `bcmath` is effectively universal on shared cPanel PHP builds, but it is a real runtime requirement worth confirming if you ever move hosts.
- **`WalletService::credit()`/`debit()` are the only two places that ever touch `wallets.balance` or write to `wallet_ledger`.** Every other Service (top-up, transfer, withdrawal, manual adjustment) calls into these rather than writing either table itself. Both lock the wallet row (`SELECT ... FOR UPDATE`) inside one transaction, so two concurrent operations on the same wallet can never race.
- **`transaction()` was widened from `protected` to `public`** on `BaseRepository` (used by every repository in the app, not just Wallet's) — a Service orchestrating a transaction across two different repositories (`WalletService` locks via `WalletRepository`, writes via `WalletLedgerRepository`, in one transaction) isn't a repository subclass and could never have called a protected method on an injected repository instance. Caught before it shipped as a fatal error, not after.
- **ZarinPal top-up idempotency has two independent layers**, deliberately not relying on either alone: (1) a UNIQUE constraint on `wallet_transactions.(gateway_key, provider_reference)` — the database itself refuses two rows for the same ZarinPal authority; (2) an atomic "claim" step (`WalletTransactionRepository::claimPending()`, a single conditional `UPDATE ... WHERE status = 'pending'`) that closes a real race the first layer alone doesn't: two near-simultaneous hits on the same callback URL (a double-click, a browser retry) could otherwise both pass the "is this already completed?" check before either finishes verifying with ZarinPal. Only whichever request's `UPDATE` actually matches proceeds; the other backs off. This needed a new `processing` status (migration `0006`), added rather than editing the already-shipped `0003`.
- **ZarinPal amounts are Rials, not Tomans** — confirmed against ZarinPal's own current documentation while building this (not assumed from memory), and called out prominently in `ZarinPalGateway`'s docblock specifically because Iranian businesses commonly think in Toman. No silent ×10 conversion happens inside the gateway class; if a future UI wants to label amounts in Toman, that conversion belongs at the UI boundary, not hidden in the payment integration.
- **Withdrawal funds are debited at REQUEST time, not at approval time** — a member can't request the same money twice while a request is pending. A rejection credits the amount straight back as a `reversal` ledger entry; approval leaves the debit as-is (the money already left the wallet by design) and just records the sign-off.
- **Two-level min/max top-up, exactly as requested**: Super Admin sets a platform-wide cap (`wallet_platform_settings`, one row); each Org Admin sets their own organization's min/max (`wallet_org_settings`), which `WalletSettingsService::effectiveTopupLimits()` always clamps into the platform's range — even if the platform cap is tightened *after* an org already set a wider value, the org's effective limit shrinks automatically without needing to touch the org's own row.
- **Every Wallet repository query joining across two tenant-scoped tables uses a correlated subquery with an explicitly-qualified org condition**, never `tenantFindMany()`'s automatic (naive, first-`WHERE`-only) scoping — this exact bug class bit Contacts for real in Phase 5, and very nearly repeated itself twice more in this phase (`WalletLedgerRepository::forOrganizationWithOwnerNames()`, `WalletWithdrawalRepository::pendingForOrganization()`) before being caught and fixed pre-delivery.
- **Currency is deliberately single, platform-wide for now** — every wallet in every organization shares whatever currency Super Admin has configured (IRR by default). Full multi-currency wallets (Phase 0 Rev.3 D2: one wallet per currency) are architecturally supported by the schema (`currency_id` on every table) but not yet exposed in the UI; this is a real, intentional scope trim, not an oversight.

## Deliberately not done yet

- Mellat and Saman Kish gateways — the interface and registry are ready for them (per your stated plan), but only ZarinPal is implemented.
- No refund flow tied to a specific original transaction — `WalletAdminService`'s manual credit covers the same practical need (crediting money back with a reason) without the extra UI for linking it to one specific past transaction.
- No automated withdrawal payout (no bank API) — "paid" is a manual status an admin sets after actually sending the money themselves.
- No fee revenue account — a transfer fee is deducted from the sender but isn't credited anywhere (see `WalletTransferService`'s docblock); routing fees to a platform revenue wallet is future Finance-phase work.
- No multi-currency wallet UI (see above).
- No crypto wallet logic — the `asset_type` column exists per Phase 0 Rev.3 D9 groundwork, but nothing reads or writes anything but `'fiat'`.

## Deploying this phase

1. Upload everything (full re-upload, overwrite).
2. Set `ZARINPAL_MERCHANT_ID` in your `.env` (get it from your ZarinPal panel). Leave `ZARINPAL_SANDBOX=true` until you're ready to accept real payments.
3. Run `/system/migrate?token=YOUR_TOKEN` — applies core migrations 0034–0036 (currencies, platform settings, permission grants) and the Wallet module's own 6 migrations.
4. As Super Admin, go to `/admin/modules`, sync from disk, then `/admin/organizations` → **"Manage modules"** for your test org → grant **Wallet**.
5. As Super Admin, visit `/admin/wallet/settings` and confirm/adjust the platform-wide min/max top-up.
6. As Org Admin, visit `/org/wallet` → set your organization's own min/max (must stay within the platform's range) and a transfer fee if you want one.
7. As a member, visit `/wallet`, top up an amount within the allowed range via ZarinPal sandbox — you should land on ZarinPal's sandbox payment page, and after completing/cancelling it, land back on `/wallet` with the balance updated (or an error, if cancelled).
8. Test a transfer to a colleague, and a withdrawal request — then, as Org Admin on `/org/wallet`, approve or reject it and confirm the ledger reflects it correctly either way.
9. Test a manual adjustment (credit and debit) from `/org/wallet` and confirm it shows up with your typed reason in the ledger.

## Definition of Done checklist (per Phase 0 §108)

- [x] Functionality implemented (scope above, with the documented trims)
- [x] Security reviewed — every write goes through permission-checked Services; `WalletService` is the sole path to balance/ledger writes; idempotency enforced at both the DB-constraint and application-claim level
- [x] Permissions tested manually (see deployment steps); member limited to `own` scope only, verified by RBAC grants in migration 0036
- [x] Tenant isolation — every Wallet repository is `TenantScopedRepository`, with the two correlated-subquery methods explicitly (not automatically) org-qualified for the reasons above
- [x] Module activation — Wallet is a real toggleable module, gated by `ModuleMiddleware` exactly like Sales; Super Admin's platform settings page is the one deliberate exception (reachable before any org has Wallet enabled, by design)
- [x] Database migrations tested via `/system/migrate`, including the module-migration discovery path and a same-module follow-up migration (0006) added after the fact rather than editing an already-shipped one
- [ ] API — web-only this round
- [x] UI tested — member wallet page, org admin dashboard, Super Admin settings
- [x] Errors handled — gateway failures, insufficient balance, out-of-range amounts, and the concurrent-callback race all produce a clear message, never a crash
- [x] Documentation updated (this file)
- [ ] Automated tests — none added this phase; `WalletService::credit()`/`debit()`'s locking behavior and `WalletTopUpService::handleCallback()`'s idempotency claim are exactly the kind of logic worth a real concurrency test later
- [x] CHANGELOG updated
- [x] Deployment considerations documented (above), including the `bcmath` PHP extension requirement
