# Credit-note visibility: Patch B

Status: **implemented (2026-10-10).** It is a separate patch from Patch A (invoice cancel) and from
Stage 2 (installments, not started).

## Rule

Credit-note **details** need `credit_notes.view` on the invoice, checked by
`AuthorizationEngine::can($user, 'credit_notes.view', $invoice)`. The details are:
- the list;
- each note's number, date, reason, amount and issuer;
- Total credited;
- Effective total / Payable, which is the grand total minus credits.

**Every user still gets these figures net of credits:**
- Outstanding / Remaining;
- the payment status (unpaid / partial / paid).

This is the accounting truth of the invoice and is not changed.

| Where | With `credit_notes.view` | Without |
|---|---|---|
| `InvoiceController::show` (data) | list, `totalCredited`, `effectiveTotal` | `creditNotes = []`, `totalCredited = null`, `effectiveTotal = null`. Only the yes/no `canCreditMore` is passed. |
| Invoice page (HTML) | "Credit notes" section with the list; Total credited and Effective total rows | no list and no rows; the section only appears if the user may *create* (see below) |
| Print view and PDF (`DocumentPrintService::invoice()`, same template for both) | Credited row, Payable row, payable in words | no Credited or Payable row; the grand total row and the grand total in words instead; Paid and Remaining as before |
| Proforma print/PDF | unchanged | unchanged |

**`credit_notes.view` and `credit_notes.create` stay independent:**
- **create without view:** the issue form is shown, without the `max` amount cap, which would reveal the
  effective total. There is no list. The route and service accept the user as before.
- **view without create:** the list is shown with no form. The create route returns 403 as before.

Unchanged:
- Core, schema, migrations and permission definitions;
- the period report;
- `PaymentService`, `CreditNoteService` and `InvoiceService`.

`DocumentPrintService` gets `AuthorizationEngine` as an optional last constructor argument. Without it,
credit details are hidden (fail closed). `FinanceModule` passes it.

## Residual risks (out of scope, not changed)

- **The outstanding amount implies the credited total.** Grand total, Paid and Outstanding are all
  visible, so a user without the permission can work out Grand − Paid − Outstanding. This is inherent
  to the decision that outstanding stays net of credits.
- **A create-only user can learn the creditable amount** in two ways:
  - from `CreditNoteService`'s over-limit error message;
  - from their own success flash, which shows the new note's number.
  `CreditNoteService` was not to be changed.
- **The Finance period report** (`finance.reports`) still shows the period's total credited to anyone
  with report access.

## Tests

`tests/lite/CreditNoteVisibilityTest.php`, 15 tests, run against crm_local in one rolled-back
transaction. Users with an exact permission set come from a temporary role.

**What it covers:**
- **Page:** admin view; no view; fully credited → paid; create-only; view-only.
- **Print / PDF:** the print HTML; the real PDF text, extracted from the mPDF output; the print view model.
- **Proforma** print/PDF unchanged.
- **Access:** own-scope denial on page, print and PDF; organization isolation; all five languages;
  permission independence.
- **Controller data:** captured with a throw-away theme, so a test fails even if only the controller
  passes the data and the template would hide it.

**Results:**
- On the unpatched files, 8 of 15 fail. The other 7 guard behaviour that must not change.
- 11 mutation checks, all caught:

  | Mutation | Failing tests |
  |---|---|
  | controller check always true | 5 |
  | controller passes data, template guard kept | 1 |
  | template section guard removed | 3 |
  | template totals guard removed | 4 |
  | form cap always rendered | 1 |
  | print service check removed | 4 |
  | print template "Payable" label | 3 |
  | module wiring without authorization | 2 |
  | outstanding without credits | 4 |
  | payment status without credits | 1 |
  | print remaining without credits | 3 |
