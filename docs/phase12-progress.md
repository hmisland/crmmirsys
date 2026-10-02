# Phase 12 — Finance: Progress & Deferred Decisions

See `phase12-finance-architecture.md` for the full architecture spec.
This file tracks sub-phase progress and records decisions made along
the way so they survive between sessions.

## Approved scope (confirmed by the client)

- Commercial Finance + basic AR only — **no** full General Ledger, **no**
  full Accounts Payable (a `suppliers` stub table only, if ever added).
- Exchange rates: a real `exchange_rates` table + Super Admin
  management page (`/admin/exchange-rates`) — never a hardcoded rate.
  Seeded with USD↔IRR only for now; more currencies/an API-connected
  rate source are additive later, not a redesign.
- Finance is the **first** module in this project to get a real
  `/api/v1/finance/...` REST API (sub-phase 12.10).
- Finance is the **first** module in this project to get an automated
  test suite (sub-phase 12.11).
- Discount→tax calculation order stays the single fixed order from
  the architecture doc (Gross → Line Discount → Subtotal → Invoice
  Discount → Tax → Grand Total) — no jurisdiction identified yet that
  needs a different order.
- First (and for now, only) real tax jurisdiction seeded: **Iran**.
  Turkey/UAE/Saudi etc. are schema-ready but have no real tax rules
  entered yet.

## Proforma/Invoice workflow (client's stated priority)

- **Proforma** (پیش‌فاکتور) = the architecture doc's "Quote" entity.
  Full, unrestricted CRUD (create/edit/delete) by its own creator — no
  approval gate.
- **Invoice**: any authorized user can create one (including a direct
  "Convert to Invoice" action on a Proforma — the separate "Order"
  stage from the original architecture draft is **not** being built;
  it's a clean future extension point if ever needed, not part of
  this rollout). **Issuing** (finalizing) an invoice requires Org
  Admin approval — `invoices.issue` is Org-Admin-only, separate from
  `invoices.create`/`invoices.edit` which any authorized creator has.
- **Products & Services**: its own dedicated CRUD section (sub-phase
  12.3), not folded into Proforma/Invoice forms.

## DEFERRED — Customer Portal (explicit decision, not forgotten)

