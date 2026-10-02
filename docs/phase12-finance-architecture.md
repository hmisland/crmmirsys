# Phase 12 — Finance & Tax: Architecture Specification

**Status: architecture proposal only — no implementation, per explicit instruction. Nothing in this document has been built. Stop and wait for approval before any code is written.**

This preserves every Phase 0–11 decision (modular/multi-tenant/API-first architecture, Core/Optional/Add-on model, Module Manager + entitlements + user overrides, RBAC + `Scope` enum, `OrganizationContext` tenant isolation, Service→Repository pattern, `EventBus`, centralized `currencies` table, `DECIMAL(38,18)`/bcmath, immutable Wallet ledger, localization). Nothing here redesigns those.

---

## 1. Finance vs. Wallet boundary

Two separate systems, never merged, matching the explicit rule:

| | Finance | Wallet |
|---|---|---|
| Who it's about | External commercial parties: **Customers**, Suppliers/Vendors | Internal platform value: **Platform Owner ↔ Organization ↔ Members** |
| Owns | `financial_profiles`, `products`, `quotes`, `orders`, `invoices`, `credit_notes`, `payments`, tax tables | `wallets`, `wallet_ledger`, `wallet_withdrawal_requests`, `notification_credits` (already built) |
| A customer owing money | A receivable — no Wallet involved at all | N/A |
| Cross-module contact | `FinanceService` calls `WalletService::debit()/credit()`, or Finance emits an event Wallet listens to | Never the reverse, never SQL-to-SQL |

