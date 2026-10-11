# Finance Stage 5.2 — audit findings L1–L5 (finalization)

Status: implemented and regression-verified on `crm_local` only. Production
has NOT been migrated or verified (see "Production" at the end).

| Finding | Subject | Result |
|---|---|---|
| L1 | Duplicate submission of expenses, payments, credit notes | Closed — migration 0023, submission keys |
| L2 | Monetary input precision | Closed — per-currency policy, refusal (never truncation) |
| L3 | Mixed bcmath scales / rounding of totals | Closed — scale 18 everywhere, totals at the currency precision; optional final invoice rounding (migration 0024) |
| L4 | No audit trail for payments, credit notes, period close / reopen | Closed — timeline events in the same transaction, permission-filtered |
| L5 | Period lock races | Closed — lock order + locking reads (local MySQL 8.4.3, InnoDB, REPEATABLE-READ) |

## L1 — submission keys (migration 0023)

`finance_submission_keys` with UNIQUE (organization_id, scope, submission_key).
Each create form carries a random 128-bit key; the key row is claimed first in
the same transaction as the record, then pointed at it. A repeat (double click,
retry, second process) waits for the first, then is answered with the
ORIGINAL record — no second record, no second audit event. A failed first
attempt rolls its key back, so a corrected retry with the same key succeeds.
Keys are never purged automatically (no retention policy approved).

## L5 — period locking

Lock order in every financial write: submission key → document row
`FOR UPDATE` → covering period rows `LOCK IN SHARE MODE`
(`FinancialPeriodRepository::isDateLockedForUpdate`) → number series.
`close()` / `reopen()` take only the period row `FOR UPDATE` and re-check the
status under it; period creation uses `overlappingForUpdate`. Deadlock (1213)
and lock-wait timeout (1205) become a "please retry" validation answer
(`LockConflict`), never a 500 and never an automatic retry. Relies on InnoDB
and REPEATABLE-READ gap locks.

## L2 / L3 — precision policy

| Value | Maximum decimals | Behaviour above the limit |
|---|---|---|
| Money (IRR) | 3 | refused (`finance.error.amount_precision`) |
| Money (other currencies) | `currencies.decimal_precision` | refused |
| Unit price | money decimals + 2 | refused (`line_unit_price_precision`) |
| Quantity | 4 | refused (`line_quantity_precision`) |
| Percentage discount | 4 | refused (`line_discount_precision`) |
| Fixed line discount / invoice discount | money decimals | refused; invoice discount also ≤ sum of lines |

- IRR is never rounded to a whole rial automatically. `currencies.decimal_precision`
  for IRR is 0 in the catalogue (display setting); the Finance policy overrides it to 3
  (`App\Infrastructure\Finance\CurrencyPrecision`, `Modules\Finance\Money`).
- "1,000" (comma grouping) is still accepted; scientific notation, malformed and
  negative values are refused (remediation P3, unchanged).
- Only *significant* decimals count ("1.500" has 1), so values read back from
  `DECIMAL(38,18)` columns are never mistaken for excess precision.
- Arithmetic: bcmath at scale 18 everywhere (was a mix of 8 and 10). Line totals are
  stored exactly (quantity ≤ 4 and unit price ≤ p + 2 decimals make the product exact).
  The subtotal and every tax line round to the currency precision; the grand total is
  built from those rounded parts — numerically identical to the previous total for
  every document that was valid before.
- Rounding mode: **half-up (away from zero)** — `RoundingService`, unchanged.
  `docs/architecture.md` §D3 says "round half to even"; that was never implemented.
  The implemented mode is kept on purpose (changing it would silently alter totals)
  and the architecture document now records the discrepancy.
- Display: `money($amount, $currencyCode, $fmt)` — IRR shows only its significant
  decimals (≤ 3), other currencies their fixed precision. Used by every Finance
  amount: invoice / proforma pages and lists, print and PDF, expenses, reports,
  project costs / budget / profit, contract and project billing panels,
  installments, ClientRecords summary / invoices tab / timeline, the contact
  related panel. Display never changes stored values and never sums currencies.
  (Wallet, SMS and Sales displays are outside Finance and unchanged.)

### Optional final invoice rounding (migration 0024)

- `financial_profiles.invoice_final_rounding` — organization default, **OFF**
  (Finance → Settings, behind `finance.settings.edit`).
- At issue, the issuer (`invoices.issue`, organization admins) can override it for
  that invoice; the form defaults to the organization setting and names the target
  precision (IRR 0 = whole rial, other currencies their configured precision).
- Snapshot on the invoice, written inside the issue transaction and never recomputed:
  `final_rounding` (1 / 0; NULL = draft or issued before this feature),
  `rounding_precision`, `unrounded_total`, `rounding_adjustment` (may be negative).
  `grand_total` holds the **final payable**.
- Because `grand_total` is the payable, the adjustment is counted exactly once by
  every existing consumer — payments and the outstanding cap, credit-note cap,
  payment status, print / PDF, ClientRecords and contact panels, project revenue
  and profit (profit = grand − tax, so the adjustment is revenue, not tax), and the
  per-currency report — without any change to those calculations.
- Shown as "Total before rounding / Rounding adjustment" above the grand total on
  the invoice page and in print / PDF, only when the invoice was rounded.
- No backfill: invoices issued before 0024 keep their values; NULL snapshot.

## L4 — financial audit events

Written with `TimelineService` in the SAME transaction as the record (if the event
insert fails, the record is rolled back with it — tested by holding a table lock on
`timeline_events` from a second connection):

| Event | Subject | Description (data) |
|---|---|---|
| `payment_recorded` | invoice | payment id, amount, method, date, paid before → after |
| `credit_note_issued` | invoice | number, amount, date, credited before → after |
| `period_closed` / `period_reopened` | financial_period | the period's dates; actor = the user |

Visibility: the invoice page shows a "Financial history" card with payment events
only for `payments.view` and credit-note events only for `credit_notes.view`; the
periods page shows the close / reopen history (`financial_periods.view`, also
enforced in `FinancialPeriodService::history`). No other page or API reads
`invoice` / `financial_period` timeline subjects (the contact, ClientRecords,
contract and project timelines read only their own subjects) — checked by a test.
A repeated submission (L1) writes no second event. Payments and credit notes still
cannot be edited or deleted.

## Tests

- `FinanceFinalizationTest` (15) + child `standalone/finance-finalization-child.php`.
- `FinanceIdempotencyTest` L1-5 now also asserts one audit event per repeated submission.
- Mutations FM1–FM9 (precision, IRR precision, override, payable, event write,
  event atomicity, history permissions ×2, display) are all detected.
- Children delete everything they write and restore the invoice / credit-note number
  series and the rounding setting (guarded: only when no document uses a higher number).

## Production

Not migrated, not verified. Before claiming production-safe period locking,
confirm on Production: InnoDB for the Finance tables, transaction isolation
REPEATABLE-READ, and the locking behaviour (the 14-scenario period-lock probe).
Migrations 0023 and 0024 must be deployed together with the code.