As of this sub-phase, this platform has exactly three access tiers —
`member`, `org_admin`, `super_admin` — **all internal staff**. A
Contact (Finance's "customer") is a CRM record only: no login, no
ability to view their own invoices, no ability to pay online or hold
a wallet themselves.

The client explicitly chose to **defer** building a fourth tier (a
real customer-facing portal with its own authentication) rather than
build it as part of Phase 12. Every payment — online, bank transfer,
cash — is recorded by an internal staff member on the customer's
behalf (`payments.create`), never by the customer logging in
themselves.

**This means:** the original architecture draft's §9/§19 ("Finance ↔
Wallet integration" sub-phase 12.9) has very little real use until a
customer portal exists, since it only matters when the paying party
is also an internal platform user. Treat 12.9 as low-priority/likely
to be re-scoped once a customer portal is actually decided on.

**If a customer portal is ever wanted**, it is its own separate,
explicit product decision — a new access tier, its own
authentication/session handling, its own security review — not
something to fold quietly into a later Finance sub-phase. Revisit
this file when that conversation happens.

## Sub-phase status

- [x] **12.1 — Foundation**: `countries`/`tax_jurisdictions`
      (Core, seeded with Iran), `exchange_rates` (Core, seeded
      USD↔IRR placeholder — **update to the real current rate**),
      `financial_profiles` (Finance-owned, supports both legal
      entities and natural persons via `entity_type` — corrected
      after initial delivery), Finance module scaffold, Org Admin
      settings page (`/finance/settings`), Super Admin exchange-rate
      page (`/admin/exchange-rates`), full Finance permission
      catalogue registered (grants added per sub-phase as each
      feature is actually built).
- [x] **12.2 — Tax engine + Iran VAT**: `tax_categories`,
      `tax_rules` (effective-dated, never edited in place),
      `tax_profiles`/`tax_profile_rules` (platform templates +
      room for org-specific profiles later), seeded with the real
      current Iran standard VAT rate — **10%**, effective from the
      1404 budget law (verified by web search at migration time;
      was 9% before). `RoundingService` (Core, bcmath round-half-up,
      no floats) and `TaxCalculationService` (Finance, pure
      calculator — no DB writes, reusable for both a live preview
      and a frozen snapshot once Proforma/Invoice exist). Org Admin
      can now pick their organization's default tax profile on the
      financial-profile settings page. No UI yet to manage/add tax
      rules themselves — deferred until a real need for it shows up
      (e.g. a second jurisdiction, or a rate change); for now a new
      rate is a migration, matching how the first one was seeded.
- [x] **12.3 — Products & Services**: `products` table (Finance-
      owned, per-org catalog), its own dedicated section at
      `/finance/products` — not folded into Proforma/Invoice forms.
      `products.view` granted to both `member` and `org_admin` (any
      team member can browse the catalog once Proforma/Invoice exist
      and need to pick from it); create/edit/delete stay
      `org_admin`-only. A product can override the organization's
      default tax profile, or just use the org default. First
      member-facing Finance nav link added (gated on the module
      being enabled, matching Sales/Wallet/Support's existing
      pattern). **Two real bugs caught before shipping**: (1) the
      product form's first draft nested a delete `<form>` inside the
      edit `<form>` — invalid HTML with unpredictable browser
      behavior; (2) `ProductRepository::search()`'s first draft used
      `tenantFindMany()` on a query whose correlated subqueries had
      their own `WHERE` clauses appearing before the outer one —
      the auto-scoping helper only replaces the *first* `WHERE` in
      the string, so it would have landed inside a subquery instead
      of the outer query, a real cross-tenant leak. Both fixed before
      packaging; a sweep across every other template and this bug
      class's other known instance confirmed nothing else was
      affected.

**Hotfix (discovered via a production log the client sent after
deploying 12.3)**: `ProductRepository.php` imported `SuperAdminContext`
and `OrganizationContext` from `App\Infrastructure\Security\...`, but
both classes actually live in `App\Infrastructure\Repositories\...`
— a wrong-namespace mistake that only surfaced at runtime (PHP has no
compile-time import checking), breaking `/finance/products` with a
500. Fixed immediately upon report; swept the whole repo for the same
wrong namespace and found no other instance.
- [x] **12.4 — Proforma**: `proformas`/`proforma_items` tables
      (named "Proforma" directly, matching the client's own
      terminology, rather than the architecture doc's generic
      "Quote"). Free, unrestricted CRUD by the creating user — no
      approval gate — modeled as `quotes.*` permissions at OWN scope
      for `member` and ORGANIZATION scope for `org_admin`, reusing
      the existing generic OWN-scope check in `AuthorizationEngine`
      (the `proformas` table uses `owner_id`, matching the same
      column name Contacts/Companies/Leads/Deals already use, instead
      of a one-off `created_by_user_id`). Dynamic line items (product
      picker or free-text line, quantity, unit price, per-line
      discount), live tax calculation on save via the `TaxCalculationService`
      built in 12.2. A Proforma becomes read-only once converted to
      an Invoice (`converted_invoice_id` set) — not a permission
      restriction, just "this is now historical"; the actual convert
      action itself is 12.5's job. **Two more real bugs caught before
      shipping**, same bug classes as before: (1) a stray nonsensical
      comparison (`'Won' === ...`) left over from drafting the detail
      page's tax-total label — fixed before the file was ever
      written; (2) `ProformaRepository::search()`'s very first draft
      already applied the correlated-subquery fix from the start
      this time, so no regression there.
- [x] **12.5 — Invoice**: `invoices`/`invoice_items`/
      `invoice_number_series` tables, plus the FK from
      `proformas.converted_invoice_id` to `invoices` (couldn't exist
      until this table did). Status machine for this phase: Draft →
      Issued → Cancelled (Paid/Partially Paid/Overdue wait for
      Payments in 12.6, since those statuses are meaningless without
      a payment concept yet). Permissions: `invoices.delete` was
      missing from the original catalogue — added. `member` gets
      view/create/edit/delete at OWN scope (can freely manage their
      own **drafts**); only `org_admin` gets `invoices.issue` and
      `invoices.cancel` — the client's explicit approval requirement.
      Invoice numbering is atomic and row-locked (`SELECT ... FOR
      UPDATE` inside a transaction), the same pattern already proven
      for Wallet's balance updates — verified the connection really
      is shared across repository instances (a static singleton in
      `Connection::pdo()`) so the transaction genuinely wraps both
      the series-allocation and the invoice-update queries together.
      Once Issued, an invoice is immutable in this phase — no edit
      path reaches an issued invoice; a correction is a Credit Note
      (12.7), not built yet. **Direct Proforma → Invoice conversion**
      is the client's stated priority: one action, copies the header
      and every line item exactly as they stood, no separate "Order"
      stage — the new invoice still starts as a Draft and still needs
      separate org_admin approval before it's official. **Two more
      real bugs caught before shipping**: (1) another leftover
      nonsensical comparison from drafting, caught before the file
      was ever written, same as 12.4's; (2) a more serious one — the
      invoice detail page's first draft gated the Issue/Cancel
      buttons on `CurrentUser::user()['is_org_admin']`, a field that
      does not exist anywhere in this codebase, which would have
      silently hidden the approval buttons from everyone, including
      real org admins. Fixed by having the controller properly
      compute `canIssue`/`canCancel` via `AuthorizationEngine::can()`
      and pass them to the view, instead of the template guessing at
      a user field — a repo-wide sweep confirmed no other reference
      to that fake field exists.
- [x] **12.6 — Payments**: `payments` table, manual recording only
      (cash/bank transfer/card/other) — matches the reality that
      there's no customer portal yet (see the DEFERRED note above):
      an internal staff member always records the payment on the
      customer's behalf, never the customer themselves. A payment
      has no `owner_id` of its own — its OWN-scope authorization
      follows its **invoice's** ownership, the same reasoning
      Ticket replies/Task comments use for their parent record.
      `paid_total` is updated atomically (row-locked invoice
      re-read inside the same transaction as the payment insert),
      so two payments recorded for the same invoice in quick
      succession can never both pass the outstanding-balance check
      against a stale read. Payment status (unpaid/partial/paid) is
      derived from `paid_total` vs `grand_total` at read time —
      deliberately not stored, so it can never drift out of sync
      with the real numbers. Payments only live within the invoice
      detail page (no separate nav item) and only appear for
      **issued** invoices — a draft has nothing to pay yet.
      `payments.refund` is deliberately not granted this phase;
      refunds are 12.7's job, alongside Credit Notes. No new bugs
      caught this round — the correlated-subquery and fake-field
      checks from 12.3–12.5 are now a standing habit applied from
      the first draft of every new repository/controller, and the
      sweep confirmed it held.
- [x] **Hotfix — payment-recording form layout jumbled**: reported
      by the client via screenshot (labels and inputs appeared
      side-by-side and visually offset by one position — a date
      showing under the "Notes" label, a reference placeholder
      showing under "Payment date", etc.). Root cause: the exact
      `card--form`-on-`<form>` mistake this project's own
      conventions document explicitly warns about ("must go on a
      WRAPPER element, never on the `<form>` tag itself — real bug,
      fixed twice") — the payment-recording form's `<form>` tag
      carried the `card--form` class directly, but the actual CSS
      rule (`.card--form form { display: grid; ... }`) only applies
      when `card--form` is on a *wrapper* with the real `<form>`
      nested inside it. With no wrapper, the grid-stacking rule
      never matched and the form fell back to default inline flow.
      Fixed by wrapping in a plain `<div class="card--form">` with
      the class removed from the `<form>` itself; swept every other
      template in the project afterward and confirmed this was the
      only instance.
- [x] **Hotfix — currency not defaulting on new Proforma/Invoice**:
      reported by the client via screenshot (org set to Rial in
      `/finance/settings`, but a newly created invoice showed EUR).
      Root cause: neither `ProformaController::form()` nor
      `InvoiceController::form()` passed the org's
      `base_currency_id` as a fallback for the currency `<select>`
      on a brand-new document — with no option marked `selected`,
      the browser silently picks whichever currency happens to be
      first in the list, regardless of the org's actual setting.
      Fixed by fetching the financial profile in both controllers
      and using `base_currency_id` as the fallback, the same pattern
      already correctly in place for `tax_profile_id`.
- [x] **12.7 — Credit Notes**: `credit_notes`/
      `credit_note_number_series` tables — own independent
      numbering, never touches `invoices.grand_total` (stays frozen
      forever per the immutability rule); "what's really owed" is
      derived at read time as `grand_total - total_credited`, the
      same pattern as 12.6's payment status. `credit_notes.*`
      permissions are **org_admin-only** — the same trust level as
      issuing an invoice in the first place. Atomic, row-locked
      numbering and a re-validated cap inside the same transaction
      (two credit notes issued in quick succession can't both pass
      a stale check). `PaymentService` updated so a payment's
      outstanding-balance check and `paymentStatus()` now both
      account for any credit notes, not just `grand_total` on its
      own. **A reasoning error caught before shipping**: the first
      draft capped a credit note at
      `grand_total - credited - paid`, which would have made it
      impossible to credit an invoice that's already been paid in
      full — a legitimate "customer returned the item after paying"
      case. Fixed to cap against `grand_total - credited` only,
      independent of payment status (an actual money-back Refund
      workflow, separate from a Credit Note, is future work, not
      this phase). **The exact `card--form`-on-`<form>` bug from the
      hotfix above this entry was deliberately avoided** when
      building the new "issue a credit note" form — applied as a
      standing lesson, verified clean by the repo-wide regression
      guard.
- [x] **Hotfix — currency still showing EUR after the previous fix**:
      client reported the default-currency fix from the prior
      delivery didn't resolve the issue on their end. Investigated
      `CurrencyRepository::all()` and confirmed it orders by `code`
      alphabetically (EUR, IRR, USD) — exactly matching the observed
      symptom if `defaultCurrencyId` ever fails to resolve for any
      reason (e.g. a financial profile not yet saved, or a stale
      deployment). Rather than just re-asserting the same fix,
      hardened the `<select>` itself the way `/finance/settings`
      already correctly does: added an explicit blank
      "— Choose currency —" placeholder as the first option in both
      the Proforma and Invoice forms, so the browser can never
      silently fall back to picking an arbitrary currency — if no
      default resolves, the field now shows empty and (being
      `required`) forces an explicit choice instead of a wrong
      invisible one.
- [x] **Dashboard — three Finance count tiles**: Invoices, Proformas,
      Products, added to the main dashboard. Reuses each service's
      own existing `list()` scoping (the same OWN/ORGANIZATION split
      already used on the list pages) rather than a separate
      "is this an org admin" check — an org_admin naturally sees the
      organization-wide total and a member sees their own, with zero
      new permission logic needed. Wired via the same lazy,
      non-hard-dependency container pattern already used for every
      other optional-module dashboard tile (Wallet balance, open
      tickets, etc.) — `FinanceModule` now exposes `productService()`/
      `proformaService()`/`invoiceService()` getters alongside its
      existing ones.
- [x] **12.8 — Financial periods + basic reports**: `financial_periods`
      table (org_admin only — view/close/reopen). Once a period is
      closed, `InvoiceService::issue()`, `PaymentService::record()`,
      and `CreditNoteService::issue()` all now check
      `FinancialPeriodService::isDateLocked()` against their own
      document's date before writing — a single shared service
      consulted from all three write paths, not three separate
      implementations of the same rule. Basic report page
      (`/finance/reports`): invoice count, gross/net revenue, tax
      collected, total paid, outstanding, for a date range — with an
      explicit caveat note that it naively sums across currencies
      (real multi-currency reporting conversion is future work, not
      this phase). **A real bug caught before shipping**: while
      wiring the closed-period check into `CreditNoteService::issue()`,
      replaced a redundant `date('Y-m-d')` recomputation with the
      already-validated `$issueDate` variable — but the first edit
      forgot to add `$issueDate` to the surrounding transaction
      closure's `use()` clause (and accidentally dropped `$input`,
      which turned out to be safe since nothing else in that closure
      referenced it). Caught by a targeted sweep for this exact bug
      class across every Finance service's closures; confirmed the
      other two (`InvoiceService::issue()`, `PaymentService::record()`)
      were already correct.
## Deferred — not current priorities (explicit client instruction)

The four items below are **not cancelled**, just explicitly deprioritized
by the client as of 2026-10-02. Listed here so they aren't forgotten or
silently dropped, and so the numbering stays stable if/when they're
picked back up.

- [ ] 12.9 — Wallet integration (likely re-scoped once the deferred
      Customer Portal decision above is actually made — see the
      "DEFERRED — Customer Portal" section; as-is, this has very
      little real use since every payment is already recorded
      manually by staff, not through a Wallet)
- [ ] 12.10 — REST API (`/api/v1/finance/...`)
- [ ] 12.11 — Automated test suite
- [ ] 12.12 — Final security/hardening pass

## 12.13+ — reserved for whatever comes next

A new sub-phase was announced by the client (2026-10-02) but not yet
described — spec to follow. Per explicit instruction: **analyze first,
then wait for an explicit go-ahead before writing any code.** This
section will be filled in once the spec arrives and is reviewed.