**Hard rule carried into every table design below**: no Finance table ever gets a foreign key into `wallet_ledger`, and no Wallet table ever gets a foreign key into `invoices`. A Finance invoice paid via wallet records `payment_method = 'wallet'` with a `wallet_ledger_id` reference column (an opaque pointer, read-only from Finance's side) — not a join, not a shared table.

A CRM **Contact** (already Core) is reused as the Finance "customer" — no duplicate Customer table. Finance adds its own data *about* a contact (tax ID, billing address, exemption) in Finance-owned tables keyed by `contact_id`, the same pattern Sales/Support already use to attach module data to Core entities without touching `contacts` itself.

---

## 2. Region ≠ language (hard rule, enforced structurally)

Finance jurisdiction is never derived from `users.language` or `organizations.default_language`. A `tax_jurisdictions` table (Country → Region/Province → Tax Regime) is the only input to tax logic. An organization's financial profile explicitly picks its jurisdiction; nothing infers it from UI locale. No Finance service file is permitted to read `$user['language']` or `Locale::get()` for a calculation — only for rendering the same numbers into a document.

---

## 3. Financial jurisdiction model

```
countries (id, name, iso_code)
regions (id, country_id, name)                       -- state/province, optional
tax_jurisdictions (id, country_id, region_id NULL, name)
tax_regimes (id, tax_jurisdiction_id, name, effective_from, effective_to NULL)
```

A `tax_regime` is the versioned container tax rules attach to (§7). Nothing here encodes any specific country's rules — Turkey/UAE/Saudi/Iran are just rows, added by seeding data, never by branching code.

---

## 4. Organization financial profile

New table `financial_profiles` (1:1 with `organizations`):

```
organization_id (PK/FK)
legal_name, trade_name, legal_entity_type
tax_identification_number, vat_registration_number, registration_number
tax_registration_status
country_id, region_id, tax_jurisdiction_id, tax_regime_id
base_currency_id, reporting_currency_id, default_invoice_currency_id
fiscal_year_start_month, fiscal_year_start_day
address (reuses existing address/city/province/country field shapes from Companies)
tax_profile_id (default profile for this org's outgoing invoices)
invoice_prefix, invoice_numbering_policy (json — see §16)
pricing_mode ('tax_inclusive' | 'tax_exclusive')
rounding_policy_id
financial_settings (json overflow bucket, so one jurisdiction's odd requirement doesn't force a schema migration)
```

**Configurable identifier labels**: rather than hard-coded field names ("VAT Number" vs "Tax ID" vs "National ID"), a `tax_jurisdiction_identifier_labels` table maps `tax_jurisdiction_id` → which generic column applies and what to call it in the UI (translated per Phase 11's language files). Turkey's row might label a column "Vergi Kimlik Numarası"; UAE's might label the same column "TRN". Same column, different label — no schema difference between jurisdictions.

---

## 5–8. Tax Engine

Dedicated services, never inline in `InvoiceService`:

```
TaxProfileService     — CRUD for tax profiles (Standard/Zero-Rated/Exempt/Reverse-Charge/...)
TaxRuleEngine          — resolves which rule(s) apply for (org, customer, product, jurisdiction, date)
TaxCalculationService  — pure function: inputs → TaxResult, no side effects, no DB writes
TaxService             — thin orchestrator other services call
```

`TaxCalculationService::calculate()`:
```
calculate(lineItems[], customer, organization, jurisdiction, transactionDate, pricingMode)
  → TaxResult { subtotal, discount_total, taxable_amount, tax_lines[], tax_total, grand_total,
                currency_id, jurisdiction_id, tax_regime_id, rounding_policy_id, calculated_at }
```

Every `tax_rule` row is effective-dated (`effective_from`, `effective_to`) and never edited in place once used by a real transaction — a rate change is a new row, not an `UPDATE`. `TaxResult` is persisted verbatim onto the invoice at creation time (`invoice_tax_snapshot`, §15); nothing about tax is ever recomputed later from "today's" rule.

```
tax_categories (id, name)                             -- e.g. goods, services, digital
tax_rules (id, tax_regime_id, tax_category_id NULL, rate_type ['fixed','percentage','zero','exempt','reverse_charge','withholding','compound'], rate, effective_from, effective_to, applies_tax_inclusive_by_default)
tax_profiles (id, organization_id NULL, name, description)   -- organization_id NULL = platform template profile
tax_profile_rules (tax_profile_id, tax_rule_id)               -- a profile bundles one or more rules
tax_exemptions (id, tax_jurisdiction_id, contact_id NULL, product_id NULL, reason, certificate_reference, valid_from, valid_until)
```

---

## 9. Customer (Contact) tax information

New table `contact_financial_profiles` (1:1 optional, keyed by `contact_id`) — deliberately not new columns on `contacts` (Core stays untouched, the same pattern every other module has used since Phase 5):

```
contact_id (PK/FK)
tax_identification_number, vat_number, registration_number
tax_jurisdiction_id, tax_profile_id
tax_exempt_status, tax_exemption_reference
billing_address, shipping_address
```

The UI shows only fields relevant to the contact's `tax_jurisdiction_id` (via the same identifier-label lookup as §4) — never every country's fields at once.

---

## 10–12. Products, pricing mode, discounts

```
products (id, organization_id, sku, name, description, category, unit, currency_id, price, tax_category_id, tax_profile_id NULL, is_active)
```
Resolution order for which tax profile applies: **product profile → organization default profile → platform template**, first non-null wins.

Both tax-inclusive and tax-exclusive pricing are supported; `financial_profiles.pricing_mode` sets the org default, and every quote/order/invoice stores which mode it actually used — never inferred after the fact.

Discount order is fixed and explicit (no jurisdiction encountered in initial scope needs a different order — flagged as an open item below if that changes):
```
Gross → Line Discount → Subtotal → Invoice Discount → Tax → Grand Total
```

---

## 13–14. Quotes and Orders

```
quotes (id, organization_id, contact_id, owner_user_id, created_by_user_id, issue_date, expiry_date, currency_id, pricing_mode, subtotal, discount_total, tax_total, grand_total, terms, notes, status)
quote_items (id, quote_id, product_id NULL, description, quantity, unit_price, discount_type, discount_value, tax_category_id, line_subtotal, line_tax, line_total)

orders (id, organization_id, contact_id, quote_id NULL, owner_user_id, status, currency_id, pricing_mode, subtotal, discount_total, tax_total, grand_total, billing_address, shipping_address)
order_items (same shape as quote_items)
```

Status machines, enforced server-side, invalid transitions rejected:
```
Quote:  Draft → Sent → Accepted → Converted
                     ↘ Rejected
                     ↘ Expired
Order:  Draft → Confirmed → Processing → Completed
                          ↘ Partially Fulfilled
                          ↘ Cancelled
```
Quote→Order and Order→Invoice conversion copies the financial snapshot into the new document's own rows — never a live reference back to the source, so editing a quote later can't change an already-issued invoice.

---

## 15–17. Invoices, numbering, credit notes

```
invoices (id, organization_id, contact_id, order_id NULL, invoice_number, series_id, issue_date, due_date, currency_id, pricing_mode, tax_jurisdiction_id, tax_profile_id, subtotal, discount_total, tax_total, grand_total, paid_total, outstanding_total, status, notes, terms, finalized_at)
invoice_items (same shape as order items, plus tax_rule_id and computed tax_amount frozen at issue time)
invoice_tax_snapshot (invoice_id, tax_result_json)   -- the full TaxResult, verbatim, for audit/explainability
```

Status machine: `Draft → Issued → Sent → Partially Paid → Paid → Overdue`, with `Cancelled/Voided` reachable from any pre-Paid state. **Draft is the only mutable state** — once Issued, an invoice is immutable; a correction is a Credit Note, never an `UPDATE`.

**Numbering** (`invoice_number_series`: `organization_id, series_key, prefix, next_sequence, fiscal_year_reset, current_fiscal_year`). Allocation happens inside a transaction with a row lock (`SELECT ... FOR UPDATE`) on the series row — the same concurrency-safe pattern already used for Wallet's atomic balance updates — never a bare `MAX(invoice_number)+1`, a classic race condition under concurrent issuance.

**Credit notes**: `credit_notes` / `credit_note_items`, always referencing the original invoice, with their own independent numbering series — the only sanctioned way to reduce a finalized invoice's effective total.

---

## 18. Payments

```
payments (id, organization_id, contact_id, invoice_id NULL, amount, currency_id, payment_method, payment_reference, gateway_reference, exchange_rate_id NULL, payment_date, status, created_by_user_id, idempotency_key UNIQUE)
payment_allocations (id, payment_id, invoice_id, allocated_amount)   -- one payment can settle several invoices; one invoice can be settled by several payments
refunds (id, payment_id, invoice_id, amount, reason, status, created_by_user_id)
```

`payment_method` is a plain string/lookup table (`cash`, `bank_transfer`, `card`, `gateway`, `wallet`, `other`) — never a hard-coded enum tied to one provider. `payments.idempotency_key` UNIQUE plus the same atomic-claim pattern already proven in Wallet's `claimPending()` covers duplicate gateway callbacks.

**Wallet-as-payment-method**: when `payment_method = 'wallet'`, `PaymentService` calls `WalletService::debit()` on the contact's associated platform user — only if that contact actually corresponds to a real user with a wallet (most external customers won't). This is the one deliberately narrow integration point; everywhere else, Finance has no idea Wallet exists.

---

## 19–21. Receivables; Payables (scoped out this phase)

**Accounts Receivable**: derived, not a separate ledger — `invoices.outstanding_total` (kept in sync by `PaymentService` on every allocation/refund) *is* the receivable. A per-contact statement view just unions Invoices/Payments/Credit Notes/Refunds chronologically — no new source-of-truth table.

**Accounts Payable**: not implemented this phase (see §22 recommendation). A `suppliers` stub table (id, organization_id, name, tax info) is added now purely as a clean extension point, but Bills/payable-balances/supplier-payments are explicitly deferred.

---

## 22. Accounting scope — recommendation (needs your approval)

**Recommendation: Option A (Commercial Finance) + basic AR, explicitly not Option B (full GL/double-entry accounting), and Accounts Payable deferred beyond a stub table.**

Reasoning: a real General Ledger (Chart of Accounts, Journal Entries, Trial Balance, P&L, Balance Sheet, period-close with debit/credit balancing) is its own multi-year product category, not one phase of a CRM — and nothing in this project's history or your original three-option question (invoice-only / invoice+expenses / invoice+expenses+P&L) asked for double-entry bookkeeping. A half-built GL is worse than none — it invites trusting numbers a partial ledger can't actually guarantee are balanced.

**Clean extension point, not a dead end**: every state-changing Finance action emits an event (`InvoiceIssued`, `PaymentConfirmed`, `CreditNoteCreated`, etc.). A future Accounting module could subscribe to exactly these events and build a real double-entry ledger without Finance itself ever writing a journal entry.

**This is an open decision, flagged explicitly below — not assumed.**

---

## 23. Financial periods

```
financial_periods (id, organization_id, starts_on, ends_on, status ['open','closed','locked'])
```
Once closed, `InvoiceService`/`PaymentService` reject any write whose date falls inside that period unless the actor has a new `financial_periods.reopen` permission.

---

## 24–28. Multi-currency, exchange rates, rounding

**Honest gap, found while writing this spec, not assumed away**: the spec treats exchange rates/history as already-existing authoritative architecture. Checked against the actual Phase 7 code just now — it isn't built yet. `currencies` (code/name/symbol/decimal_precision) exists; there is no `exchange_rates` table anywhere in this codebase, and Wallet is documented as intentionally single-currency-per-platform for exactly this reason. Finance needs real multi-currency more urgently than Wallet did. **This is an open decision** — see below.

If approved, the addition (Core-owned, so Wallet inherits it too rather than Finance duplicating it):
```
exchange_rates (id, source_currency_id, destination_currency_id, rate, rate_source, effective_at)
```
Every Finance conversion stores its own frozen snapshot directly on the invoice/payment row (`source_currency_id, destination_currency_id, source_amount, destination_amount, exchange_rate_id, rate, rate_timestamp`) — a later change to `exchange_rates` never rewrites history.

**Rounding**: a single `RoundingService` (bcmath-based, no floats, continuing the existing Wallet convention exactly) is the only place any Finance code rounds a number. `rounding_policy_id` on the org's financial profile picks the strategy (round-per-line / round-per-tax-category / round-invoice-total) — the policy is data, not an `if` chain.

---

## 29–33. Localization, document templates, compliance, exemptions

Financial documents use the same `t()`/`Translator` system already built in Phase 11 — a `FinancialDocumentRenderer` service takes an already-calculated Invoice/Quote/CreditNote object plus a locale and produces HTML/PDF; it never recalculates anything, only relabels and reformats. This is the only place financial code reads `$user['language']`, confirming §2's rule holds even at the presentation layer — a Turkish-jurisdiction invoice rendered in Arabic for an Arabic-speaking accountant is a completely normal, valid combination.

Country-specific electronic invoicing is an interface, not core code:
```
interface FiscalComplianceProviderInterface {
    submit(Invoice $invoice): FiscalSubmissionResult;
    validate(Invoice $invoice): FiscalValidationResult;
}
```
No adapter (Iranian/Turkish/UAE/Saudi) is built this phase — the interface exists so one can be added later (`modules/FinanceRegions/Turkey/...`) without touching `InvoiceService`.

Tax exemptions are covered by `tax_exemptions` plus the frozen `invoice_tax_snapshot` — a later change to an exemption's validity never alters an invoice issued while it was valid.

---

## 34. Audit trail

Finance reuses the same audit/timeline infrastructure already built for CRM Core (`TimelineService`, proven since Phase 5) rather than inventing a parallel system — every tax-rule change, invoice issuance/cancellation, payment/refund, and period close/reopen writes a timeline event, with the same exclusion list already the established convention (no passwords/secrets/payment credentials/keys).

---

## 35–37. Permissions, scopes, tenant isolation

New `Permission` constants, following the exact naming/registration pattern every prior module has used:
```
finance.settings.view / finance.settings.edit
products.view / products.create / products.edit / products.delete
quotes.view / quotes.create / quotes.edit / quotes.approve / quotes.convert
orders.view / orders.create / orders.edit / orders.cancel
invoices.view / invoices.create / invoices.edit / invoices.issue / invoices.cancel / invoices.export
payments.view / payments.create / payments.refund / payments.reconcile
tax.view / tax.manage / tax.rules.manage / tax.profiles.manage
financial_reports.view / financial_reports.export
financial_periods.view / financial_periods.close / financial_periods.reopen
suppliers.view / suppliers.create / suppliers.edit / suppliers.delete   (stub-scope only)
```
All five existing `Scope` values (OWN/TEAM/DEPARTMENT/ORGANIZATION/ALL) apply unchanged — e.g. a Sales Agent role might get `invoices.view` at OWN, a Finance Manager at ORGANIZATION. Every Finance table carries `organization_id`, and every repository is `TenantScopedRepository` — including the now-standard habit of explicitly org-qualifying any correlated subquery rather than trusting automatic first-`WHERE` scoping, a bug class that has bitten this project for real more than once.

---

## Database entity map (summary)

```
financial_profiles          contact_financial_profiles
countries → regions → tax_jurisdictions → tax_regimes
tax_categories   tax_rules   tax_profiles   tax_profile_rules   tax_exemptions
tax_jurisdiction_identifier_labels
products
quotes → quote_items
orders → order_items
invoices → invoice_items   invoice_tax_snapshot   invoice_number_series
credit_notes → credit_note_items
payments → payment_allocations   refunds
financial_periods
suppliers (stub only)
exchange_rates   (Core, shared with Wallet — open decision, see below)
```
All monetary columns `DECIMAL(38,18)`; all currency references `currency_id`, never free text — no exceptions.

---

## Event map

`QuoteCreated, QuoteAccepted, QuoteConverted, OrderCreated, OrderConfirmed, InvoiceIssued, InvoicePaid, InvoicePartiallyPaid, InvoiceOverdue, InvoiceCancelled, PaymentCreated, PaymentConfirmed, PaymentRefunded, CreditNoteCreated, TaxCalculated, FinancialPeriodClosed` — all via the existing `EventBus`, no new event mechanism.

---

## API map (web-first, per this project's established pattern)

Every prior phase in this build shipped as web routes/controllers first — no REST API has actually been built for any module yet, despite "API-first" being a Phase 0 principle. Finance would be the first module to actually implement `/api/v1/...` if wanted now, which is a real scope question flagged below, not assumed.

If approved: `/api/v1/finance/products`, `/quotes`, `/orders`, `/invoices` (+`/issue`, `/cancel`), `/payments` (+`/refund`), `/taxes`, `/reports/...` — every response still passing through tenant isolation, RBAC, module entitlement, and idempotency exactly as web controllers would.

---

## Testing matrix

Tax (rate/effective-dates/exemptions/zero-rated/inclusive-exclusive/rounding/jurisdiction-change/historical-invoice-immutability/per-customer-profile), Currency (same/cross-currency, historical rate, precision), Invoice (full lifecycle incl. overpayment/refund/credit-note), Payment (duplicate/failed/success/refund/reconciliation), Security (IDOR, cross-tenant, unauthorized tax-rule edit, unauthorized period close, disabled-module, user-override, scope violation), Wallet integration (paid-via-wallet, insufficient balance, wrong currency, failed transaction, duplicate callback, "Finance cannot touch wallet_ledger directly" as an explicit negative test). No automated tests exist for any prior module in this build — Finance would be the first, a real deliberate scope increase worth confirming, flagged below.

---

## Implementation sequence — accepted as proposed

12A Foundation → 12B Org financial profile → 12C Tax/Jurisdiction engine → 12D Products → 12E Quotes → 12F Orders → 12G Invoices → 12H Payments → 12I Credit Notes/Refunds → 12J Receivables (Payables deferred) → 12K Financial periods → 12L Reports → 12M Wallet integration → 12N Regional compliance adapters (interface only) → 12O Security/testing hardening.

---

## FINAL ARCHITECTURE DECISIONS

- Finance and Wallet are fully separate; the only bridge is `PaymentService → WalletService::debit()` for `payment_method = 'wallet'`, plus events. No shared tables, no cross-module SQL.
- Jurisdiction is a first-class, explicit model, never derived from UI language.
- Tax rules are effective-dated and never edited in place; every invoice freezes its own tax snapshot.
- Invoices are immutable once Issued; corrections are always Credit Notes, never `UPDATE`s.
- Invoice numbering is series-based with row-locked atomic allocation — never bare auto-increment.
- Payments support partial/multiple/overpayment via `payment_allocations`; idempotency enforced via unique key + atomic claim, mirroring Wallet's proven pattern.
- Accounts Receivable is a derived view over Invoices/Payments, not a separate ledger.
- Full double-entry General Ledger accounting is out of scope this phase (recommendation, pending approval below).
- Accounts Payable is out of scope this phase beyond a stub `suppliers` table (recommendation, pending approval below).
- Country-specific electronic invoicing is an interface with zero adapters built yet.
- All five existing RBAC scopes and every existing tenant-isolation/audit convention carry over unchanged.

## OPEN DECISIONS REQUIRING YOUR APPROVAL

1. **Accounting scope**: approve "Commercial Finance + basic AR, no GL, no AP" as recommended? Or do you want AP (supplier bills) now, or even full double-entry GL?
2. **Exchange rates don't exist yet**: a real `exchange_rates` table with history is needed for genuine multi-currency invoicing, but nothing like it exists today (Wallet deliberately stayed single-currency). Build this now (benefiting Wallet too), or scope Finance's first release to single-currency (the org's own base currency only), with true multi-currency deferred?
3. **API**: every module so far is web-only. Should Finance be the first to actually build `/api/v1/...`, or stay web-only like everything else, with a platform-wide API layer added later?
4. **Automated tests**: no automated test suite exists for any module so far. Confirming Finance should be the first with real tests — a genuine, deliberate scope increase versus every prior phase's manual-verification-only approach.
5. **Discount-order jurisdiction variance**: the fixed discount→tax order proposed works for every jurisdiction likely in initial scope; flagging that a specific jurisdiction requiring a different order would make this a configurable strategy instead of one fixed order.
6. **Which jurisdiction(s) to seed first with real tax rules/rates** (Iran? Turkey? both?) — this determines what Phase 12C's actual seed data looks like, since the model supports any jurisdiction but issuing real invoices needs real rule data entered first.

No code will be written until you've responded to these.
