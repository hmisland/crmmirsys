# Invoice cancel with payments — design note

Status: **Patch A implemented (2026-10-10).** Decisions: A-1 a reason is required for issued
invoices and recorded in the timeline with user and time; A-2 draft cancel is kept as it was; A-3
the invoice page shows the cancellation history and a warning for a cancelled invoice that has
payments.

**Rules now in `InvoiceService::cancel()`:**
- An **issued** invoice can be cancelled only when all of these hold:
  - it has no payment (`paid_total = 0`);
  - it has no credit note, even when its balance is 0;
  - its issue date is in an **open** financial period (`FinancialPeriodService::isDateLocked`);
  - a reason is given (at most 450 characters).
- The checks are repeated on the row locked `FOR UPDATE`.
- Every cancel writes a timeline entry (subject `invoice`, type `invoice_cancelled`).

**Unchanged:** drafts cancel as before. No migration, Core, permission, payment or credit-note change.
Old data is never altered: no automatic correction, no refund.

**Tests:**
- `tests/lite/InvoiceCancelHardeningTest.php`: 17 tests. 12 of them fail on the unpatched files; the other 5 guard behaviour that had to stay the same.
- Mutation checks: 8 of 9 caught by the lite suite. The suite does not catch #7, the re-check on the locked row: it runs in one process inside one rolled-back transaction, so no second connection can hold the row lock.

**Mutation #7: real concurrency probe (2026-10-10).** `tests/concurrency/invoice-cancel-lock-probe.php` runs
outside the lite suite and only against crm_local. Each scenario works like this:
1. It commits a temporary issued invoice.
2. A second connection locks that row `FOR UPDATE` and records a payment or credit note on it, as
   PaymentService / CreditNoteService do.
3. A real `cancel()` starts in a separate PHP process. It passes the pre-check, which still sees the
   committed state, then waits about 2 s on the lock.
4. The second connection commits.

Every temporary row is deleted afterwards.

| Scenario | Patch A as shipped | Mutation #7 (re-check removed) |
|---|---|---|
| no concurrent change | cancelled | not run (same path) |
| concurrent payment | **refused** (has payments) | **cancelled with paid 100**: caught |
| concurrent credit note | **refused** (has credit note) | **cancelled with a credit note**: caught |

The results were the same across 3 repeated runs, with a lock wait of about 1.9 s each time.
- **Why the credit-note case works in production:** the pre-check runs before the transaction. Under
  REPEATABLE READ the first consistent read inside the transaction therefore comes after the lock is
  granted, so the credit-note read sees the committed row.
- **Limitations:**
  - The probe is not part of `tests/lite/run.php`, because it commits (and then deletes) rows and takes
    about 6 s.
  - It covers MySQL/InnoDB at REPEATABLE READ (crm_local's setting).
  - Run it by hand after any change to `InvoiceService::cancel()`, `PaymentService` or `CreditNoteService`
    locking.

The analysis below is kept for reference. Patch B (credit-note visibility) is implemented; see
`docs/credit-note-visibility.md`.

## 1. What the code does today

`Modules\Finance\Services\InvoiceService::cancel()`:

| Aspect | Behaviour |
|---|---|
| Permission | `invoices.cancel` on the record (granted to `org_admin` only by default) |
| Allowed from | any status except `cancelled`, i.e. **draft and issued** |
| Checks `paid_total` | **no**: an invoice with recorded payments can be cancelled |
| Checks credit notes | no |
| Checks the financial period | **no**: unlike issue, payment and credit note, cancel is not blocked in a closed period |
| Records a reason / who / when | **no**: only `status = 'cancelled'` is written; no timeline entry |
| Reversible | no (no "uncancel") |

Other paths:

- **There is no "void" anywhere in Finance.** Payments have no delete or reverse path. Credit notes
  have no void. Refunds are not implemented (`payments.refund` is defined but unused).
- After cancel, new payments and credit notes are refused (both require `status = 'issued'`). The
  invoice's existing `paid_total` and credit notes **stay as they are**.

## 2. Effects of the current behaviour

| Area | Effect of cancelling a paid invoice |
|---|---|
| Payments | The money stays recorded on the invoice (`paid_total`), but the invoice is out of every total. |
| Contract Billing summary (Stage 1) | Excluded from billed, credited, paid and receivable. Its `paid_total` is shown in the separate warning "Payment recorded on a cancelled invoice". |
| Finance period report | `summaryForPeriod()` excludes cancelled invoices (`status = 'issued'` only), **but `CreditNoteRepository::totalForPeriod()` still subtracts their credit notes**, an inconsistency. The report also sums across currencies. |
| Financial periods | A cancel can change what a closed period effectively reports, because it is not period-locked. |
| Future installments | Without a rule, an installment whose paid invoice is cancelled would look "unpaid" and could be invoiced again (double billing, lost payment). |

## 3. Options

| Option | Change | Effect on payments / credit notes / reports / periods / existing data | Risks |
|---|---|---|---|
| **A. Keep as is** | none (Stage 1 warning only) | none | money silently outside reports; installments need a "needs review" state |
| **B. Refuse cancel when `paid_total > 0`** | `cancel()` returns 422 "record a credit note instead" | Existing cancelled invoices are untouched and stay visible through the Stage 1 warning. Correction goes through credit notes, as Finance already does for issued invoices. Reports become consistent for new data. | Changes how org admins work today; needs a clear message and a help text. |
| **C. B + period lock on cancel** | B + refuse cancel when the invoice's issue date is in a closed period (`FinancialPeriodService::isDateLocked`) | as B; closed periods can no longer change through cancel | same as B; still no recorded reason |
| **C+. C + reason and timeline** | C + required reason, timeline entry, `cancelled_at` / `cancelled_by` | full audit trail | needs new columns (a Finance module migration) and a form field: a separate decision |
| **D. Auto-refund on cancel** | create refunds when cancelling | needs a refund workflow that doesn't exist | rejected: large, accounting-level, out of scope |

## 4. Edge cases to decide with B / C

- An invoice paid and then **fully credited** (`paid_total > 0` but nothing owed, refund due > 0). Should
  cancel still be refused? Recommended: **yes**. The payment exists and the refund question stays open.
- **Draft** invoices can never have payments, so cancelling them stays allowed.
- An **issued invoice without payments** stays cancellable (subject to the period lock in C).

## 5. Tests required for B / C

1. Cancel a draft → allowed.
2. Cancel an issued invoice with `paid_total = 0` → allowed.
3. Cancel an issued invoice with a partial payment → 422, no change (status, `paid_total`, credit notes).
4. Cancel an issued invoice that is paid and fully credited → 422 (per §4).
5. (C) Cancel when the issue date is in a closed period → 422.
6. Another organization's invoice → 404. Without `invoices.cancel` → 403.
7. Regression: payment, credit note, the period report and `ContractCreditEffectTest` unchanged.
8. Mutation: remove the `paid_total` condition; remove the period check.

## 6. Recommendation

**Option C** (refuse when `paid_total > 0` + period lock) as a small separate Finance patch **before
Stage 2 (installments)**. C+ (reason / timeline, schema) and the period-report fixes (credit notes of
cancelled invoices, cross-currency sums) are separate decisions.

Related access issue (**fixed by Patch B**, see `docs/credit-note-visibility.md`): `InvoiceController::show` listed an invoice's credit
notes and its credited total to anyone who can view the invoice, without `credit_notes.view`.
**Proposed separate fix:** pass the credit-note list and amounts only when the user holds
`credit_notes.view`, and keep showing the outstanding amount (it is a property of the invoice). Tests:
a member with `invoices.view` but without `credit_notes.view` sees no credit-note rows; an org admin does.
