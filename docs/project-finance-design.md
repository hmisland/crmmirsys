# Project Financial Management + Contract Obligations — audit and design

Status: **design final (2026-10-10). Stages 1, 2, 3-A, 3-B, 3-C (C1 + C3), 4 (Expenses, E1–E12 + D-P1–D-P3), 5 (Budget, profit, margin) and 5.1 (expense project allocation + contract reference) implemented; no later stage started.** Each stage
needs its own approval. Stage 2 decisions D-S2-1 … D-S2-9 (below) supersede §11–§13, §18 and §21
where they differ.

## 0. Final decisions (approved; they supersede the open questions in §29)

| Topic | Decision |
|---|---|
| **D1 Permissions** | Four keys, owned by Finance and registered through `Permission::registerModuleKeys('finance', …)` with no Core change: `finance.expenses.view`, `finance.expenses.manage`, `finance.budgets.view`, `finance.budgets.manage`. Default grants: org_admin all four at organization scope; member none. The grant migration targets only `roles.key = 'org_admin' AND is_system = 1 AND organization_id IS NULL`, with `INSERT IGNORE`, so custom roles are not touched (tested). Installments reuse `invoices.view` / `invoices.create`. |
| **Document attribution** | Route 1, two steps, Finance only: create from the contract page (contract link); attach or detach the project from the project's Finance panel **while the document is a draft**. An invoice's links are **frozen at issue** (no change, no removal); a proforma's links can change until it is converted. Conversion copies **all** links. Correction after issue goes through a credit note plus a correctly attributed new invoice. Revenue is never derived through `project_links`. |
| **Expense** | Attributed to a Project only; no approval in this phase; no hard delete (void with a reason); editable and re-attributable while its date is in an open financial period, with a timeline entry; after the period closes, correction only through a new entry in an open period. **Detailed and superseded by "Phase 4 — Expenses: approved design" (E1–E12).** |
| **Revenue** | Project revenue = issued invoices, excluding tax, minus valid credit notes in the invoice's own net/gross proportion, per currency. Payments are not revenue; they drive Paid / Receivable. |
| **Currency** | No cross-currency sum or profit. D6 (base-currency conversion) rejected for this phase. |
| **Out of scope now** | Contract value on the project page, committed cost, charts. |
| **Order** | 1. credit-note effect in the contract summary → 2. installments → 3. project attribution and revenue → 4. expenses → 5. budget, profit and margin. |
| **Cancel with payments** | Patch A done (2026-10-10): an issued invoice with any payment or credit note, or dated in a closed period, can't be cancelled; a reason is required and recorded in the timeline. See `docs/invoice-cancel-note.md`. Patch B done (2026-10-10): credit-note details on the invoice page, print and PDF need `credit_notes.view`; see `docs/credit-note-visibility.md`. Stage 2 (installments) done 2026-10-10, see the Stage 2 section; it uses migration 0017, not 0020 as in §20. |

## Stage 1 — credit-note effect in the contract Billing summary (implemented 2026-10-10)

Per **issued** invoice, in its own currency, bcmath at 18 decimals; per-currency totals only:

| Figure | Formula |
|---|---|
| Credited | Σ credit notes of the invoice |
| Net billed | Grand total − Credited |
| Receivable (`balance`) | max(0, Grand total − Credited − Paid) |
| Refund due | max(0, Paid − (Grand total − Credited)), shown only when > 0 |
| Payment on a cancelled invoice | Σ `paid_total` of cancelled invoices: a separate warning, explicitly "not valid revenue", shown only when > 0 |

**Exclusions:** drafts are never counted. Cancelled invoices are out of Billed, Credited, Paid and Receivable.

**Access:**
- Only invoices the user may view (`InvoiceService::find()`: permission and record scope) are summed.
- Credits are read per such invoice through the tenant-scoped repository.
- Credited and Net billed are shown only with `credit_notes.view`. Without it, Receivable and Refund due still account for credits; no credit-note amount or label is rendered.

**Unchanged:** no Finance rule (payment, credit note, invoice, cancel, tax, periods), no Core, no migration, no permission.

**Files:**
- `modules/Finance/Services/ContractBillingService.php`;
- `themes/default/templates/finance/_contract_panel.php`;
- `lang/*.php` (5 keys + corrected `finance.billing_hint`);
- `tests/lite/ContractCreditEffectTest.php` (new).

**Tests:**
- 17 new tests;
- full suite 413 / 413;
- 16 of 17 fail on the unpatched files. The rollback test is the exception. Some fail there partly because the new fields don't exist;
- 10 mutation checks, all caught.

**Known at the time (both fixed since):**
- `InvoiceController::show` showed credit notes without `credit_notes.view` (fixed by Patch B);
- cancel was allowed on paid invoices (fixed by Patch A).

## Stage 2 — contract installment schedule (implemented 2026-10-10)

**What an installment is.** A planned amount and due date on a contract, owned by Finance. It is
**not** an invoice, receivable, revenue or payment, and it never feeds the Billing summary, the period
report or any other total.

| Concept | Source | Financial effect |
|---|---|---|
| Contract value | `contracts.value` (optional) | none; shown as a comparison only |
| Installment | `finance_installments` | none |
| Proforma / draft invoice from it | linked document | none |
| Issued invoice | `invoices` | revenue / receivable, existing rules |
| Payment | `payments` / `paid_total` | cash, existing rules |
| Receivable | Stage 1 summary | unchanged; installments never added |

**Data:** one new table, **Finance migration `0017_create_finance_installments_table`**, additive.
- `linked_type` = 'contract', `linked_id` (no FK; Contracts may be absent);
- `sequence`, `due_date`, `amount DECIMAL(38,18)`, `currency_id`, `description`;
- `voided_at`, `voided_by_user_id`, `void_reason`;
- created/updated by, timestamps;
- UNIQUE `(organization_id, linked_type, linked_id, sequence)`.

A document is tied to an installment by a second `finance_document_links` row (`linked_type =
'installment'`) next to its `contract` link. That column is a VARCHAR, so no migration was needed for it.

**Derived status** (only `void` is stored). It comes from the one *active* document: a proforma not
converted, or an invoice not cancelled.
- **unpaid:** no active document, or only a proforma / draft invoice;
- **issued:** issued invoice, nothing paid;
- **partially_paid;**
- **paid:** outstanding = grand − credited − paid ≤ 0, the same figures as the invoice page. A fully
  credited invoice therefore counts as paid, meaning nothing is left to collect.

`overdue` is a flag, computed on read.

**Approved decisions (as implemented):**

| # | Rule |
|---|---|
| D-S2-1 | **View:** Finance + `invoices.view` + the contract through `RecordTypeRegistry::find()`. **Create / edit / void:** `contracts.edit` on the contract record (scope), plus `find()`. `contracts.edit` already governs non-term changes on a contract in any status, such as its links. **Documents from an installment:** `quotes.create` / `invoices.create`, as for any document, plus the installment and contract checks. No permission was added or changed. |
| D-S2-2 / D-S2-5 | **Create:** contract `active` only. **Edit:** contract `active`, installment open. **Documents:** contract `active` or `expired`. **Terminated:** nothing new; void is still allowed. **Void:** any contract status, no active document. The status reading is ContractService's own `effectiveStatus()` rule (an `active` contract past its end date is `expired`), pinned by a parity test. |
| D-S2-3 | **Currency:** the contract's; otherwise that of the schedule's non-void installments; otherwise the first one's (must be active). No conversion; totals per currency. Two concurrent "add" requests compute the same sequence number, so the second fails on the UNIQUE key: one currency only. |
| D-S2-4 | **Document vs installment:** another currency → review warning, and amounts are not compared. Same currency, other amount → both amounts shown with a review warning. Money figures always come from the document itself. |
| D-S2-6 | **Linked documents:** resolved through `ProformaService` / `InvoiceService::find()`. Without access the row shows only "A linked document exists, but access is restricted": no number, amount, currency, status, overdue or review flag. No credit-note figure is shown in the schedule (Patch B). |
| D-S2-7 | **Periods:** no financial-period lock on installments. Documents keep every existing period rule. |
| D-S2-8 | **Conversion:** `InvoiceService::createFromProforma()` runs in one transaction whose first statement locks the proforma row (`ProformaRepository::lockForUpdate`) and re-checks "already converted". It copies the `contract` **and** `installment` links. Nothing else in the conversion changed. |
| D-S2-9 | **Void:** reason required (≤ 450 characters, as for invoice cancel). Refused with an active document. Recorded in the contract timeline. |

**Concurrency.**
- **Document from an installment:** runs in one transaction. The first statement locks the installment
  row; the installment is then re-checked (not void, no active document) before the document, both
  links and the timeline entry are written. Edit and void take the same lock.
- **Probe:** `tests/concurrency/installment-lock-probe.php` (two connections, two processes, crm_local
  only) checks doc/doc, void/doc, edit/doc, convert/convert and add/add in two currencies. Every
  temporary row is removed afterwards.

**Routes** (`/finance` group: Auth + Finance module):

| Route | Middleware |
|---|---|
| `POST /contracts/{id}/installments` | `RbacMiddleware('contracts.edit')` + CSRF |
| `GET /installments/{id}/edit` | `RbacMiddleware('contracts.edit')` |
| `POST /installments/{id}` | `RbacMiddleware('contracts.edit')` + CSRF |
| `POST /installments/{id}/void` | `RbacMiddleware('contracts.edit')` + CSRF |
| `GET /{invoices,proformas}/create?from_installment=N` | as before |
| `POST /{invoices,proformas}` with `source_type=installment` | as before |

**UI:** a "Payment schedule" section in the contract Billing panel, and an edit / void page. Timeline
entries on the contract: `installment_created`, `installment_updated`, `installment_voided`, and
`{proforma,invoice}_created` "… from installment #k".

**Not done (out of scope):**
- automatic installment generation, scheduled invoicing, automatic void on termination;
- an "unbilled" total;
- any change to Contracts, Core, permissions, Payment / CreditNote services, Patch A / Patch B logic or reports.

**Tests:**
- `ContractInstallmentsTest` (24 tests);
- full suite 469 / 469;
- 22 mutation checks: 20 caught by the suite. M21 (lock-time re-check) and M22 (conversion re-check)
  need real concurrency and are caught by the probe, together with M23 and M24 (row locks removed).

## Stage 3-A — Contract ↔ Project association (implemented 2026-10-10)

**Audit finding:** the association already existed. It lives in the Projects module's `project_links`
(migration 0004, `linked_type = 'contract'`, UNIQUE `(project_id, linked_type, linked_id)`, tenant-scoped,
FK to projects with CASCADE). It is managed by `ProjectService::link/unlink/createLinked` and shown on
the contract page by `ProjectPanels`. Stage 3-A **hardens it; it doesn't rebuild it**.
- No new table or migration.
- No Finance, Contracts, Core or permission-definition change.
- The association has **no financial effect**.

**Approved decisions (Q1–Q9), as implemented:**

| # | Rule |
|---|---|
| Q1 | N:N kept: a contract has many projects, a project has many contracts. |
| Q2 / Q3 | `project_links` stays the source of truth. Changes are in Projects only. |
| Q4 | **Link, unlink and `from_contract`** need `projects.edit` on the project **and** the contract module's edit permission (`contracts.edit`) on the contract, both with record scope. The key is derived from the contract RecordType's registered `viewPermission` (`<module>.view` → `<module>.edit`), so Projects still never names another module. The architecture test `ProjectsRecordTypeTest` forbids that. If the key can't be derived, the check fails closed. A link to a contract that **no longer exists** can be removed with `projects.edit` alone. |
| Q5 | **New link:** contract draft, pending_approval, active or expired. Refused when terminated or cancelled (`projects.error.closed_contract`). Unlink in any status. |
| Q6 | **New link:** project draft, planned, active or on_hold. Refused when completed or cancelled (`projects.error.closed_project`). Unlink in any status. Deal links are unchanged. |
| Q7 | **Link is idempotent**, including under concurrency: a duplicate-key error from the UNIQUE index is caught and the existing link is returned. There is no second row and no second timeline entry. **Unlink** writes the timeline only when this request deleted the row (`deleteLink()` returns the count). |
| Q8 | **Timeline:** the project-side events (`linked` / `unlinked`) are kept. The contract side gets `project_linked` / `project_unlinked` (Core TimelineService, actor and project named). |
| Q9 | **Contract page:** "Unlink" per project in the Projects panel (existing route, CSRF, `data-confirm`), shown only when allowed. The redirect returns to the contract page only if `return_to` equals the linked record's own URL, so there is no open redirect. "New project" and "Link existing" are hidden when not allowed; completed and cancelled projects are not offered. **Project page:** Unlink is hidden for a contract link without `contracts.edit`. |

**Changed files:**
- `modules/Projects/Services/ProjectService.php`
- `modules/Projects/ProjectLinkRepository.php` (`deleteLink` returns the row count)
- `modules/Projects/ProjectPanels.php`
- `modules/Projects/Controllers/ProjectController.php` (unlink `return_to`)
- `themes/default/templates/projects/_panel.php`
- `themes/default/templates/projects/show.php`
- `lang/*.php` (2 keys)

**Tests:**
- New suite `ContractProjectLinkTest` with 21 tests. 10 of them fail on the pre-Stage-3-A code; the other 11 guard behaviour that must not change.
- `ProjectLinksTest`: the "member (own edit)" test's contract is now owned by the member, because Q4 requires `contracts.edit`, which members hold on their own contracts only. Every original assertion is kept, and an assertion was added that the admin's contract is refused.
- Full suite 490 / 490.

**Mutations:** 18 in total, all detected.
- 15 by the suite.
- The two race-only mutations (unlink timeline correctness, duplicate-key handling) by the probe `tests/concurrency/project-link-probe.php`.
- The DB uniqueness mutation by both.

---

## Stage 3-B — Project revenue (implemented 2026-10-10)

**Source of truth:** issued invoices *attributed* to the project. The attribution is a
`finance_document_links` row with `linked_type = 'project'`. The existing UNIQUE
`(organization_id, document_type, document_id, linked_type)` makes it **one project per invoice**, so
there is no allocation and no double counting.
- No new table and no migration.
- Revenue is **never** derived from the contract value, installments, payments, proformas, drafts,
  cancelled invoices or `project_links` (the Stage 3-A association), and never by traversing a contract.

**Figures:** per invoice currency, with no conversion and no cross-currency total. They cover the issued
invoices the user may view (`invoices.view` + record scope, the Stage 1 pattern). Restricted invoices are
neither listed nor summed.

| Figure | Rule (per invoice, then summed) | Shown |
|---|---|---|
| Invoiced (incl. tax) | `grand_total` | `invoices.view` |
| Credited | Σ its own credit notes | `credit_notes.view` |
| Revenue (incl. tax) | gross − credited (D-B1 = R1; no tax-proportional formula) | `credit_notes.view` |
| Paid | `paid_total`; never reduces revenue | `invoices.view` |
| Receivable | max(0, gross − credited − paid) | `credit_notes.view` (security gate, option A) |
| Refund due | max(0, paid − (gross − credited)) | `credit_notes.view`, only when > 0 |

These are the same per-invoice rules as the contract Billing summary (Stage 1); no Finance service changed.

**Security gate, option A (approved 2026-10-10):** without `credit_notes.view`, the project panel shows
**only Invoiced (gross) and Paid**. Credited, Net revenue, Receivable and Refund due are neither computed nor
rendered. Receivable is withheld too, because Gross − Paid − Receivable would give the credited total (exactly,
when nothing is over-paid). Gross and Paid alone don't determine any credit amount. Users with the permission
see the same figures as before.

**Decision (i), the rest of the CRM, unchanged:** the invoice page (outstanding, payment status), print / PDF
(grand, paid, remaining) and the contract Billing panel (billed, paid, balance) still let a user without
`credit_notes.view` infer credits for invoices they may view. This is a known, accepted residual risk
(Patch B / Stage 1). It is **not** fixed CRM-wide; a separate patch would need its own audit, including the
payment form, which uses the outstanding amount.

**Attribution:**
- invoices only, not proformas (D-B5; `createFromProforma` unchanged);
- needs `invoices.edit` (+ `invoices.view`) on the invoice **and** `projects.edit` on the project, record scope, server-side;
- draft or issued invoices; cancelled ones are refused; completed or cancelled projects receive none;
- an invoice keeps its one project: attaching it to another project is refused, with no move;
- detach only while the invoice is a draft (D-B6); an issued attribution is fixed, and corrections go through a credit note;
- both run in one transaction whose first statement locks the invoice row (`InvoiceRepository::lockForUpdate`), the lock issue, payments, credit notes and cancel use;
- "already attributed?" is a locking read, so even a duplicate-key race resolves to idempotent success or a controlled refusal;
- **Timeline:** the project side records only that *an* invoice was attributed or detached, with no number or amount, because project viewers may not see the invoice. The invoice side names the project.
- No "create from project" (D-B7).

**UI:** a "Project revenue" panel on the project page (Finance, `RelatedPanelRegistry` subject `project`).
It shows the per-currency facts, the attributed invoices the user may view, an "Attribute invoice" select
of unattributed draft/issued invoices the user may edit, and "Detach" on drafts.
- Routes: `POST /finance/projects/{id}/invoices` and `POST /finance/projects/{id}/invoices/{invoiceId}/detach`, both with `invoices.edit` + CSRF.

**Changed files (Finance only):**
- new: `ProjectRevenueRepository.php`, `Services/ProjectRevenueService.php`, `Controllers/ProjectRevenueController.php`, `templates/finance/_project_panel.php`;
- modified: `FinancePanels.php`, `FinanceModule.php`, `lang/*.php` (16 keys).

**Tests:**
- `ProjectRevenueTest`: 20 tests, covering required items 1–35 plus attach / detach rules, credit visibility, five languages;
- full suite 510 / 510.

**Mutations:** 25 detected by the suite, including Receivable shown without `credit_notes.view` (in the service and in the template). With the probe `tests/concurrency/project-revenue-probe.php`:
- the row-lock removal and the detach-timeline mutation are detected;
- the duplicate-key `catch` is now an equivalent mutant, because the locking read already serializes the race. It stays as defense.

---

## Stage 3-C — new invoice from a project, attach-list order (implemented 2026-10-10)

**Scope (approved):** the remainder of the documented Stage 3 (§4, §10, §25), **C1 + C3 only**.
- **Excluded:** C2 (proforma attribution and project-link copy on conversion), since D-B5 stays in force and `InvoiceService::createFromProforma` is unchanged.
- No migration.

**C1 — "New invoice" from the project revenue panel.**
- **Flow:** `GET /finance/invoices/create?from_project=N` → the normal invoice form, prefilled with the project's contact, today's date, and one line named after the project, plus the "Created from project" notice and hidden `source_type=project` / `source_id`. `POST /finance/invoices` then creates a **draft**.
- **Rules (`Services/ProjectInvoiceService.php`):**
  - the project is re-read on every step: `find('project')` (else 404 / form error), `projects.edit` on it (403), and not completed or cancelled (refused);
  - `invoices.create` comes from the existing route and `InvoiceService::create()`;
  - the invoice, its project link and the timeline entries are written in **one transaction** (`FinanceDocumentLinkRepository::atomically`);
  - the project-side timeline says only "An invoice draft was created for this project", with no number, id or amount;
  - a draft is listed on the project but is revenue only once issued (Stage 3-B rules).
- **Controller:** only `InvoiceController::showCreate`, `create` and `form` changed. A `project` branch runs **before** the existing branch, so `from_contract` / `from_installment` are untouched. `show` / `cancel` (Patch A/B) are unchanged, and `ContractBillingService` is unchanged.
- **Panel:** "New invoice" is shown only with `invoices.create` + `projects.edit` on an open project.

**C3 — attach-list order.** `ProjectRevenueRepository::unattributedInvoices()` orders invoices of the project's contact first, then invoices of contacts of the project's company, then the rest, newest first in each group. Eligibility (draft or issued, unattributed, tenant-scoped, editable by the user) is unchanged, and `project_links` is not read.

**Files:**
- new: `Services/ProjectInvoiceService.php`;
- modified: `Controllers/InvoiceController.php` (three create methods), `FinanceModule.php` (wiring), `FinancePanels.php`, `ProjectRevenueRepository.php` (ORDER BY), `Services/ProjectRevenueService.php` (one call passes the project's contact / company), `templates/finance/_contract_source.php`, `templates/finance/_project_panel.php`, `lang/*.php` (2 keys).

**Tests:**
- `ProjectInvoiceTest`: 13 tests. 10 fail on the pre-3-C code; 3 guard behaviour that must not change.
- Full suite 523 / 523.
- 15 mutations, all detected. Atomicity is detected by the probe `tests/concurrency/project-invoice-atomic-probe.php`, which forces the link insert to fail and checks that no invoice survives.

---

## Phase 4 — Expenses: approved design (2026-10-10, NOT implemented)

Decisions E1–E12 were approved after the Phase 4 audit; the three former open points were decided as
D-P1–D-P3 (end of this section). **This section is final for expenses.** Where it
differs from §0 (Expense row), §5, §18–§21 or §25, this section wins (see "Conflicts resolved" at the
end). No code, migration or test exists yet; implementation needs separate approval.

### Purpose and boundaries

- An expense is an **actual cost** the company incurred. It is not revenue, not a commitment, and not a
  payment to or from a customer.
- Expenses are a separate figure. They never change invoice revenue, payments, credit notes, installments,
  contract values, the Contract Billing summary or Project Revenue (Stage 3-B / 3-C).
- **Out of Phase 4:** vendors (as an entity), purchase orders / committed cost, budgets, timesheets,
  profit and margin (Stage 5), approval workflows, a contract association.

### E1 — Project association: `project_id` on the expense (deviation from §5 / §20)

- **Model:** the expense table has its own nullable `project_id`. This is the **canonical** association.
  `finance_document_links` is **not** extended: no `expense` ENUM value, no migration on that table.
- **Why it deviates from §5 / §20:**
  - One column means **at most one project per expense** by construction.
  - The table that is the source of truth for Stage 3-B / 3-C stays untouched.
- **No FK:** Projects may be absent. The id is validated through `RecordTypeRegistry::find('project')`
  whenever it is set or changed.
- **Stage 5 rule:** profitability uses the expense's own `project_id` only. It never goes through a
  contract, `project_links` (Stage 3-A, N:N) or any other indirect path.

### E2 — No contract association

There is no `contract_id`, and expenses are never attributed or inferred through Contracts.

### E3 — Amount and tax (D4 / T1)

- **One amount:** positive, `DECIMAL(38,18)`, the company's **actual cost**.
- **What it includes:** it already **excludes recoverable tax** and **includes non-recoverable tax**.
- **No tax logic:** no tax is calculated and no rate is inferred. `TaxCalculationService` (sales-side) is not
  used. There are no regional tax rules in code.
- **User responsibility:** the amount is user-supplied and must reflect the applicable tax treatment. The
  form hint says so in all five languages.

### E4 — Payee (no Vendor entity)

- **Optional:** either `payee_contact_id` **or** `payee_company_id`. Each must be a contact / company
  of the same organization (tenant-scoped repositories, as `DocumentHeaderGuard` checks contacts).
- **Never both:** both set → 422.

### E5 — Categories

A fixed list (ENUM): `labor`, `procurement`, `contractor`, `shipping`, `services`, `other`. The future
budget lines (Stage 5) use the same list.

### Data model — `finance_expenses` (Finance migration 0018)

| Column | Purpose / validation |
|---|---|
| `id`, `organization_id` NOT NULL (FK organizations CASCADE) | tenant |
| `entry_type` ENUM(`expense`, `correction`) NOT NULL DEFAULT `expense` | an ordinary expense or a correction (E8) |
| `correction_of_id` INT UNSIGNED NULL (FK finance_expenses RESTRICT) | correction rows only: the **original** expense (E8) |
| `correction_direction` ENUM(`increase`, `decrease`) NULL | correction rows only: the **signed effect** (E8). NULL on ordinary expenses |
| `category` ENUM (E5) | required; a correction copies the original's |
| `description` VARCHAR(255) NOT NULL | required, trimmed |
| `reference_number` VARCHAR(100) NULL | the payee's receipt / bill number; optional, not unique |
| `expense_date` DATE NOT NULL | ISO; decides the financial period |
| `amount` DECIMAL(38,18) NOT NULL | always **> 0**, a magnitude (E3; for corrections see E8) |
| `currency_id` INT UNSIGNED NOT NULL (FK currencies) | must exist and be active when set; a correction copies the original's |
| `project_id` INT UNSIGNED NULL (no FK) | E1 / E7 / E9; a correction copies the original's |
| `payee_contact_id` NULL (FK contacts SET NULL), `payee_company_id` NULL (FK companies SET NULL) | E4; at most one |
| `owner_id` (FK users RESTRICT), `team_id` NULL, `department_id` NULL (SET NULL) | record scope, as on every Finance document |
| `status` ENUM(`recorded`, `void`) DEFAULT `recorded`, `voided_at`, `voided_by_user_id` (SET NULL), `void_reason` VARCHAR(500) | E11; reason required, ≤ 450 characters (same as invoice cancel / installment void) |
| `created_by_user_id`, `updated_by_user_id`, `created_at`, `updated_at` | audit |

**Keys:**
- KEY (organization_id, expense_date);
- KEY (organization_id, project_id, status);
- KEY (organization_id, status, category);
- KEY (organization_id, correction_of_id).

**Deliberately not included:**
- `source_type` / `source_id` (timesheet, §6): added additively by a future Timesheet phase.
- `base_amount` / `rate_used`: D6 (FX) was rejected.
- `contract_id` (E2).

**Row integrity (checked by the service; MySQL can't express all of it):**
- `entry_type = 'expense'` ⇔ `correction_of_id IS NULL` ⇔ `correction_direction IS NULL`.

### E8 — Corrections after a period is closed

- **No physical delete, ever.** Ordinary edit and void follow the period rules below.
- **When a correction is used:** to correct an expense whose `expense_date` is in a **closed** period.
  - A separate correction row is created, dated in an **open** period.
  - It has `entry_type = 'correction'`, `correction_of_id` = the original, and `correction_direction` = `increase` or `decrease`.
  - `amount` is the (positive) size of the change.
- **The original is never modified and never voided by a correction.**
- **No chains or cycles:**
  - `correction_of_id` must point to a row with `entry_type = 'expense'` (an original, never a correction), in the same organization, not itself.
  - The original must be `recorded`. A voided original can't be corrected.
  - Its date must be in a closed period; otherwise the ordinary edit is used.
- **Copied, not editable:** a correction copies the original's `currency_id`, `project_id` and `category`,
  and none of them is editable on the correction. Payee, description, reference number and date are its own.
- **Signed effect:**
  - ordinary expense or `increase` correction → `+amount`;
  - `decrease` correction → `−amount`.
- **Net of an original:**
  `original.amount + Σ(+amount of recorded increase corrections) − Σ(amount of recorded decrease corrections)`.
  Voided rows don't count.
- **Invariant: the net of an original is never negative.** It is checked:
  - on creating a correction;
  - on voiding a correction (voiding an `increase` lowers the net);
  - on editing an original that has corrections (possible only if its period is reopened).
- **Concurrency:** every one of those operations runs in one transaction. Its first statement locks the
  **original** row `FOR UPDATE`, then re-reads the original and its corrections and re-checks the invariant
  before writing. Two concurrent corrections or voids can't break the invariant.
- **Corrections are not editable.** To fix one, void it (its own date must be in an open period) and record
  a new one.
- **Visibility and counting:**
  - corrections are labelled as corrections in lists, on the detail page (under their original) and in the timeline;
  - every aggregation, now and in Stage 5, counts the **net of each original exactly once**: the original
    plus its recorded corrections, per currency, under the original's `project_id`.
- **When the original's period is open** (or reopened), the original may be edited normally, subject to
  the invariant if corrections exist. Its `currency_id`, `project_id` and `category` are then frozen while
  it has recorded corrections.

### Ordinary lifecycle and period rules (E11 + §0)

- **Statuses:** `recorded` → `void` only. There is no approval workflow (E11).
- **Create:** `expense_date` must be in an **open** period.
- **Edit** (amount, date, category, description, reference, payee, currency, project): only while
  `recorded`, the **current and the new** `expense_date` are in open periods, and E1 / E7 / E9 hold.
  Corrections are not editable.
- **Void:** only `recorded` rows, with a reason (≤ 450), and its `expense_date` must be in an open period.
  - A voided original is excluded from all totals and can't be corrected.
  - An original with recorded corrections can't be voided (its period is closed anyway; if reopened, its corrections must be voided first).
- **Locking:** every create, edit, void and correction runs in one transaction. The first statement locks
  the affected expense row (for corrections, the **original**) `FOR UPDATE`, then all rules are re-checked
  on the locked row. Period checks inside it use the locking read of D-P3. On a create, there is no row
  yet, so the period read is the first statement.
- **Correction permissions:** D-P2.

### E7 — Project linking authorization

- **Setting, changing or removing** an expense's `project_id` needs:
  - `finance.expenses.manage` on the expense (record scope);
  - `projects.edit` on the project (record scope);
  - when changing from one project to another, `projects.edit` on **both** projects.
- **Validation:** the project is read through `find('project')` (Projects available, `projects.view`,
  same organization, record scope). A missing or foreign id → 404 / 422 with nothing written.
- **All other identifiers** (expense, attachment, payee, currency, correction target) are validated on the
  server and tenant-scoped. Hidden fields are never trusted.

### E9 — Project status

- **New attribution:** allowed for `draft`, `planned`, `active`, `on_hold` and `completed` projects (costs
  can arrive after completion). Refused for `cancelled`.
- **Later status changes:** a project's status change after attribution never reassigns, voids or deletes
  existing expenses; they stay attributed and are counted as before.
- **Deleted projects:** a project can only be deleted while draft, planned or cancelled. If one is
  deleted, its expenses keep their `project_id`. With no FK, they show as "project unavailable" and drop
  out of any project panel. Nothing is deleted.

### E6 — Receipts and attachments

- **Not through Core:** the generic Core RecordSupport routes (`/records/{type}/{id}/attachments…`) are
  **not** used. As audited, they check only type view permission and the record's organization: no record
  scope, no manage permission, no status, no period.
  - `expense` is registered as a RecordType with **`recordSupport: false`**, so those routes return 404 for it.
  - Core RecordSupport itself is not changed.
- **Finance routes** use the existing `AttachmentService` (subject type `expense`):
  - **download** `GET /finance/expenses/{id}/attachments/{attachmentId}`: `finance.expenses.view` + record scope on the expense;
  - **upload** `POST /finance/expenses/{id}/attachments`: `finance.expenses.manage` + record scope + the expense is `recorded` + its `expense_date` is in an open period;
  - **delete** `POST /finance/expenses/{id}/attachments/{attachmentId}/delete`: the same as upload.
  - Upload and delete use CSRF and RBAC middleware like every Finance POST.
- **Ownership:** every request checks tenant isolation and that the attachment belongs to **that** expense
  (`AttachmentService::findForSubject`). Otherwise → 404.
- **Frozen receipts:** voided expenses and expenses in locked periods can't get or lose receipts.
  Corrections may carry their own receipts under the same rules.

### E12 — Permissions and migrations

- **Keys** (§0 D1, dotted form): `finance.expenses.view`, `finance.expenses.manage`.
  - Registered at runtime by `FinanceModule` through `Permission::registerModuleKeys('finance', [...])`.
  - `Permission.php` is **not** changed.
  - Budget keys (D1) are registered only in Stage 5.
- **Migrations** (Finance module; verified on 2026-10-10 that `0017_create_finance_installments_table` is the last one):
  - `0018_create_finance_expenses_table`: the table above;
  - `0019_grant_finance_expense_permissions`: both keys to the **system** `org_admin` role only (`key = 'org_admin' AND is_system = 1 AND organization_id IS NULL`), organization scope, `INSERT IGNORE` (the Contracts 0003 pattern). Custom roles are not touched; members get nothing by default.
  - Both get an up → down → up check on crm_local. Re-check the numbering before implementing.
- **Record scope:** `owner_id` / `team_id` / `department_id` with `AuthorizationEngine::can` on every read and
  write, plus tenant scoping in every repository query (joined reads scope both tables explicitly).

### E10 — UI and navigation

- **Finance pages:**
  - Expenses list: filters for date range, category, status, project, currency, plus a correction badge;
  - create / edit form: `crm-datepicker`, the E3 hint, payee picker (contact *or* company);
  - detail view: corrections listed under the original with their signed effect and the net; receipts; void and correction actions only when allowed; the expense timeline.
- **Project Costs panel:**
  - a **separate** panel registered through `RelatedPanelRegistry` (subject `project`);
  - needs `finance.expenses.view` and the project viewable through `find()`;
  - shows the net actual cost of the expenses the user may view, **per currency only**: no cross-currency total, no FX, no profit, no margin;
  - restricted expenses are neither listed nor summed (the Stage 3-B pattern).
  - It has a "New expense" button (D-P1), shown only when allowed.
  - The Stage 3-B **Project Revenue panel and its template are unchanged.**
- **Navigation:** one additive item ("Expenses") in the Finance group of `$navGroups` in
  `themes/default/templates/layout.php`, behind the same Finance module flag. Nothing else in the layout changes.
- **Text:** all labels, messages and errors in the five language files.

### Timeline

- **On the expense:** created, updated, voided (with reason), correction recorded / voided, receipt added /
  removed, project changed. These include audit details (actor; amount and currency where relevant).
- **On the project:** generic wording only, for example "An expense was attributed to this project" or "An
  expense attribution was removed". No amount, currency, receipt name or other financial detail, because
  project viewers may not hold `finance.expenses.view`.

### Invariants (apply to every expense operation)

- Organization and record-level scope are enforced on every request, consistently.
- Amounts are `DECIMAL(38,18)` and BCMath (scale 18), with display rounding only in templates.
- The currency exists and is active when set. There is no FX and no cross-currency grand total anywhere.
- Expense totals are separate from invoice revenue, payments, installments, contract values and credit notes.
- No previously approved behavior changes: revenue, invoices, payments, credit notes, installments, Contract
  Billing, Project Revenue, Patch A, Patch B, Stage 3-A / 3-C.
- Create, edit, void and correction run with transactional validation and row locking (see E8 and the lifecycle above).
- **Stage 5 profitability:** counts each original's **net** exactly once, from its direct `project_id`, per
  currency. It never traverses Contracts or `project_links`.

### Planned files (implementation needs separate approval)

- **New:**
  - `modules/Finance/Migrations/0018_create_finance_expenses_table.php`, `0019_grant_finance_expense_permissions.php`;
  - `ExpenseRepository.php`, `Services/ExpenseService.php`, `Controllers/ExpenseController.php`;
  - `templates/finance/expenses/{index,form,show}.php`, `templates/finance/_project_costs.php`;
  - tests and a concurrency probe.
- **Extended (additive):**
  - `FinanceModule.php` (keys, RecordType, wiring, routes) and `FinancePanels.php` (second project panel);
  - `themes/default/templates/layout.php` (one nav item), `lang/*.php`, this document and `CHANGELOG.md`.
- **Not changed:**
  - Invoice, Payment, CreditNote and Report services; InvoiceController; ContractBillingService;
  - installment code; ProjectRevenueService / Repository and `_project_panel.php`; `finance_document_links`;
  - Core RecordSupport; Projects; Contracts; `Permission.php`.

### Conflicts resolved (this section supersedes)

| Earlier text | Now |
|---|---|
| §5 / §20: project link through `finance_document_links` + ENUM change (old migration 0017) | E1: `project_id` on the expense; `finance_document_links` unchanged |
| §20 / §25: migration numbers 0017 (alter), 0018 (expenses), 0021 (grants) | 0017 is installments (Stage 2); expenses are 0018 and grants are 0019 |
| §20: vendor contact **and** company, both optional | E4: payee contact **or** company, never both |
| §20: void reason VARCHAR(255) | ≤ 450 characters (column 500), same as invoice cancel / installment void |
| §20: `source_type` / `source_id`, `base_amount` / `rate_used` | not in Phase 4 (Timesheet phase / D6 rejected) |
| §18 / §21: `finance.expenses_view` / `_manage` (underscore) | §0 D1 dotted keys: `finance.expenses.view` / `.manage` |
| §0 Expense row: "after the period closes, correction only through a new entry" | E8 specifies the correction model (direction, invariant, locking) |
| §19: one combined "Project finance" panel | E10: a separate Project Costs panel; the Stage 3-B revenue panel is unchanged |
| §21: `GET /finance/expenses/create?from_project=N` | D-P1: allowed (prefill only; all checks re-run on submit) |

### Final decisions on the former open points (approved 2026-10-10)

**D-P1 — "New expense" from the Project Costs panel: allowed.**
- **Button:** "New expense" in the Project Costs panel. It is shown only when the server would accept the
  request: `finance.expenses.manage`, plus `projects.edit` on that project (record scope), and the project
  is not `cancelled` (E9).
- **Form:** `GET /finance/expenses/create?from_project=N` opens the normal expense form with the project
  pre-selected (hidden `project_id`) and a "for project …" notice.
  - The project is read through `find('project')`. Not viewable → 404; no `projects.edit` → 403;
    cancelled → back to the project with the reason.
- **Submit:** `POST /finance/expenses` re-runs every E7 / E9 check on the submitted `project_id`, inside the
  create transaction. The hidden field is never trusted. Payee, currency and the other fields are
  validated as for any expense.
- **Other routes unchanged:** this prefill route is the only addition. The form posts to the ordinary
  create route.

**D-P2 — Who may record (or void) a correction.**
- **Always:** `finance.expenses.manage` with record scope on the **original** expense (`can()` on the
  original's row). Record scope on the correction alone is not enough.
- **When the original has a `project_id`:** also `projects.edit` (record scope) on that project, read through
  `find('project')`. Not viewable or no permission → 403; nothing is written.
- **Project no longer exists** (deleted while draft / planned / cancelled): only the expense permission is
  required. There is nothing left to authorize on the project side. This is the same rule as unlinking a
  deleted contract in Stage 3-A.
- **Corrections always inherit the original's project.** E9's "no new attribution to a cancelled project"
  governs **choosing** a project for an ordinary expense, not corrections. Otherwise an overstated cost
  on a project that was cancelled later could never be corrected.
- **Same rules for voiding a correction:** the original is locked, and the non-negative invariant (E8) is re-checked.

**D-P3 — Race with closing a financial period: handled as far as the current infrastructure allows.**

*Verified facts (2026-10-10, read-only):*
- `FinancialPeriodService::close()` / `reopen()` change the period row's `status` with a single `UPDATE`
  (no surrounding transaction, no extra lock).
- `isDateLocked()` is a **non-locking** read (`closedPeriodCovering`).
- New periods are always created `open`. A period can become closed only through that `UPDATE` on its
  own row.
- `financial_periods` has KEY (organization_id, starts_on, ends_on).

*Design (no change to `FinancialPeriodService`, `FinancialPeriodRepository` or the period table):*
- Inside every expense transaction (create, edit, void, correction, correction void, receipt upload /
  delete), after the expense row lock, the period check uses a **locking read** of the period row(s)
  covering each date that matters:
  `SELECT … FROM financial_periods WHERE organization_id = :org AND starts_on <= :d AND ends_on >= :d LOCK IN SHARE MODE`.
  - This lives in `ExpenseRepository`, on Finance's own table, with an explicit organization filter.
  - `LOCK IN SHARE MODE` works on MySQL and MariaDB.
- **Which dates are checked:**
  - create: the new date;
  - edit: the old and the new date;
  - void: the expense's date;
  - correction: the correction's own date, which must be open;
  - receipts: the expense's date.
  - For the *original* of a correction, "its period is closed" is read the same way. A concurrent reopen
    simply makes the ordinary edit path apply next time.
- **Effect:**
  - **Close already pending (uncommitted)** when the expense check runs: the locking read waits for it,
    then sees `closed`, and the expense operation is refused.
  - **Expense check first:** the share lock is held until the expense transaction commits, so a
    concurrent `close()` waits and closes the period **after** the expense change. The order stays
    consistent: the change happened while the period was open.
- **Residual window (accepted, documented):** if **no** period row covers the date, there is no row to lock.
  Closing such a date would need a new period to be created (always `open`) **and** closed by a separate
  request, both inside the expense transaction's short lifetime. This is not prevented. Closing it fully
  would need a change to the period service (out of scope).
- **Scope of D-P3:** expenses only. Invoices, payments, credit notes and installments keep their current
  non-locking period checks; they are not changed in Phase 4.
- **Tests:**
  - the lite suite covers the checks themselves;
  - a two-process probe (as in Stages 2–3) covers "close pending → expense refused" and "expense in progress → close waits";
  - the mutation "locking read replaced by `isDateLocked()`" must be caught by that probe.

### Implementation status (Stage 4, implemented 2026-10-10)

Implemented as designed above (E1–E12, D-P1–D-P3). The files are those listed under "Planned files".
No protected file changed. Migrations: Finance 0018 (`finance_expenses`) and 0019 (grants to the
system `org_admin` role only); run `/system/migrate` after deployment.

**Implementation clarifications (within the design):**
- **Corrections copy the original's** category, currency, `project_id`, `owner_id`, `team_id` and
  `department_id`. Visibility and every per-project sum therefore follow the original, and an original's
  net is counted once. Category and currency sent in a correction form are ignored.
- **Receipt upload** writes the timeline entry through `AttachmentService` (`attachment_added`), as for
  every other attachment. Removal is recorded by Finance (`receipt_removed`). The project side gets no receipt entries.
- **Page flags** (which buttons are shown) use the non-locking `isDateLocked()`. Every write re-checks with
  the locking read inside its transaction (D-P3).
- **Project Costs panel:** permission is checked twice, by the panel registry (`finance.expenses.view`) and
  again in the renderer, which also needs the project to be viewable.

**Known limitation (reported, not a design addition):**
- There is no generic project picker in Core (`RecordTypeRegistry` has no list API). An expense gets its
  project only through D-P1 (the project's "New expense" button). The edit form can keep or remove it.
- Moving an expense to *another* project is implemented and checked in `ExpenseService::update()` (E7 on
  both projects, E9 on the target), but there is no UI for it.
- Attaching an *existing* expense to a project from the project page would be a new feature. It is left
  for a later, separately approved change.

**Verification:**
- `tests/lite/ExpenseTest.php`: 21 tests. Full suite 544 / 544. Against the pre-Stage-4 code, 19 of these fail.
- 20 mutations: 18 detected and 2 equivalent:
  - the panel-permission mutation: the renderer re-checks; the extra mutation M18b, which removes both checks, is detected;
  - the form-currency mutation: the original's currency already wins.
- `tests/concurrency/expense-probe.php` + `expense-child.php` cover:
  - concurrent corrections: the second waits, then is refused, net 40;
  - expense first: `close()` waits;
  - close pending: the expense waits, then is refused;
  - void vs edit: the edit waits, then is refused.
- The probe detects 3 lock mutations: non-locking period read, unlocked original, unlocked row on edit.

## Stage 5 — Budget, project profit and margin (implemented 2026-10-10)

All ten audit open points approved as recommended. This section supersedes §7 and §8 where they differ.
No FX, no cross-currency total anywhere, no Stage 1–4 file changed.

**Definitions (per currency, bcmath scale 18):**

| Figure | Rule |
|---|---|
| Net revenue | Σ issued invoices attributed to the project: (`grand_total − tax_total`) − credited × (`grand_total − tax_total`) / `grand_total`, and 0 when `grand_total = 0`. Never revenue: drafts, cancelled invoices (and their credit notes), proformas, payments, installments, contract value, unattributed invoices. Read only from `ProjectRevenueRepository::invoicesOfProject` (Stage 3-B). |
| Actual cost | Σ signed amount of the project's recorded expense rows: original +, increase +, decrease −, void excluded. Each original's net is counted once. Read only from `ExpenseRepository::recordedForProject` + `ExpenseService::signed()` (Stage 4). |
| Profit | Net revenue − Actual cost; may be negative. |
| Margin % | Profit / Net revenue × 100, only when Net revenue > 0, else "—". Shown with one decimal. |
| Budget | Expense budget only: lines per category (the six Stage 4 categories) × currency, amount ≥ 0. No planned revenue, committed cost, versioning or approval. |
| Remaining | Budget − Actual (per currency × category, and per currency total). |
| Variance | Actual − Budget (positive = over budget). |
| Variance % | Variance / Budget × 100, only when Budget > 0. |

Where no budget line exists, Remaining / Variance / Variance % are "—"; Actual is still shown.

**Profit visibility (security decision 9).** Profit and margin are shown only with all of the following:
- `finance.budgets.view` with record scope on the project;
- `invoices.view` at organization or all scope;
- `credit_notes.view`;
- `finance.expenses.view` at organization or all scope.

Otherwise the whole Profitability panel is absent and nothing is computed. There is no partial profit,
because Expense = Revenue − Profit would reveal costs the user may not see. Under these scopes every row
is visible, so the per-row checks inside the service are defense in depth.

**Budget access:**
- **View:** `finance.budgets.view` with record scope on the project row; the project must be viewable through `find('project')`.
- **Actual / Remaining / Variance columns:** also need `finance.expenses.view`. They count only the expenses the user may view, the same as the Stage 4 Project Costs panel.
- **Edit:** `finance.budgets.manage` and `projects.edit`, both with project scope.
  - Editable in draft / planned / active / on_hold; read-only in completed / cancelled.
  - Profit and margin are shown in every status. Late expenses or credit notes change them; that is intended.

**Budget save:**
- `GET/POST /finance/projects/{id}/budget` in the `/finance` group, with `RbacMiddleware(finance.budgets.manage)` and CSRF on the POST. The service re-checks everything.
- **Validation:**
  - a category from the six;
  - an active currency, or one already on this budget;
  - amount `^\d{1,20}(\.\d{1,18})?$` (zero allowed, negative refused);
  - notes ≤ 255;
  - no duplicate category × currency;
  - ≤ 200 rows;
  - blank rows ignored; an empty submission removes the budget.
- **One transaction:** lock the project's lines `FOR UPDATE`, delete, insert, then the project timeline.
- **Concurrency:**
  - A concurrent save waits and then replaces the budget whole.
  - A reader is never blocked and sees one complete budget.
  - When a project has no lines yet there is nothing to row-lock. A truly simultaneous first save is resolved by InnoDB (deadlock / duplicate key) and turned into a clean "please retry" message; the other save is kept whole.
- **Timeline:** a generic project entry, `budget_updated` "Budget updated", only when the budget actually changed. It never contains an amount, category or note. The text is stored in English, as every existing timeline entry is.

**UI:** two new project panels after Project Revenue (45) and Project Costs (46), which are unchanged:
- Budget (47): Currency, Category, Budget, Actual, Remaining, Variance, Variance %, plus a per-currency total row; "No budget defined" and "Define budget" / "Edit budget" when allowed.
- Profitability (48): Currency, Revenue (excl. tax), Actual cost, Profit, Margin %, plus a note that this revenue excludes tax and so differs from "Revenue (incl. tax)" in the Project revenue panel.

**Migrations:**
- Finance 0020 creates `finance_budget_lines` with:
  - columns: id, organization_id, project_id (no FK), category ENUM (Stage 4 values), currency_id (FK), amount DECIMAL(38,18), notes VARCHAR(255), created_by_user_id / updated_by_user_id, timestamps;
  - UNIQUE (organization_id, project_id, category, currency_id);
  - KEY (organization_id, project_id);
  - CHECK (amount ≥ 0).
- Finance 0021 grants `finance.budgets.view` / `.manage` at organization scope to the system `org_admin` role only, with `INSERT IGNORE`. Custom roles are untouched.
- Run `/system/migrate` after deployment.

**Out of scope (not built):** profitability report across projects, Finance dashboard, FX / consolidated profit,
planned revenue, committed cost, budget versioning, approval workflow, any ReportService / invoice / payment /
credit-note / Stage 4 change.

**Verification:**
- `tests/lite/ProjectBudgetTest.php` (15) and `ProjectProfitTest.php` (10). Full suite 569 / 569.
- Against the pre-Stage-5 code, 23 of these 25 fail; the two that pass are the rollback checks.
- 40 mutations: 38 detected and 2 equivalent (the per-row invoice / expense checks in the profit service, see above).
- `tests/concurrency/budget-probe.php` + `budget-child.php` cover:
  - save vs save: waits, final = the second submission whole;
  - replace on an empty budget;
  - stale re-submit: waits and records the change;
  - read during replace: no wait, the old budget complete.
- Removing `FOR UPDATE` is detected by the probe: a lost "Budget updated" entry. The data itself still can't mix, because the DELETE also waits for row locks.

## Stage 5.1 — Expense project allocation + contract reference (implemented 2026-10-10)

All eight audit open points were approved as recommended.

**Project = financial allocation; Contract = reference only.**

**Project** (the existing `project_id`; no new relation):
- The general Finance → Expenses form now has an optional "Allocate to project" selector.
- **Options** are only the projects the server would accept for a new attribution:
  - viewable, with the same rule as `find('project')`: Projects available, `projects.view` and record scope;
  - `projects.edit` on the row (E7);
  - not cancelled (E9).
- **On edit:** "No project" removes the project; another project moves the expense, with E7 checked on both projects. A form without the selector keeps the value.
- **Unchanged Stage 4 rules:** the server re-checks every submitted id; closed periods, corrections (the project is frozen while recorded corrections exist), void, scope and permissions are as before.
- **Project → New Expense** (D-P1) keeps the project fixed (hidden field, no selector).

**Contract (reference):**
- **Storage:** a new nullable `finance_expenses.contract_id`, added by Finance migration 0022. It has no FK (Contracts is optional) and has KEY (organization_id, contract_id).
- **Selecting a contract:** options are the contracts viewable by the user (`find('contract')` rule: Contracts available, `contracts.view`, record scope), in any status. Edits follow the same rules as any other field: open period only, frozen while recorded corrections exist.
- **Corrections** copy the original's `contract_id`.
- **No calculation reads `contract_id`:** project costs, Stage 5 profit, margin and budget vs actual, Project Revenue, contract Billing, installments, invoices, payments, credit notes and reports.
- **No project inference:** a project is **never** derived from the contract, because Contract ↔ Project is N:N in `project_links`.
- **Timeline:** on the expense only; nothing on the contract, no contract detail on the project.
- **Help note:** shown directly under the field. Persian text: «توجه: تخصیص هزینه به قرارداد به‌تنهایی در محاسبات مالی و سودآوری اثر ندارد. هزینه فقط در صورت تخصیص به یک پروژه، در محاسبات مالی و سودآوری پروژه لحاظ می‌شود.»

**When an expense affects a project's figures:** only with `project_id = X` and status `recorded`, counted once with its signed corrections. Contract-only and unallocated expenses never count for any project.

**Candidate lists (audit option 2):**
- Core has no list API for another module's records, so `Modules\Finance\AllocationOptionsRepository` reads the minimum columns of `projects` / `contracts`.
- It is read only, always filtered to the caller's organization, and capped at 500 rows, newest first. It is queried only when the module is available to the user.
- It only proposes choices; the submit is authorized by `find()` as before.
- This is the first place Finance reads those tables directly (a recorded, accepted coupling). Projects, Contracts and Core are unchanged.

**Form order:** amount, currency, category, date, payee, project, contract (+ note), description, reference, save. Receipts are still attached on the expense page after saving (E6).

**Unchanged:**
- Stage 5: BudgetService, ProjectProfitService, panels and templates;
- `ExpenseRepository` and `_project_costs.php`;
- revenue / invoice / payment / credit note / report / billing / installment code;
- Projects, Contracts, Core, `Permission.php` and `layout.php`.

**Verification:**
- `tests/lite/ExpenseAllocationTest.php`: 17 tests covering the 21 required scenarios plus extras. Full suite 586 / 586.
- On the pre-5.1 code, 11 of them fail. The 6 that pass assert pre-existing behaviour that had to stay unchanged.
- 18 / 18 mutations detected; all concurrency probes unchanged.

## Final validation & reconciliation — approved remediation P1–P3 (2026-10-10)

The final Finance audit found one High and two Medium defects. All three are fixed as approved (D1 = A, D2, D3).
No schema change, no migration, no financial record touched. Stage 4 / 5 / 5.1 rules and files are unchanged.

**P1 — period report** (`ReportService`, `InvoiceRepository::periodPerformanceByCurrency`, `reports/index.php`).
- **One row per invoice currency:** money is never added across currencies (architecture §48); only the invoice count is.
- **Period basis (D1 = A):** invoices *issued* in the period, with all of their own credit notes (any credit-note date) and their payments so far.
- **"Outstanding today":** grand − credited − paid, the invoice page's rule. The page states that this is period invoice performance, not a period-end receivables balance.
- **Removed:** the credit-notes-by-their-own-date method (`CreditNoteRepository::totalForPeriod`), which caused negative net / outstanding (audit A2).
- **Unchanged:** drafts and cancelled invoices are still excluded; organization filter explicit; `financial_reports.view` unchanged.

**P2 — one outstanding rule everywhere.** Outstanding = grand − credited − paid for issued invoices, in:
- the invoice page;
- the contact panel ("Balance due", now decimal-safe bcmath instead of float);
- the ClientRecords summary and invoices tab (payment status as `PaymentService::paymentStatus`);
- the ClientRecords API's new `outstanding` field.

The API's existing `remaining` (grand − paid) is kept unchanged for compatibility (D2). Credited totals come from a
correlated subquery, organization-scoped, added to Finance's invoice list and to `ContactLinkRepository`. No
credit-note detail is shown anywhere new.

**P3 — server-side numeric validation** (`Modules\Finance\AmountInput`; invoice / proforma lines, payments, credit notes).
- **Normalization:** the Stage 4 rule — thousands separators and spaces removed ("1,000" → "1000"), then `^\d{1,20}(\.\d{1,18})?$`.
- **Refused with a validation error** (form 422, or a flash for payments / credit notes), never an uncaught ValueError (500):
  - malformed values (`abc`, `1e3`);
  - negative quantities, prices and discounts;
  - percentage discounts above 100 %;
  - fixed discounts above the line's gross amount.
- **Unchanged:**
  - accepted values compute exactly as before (verified old vs new on the same document);
  - zero quantity, 100 % and discount = gross remain valid;
  - payment / credit caps and closed periods are unchanged.

**Not changed (approved scope):** idempotency, currency-precision enforcement, timeline auditing of payments / credit
notes, period-close history, locking period checks for invoices / payments / credits (audit L1–L5).
*(Later done in Stage 5.2 — see `docs/finance-stage-5-2-finalization.md`.)*

**Verification:**
- `tests/lite/FinanceRemediationTest.php`: 11 tests, plus a child process for the payment / credit success paths, which open their own transactions.
- Full suite 599 / 599, twice.
- Against the pre-remediation code, 9 of the 11 fail; the 2 that pass are the "unchanged totals" and rollback checks.
- 15 / 15 mutations detected.
- All 7 concurrency probes unchanged.
- `crm_local` schema hash and financial totals identical before and after.

---

Original audit (2026-10-10) follows. Where it lists open decisions, §0 above is final.
Baseline at audit time: `php tests/lite/run.php` → 396 tests, 396 passed.

---

## 1. Executive Summary

**The system has no cost-side finance at all today.** There is no expense, purchase, vendor cost,
timesheet, labor rate or budget anywhere in the code or schema. Revenue-side finance is mature:
- proformas;
- invoices with stored `grand_total / tax_total / paid_total`;
- payments, always against an issued invoice;
- credit notes, always issued immediately and always in the invoice's currency;
- financial periods with date locking;
- per-organization base currency (`financial_profiles.base_currency_id`);
- platform-wide exchange rates with history.

Since the last step, invoices and proformas can also be linked to a contract (`finance_document_links`).

**Recommended architecture.** It follows the pattern already used for Projects, Follow-ups and Contract ↔ Finance:

1. **Finance owns every financial record and every financial number.**
   - Revenue comes from Finance documents. There is no separate `project_revenues` table.
   - Costs are a new Finance-owned expense entry.
   - Budgets are new Finance-owned budget lines.
   - Contract payment schedules are new Finance-owned installments.
2. **Projects and Contracts stay unchanged.**
   - Finance contributes a "Project finance" panel to the project page and a "Payment schedule"
     section to the contract's existing Billing panel, both through `RelatedPanelRegistry`.
   - Finance reads projects and contracts only through `RecordTypeRegistry::find()`.
3. **Every document is attributed explicitly, once, per dimension.** `finance_document_links` already
   enforces "one contract per document". The same table gives "one project per document" and "one
   installment per document". Contract totals and project totals are two lenses on the same
   documents and are **never added together**. Revenue is never derived through
   contract → project, which is many-to-many. This is the rule that prevents double counting.
4. **There is never a cross-currency sum.** Every figure is per currency. A base-currency total is an
   optional later step, and it needs one small Core method (§9, decision D6).
5. **Revenue for profit = net invoiced, excluding tax, minus credit notes.** Cash received (payments)
   and receivables are shown next to it, never mixed with it.

**Items needing your decision:**
- **Two blockers:**
  - new permission keys for expenses and budgets (D1);
  - a Core method for historical exchange rates, only if converted totals are wanted (D6).
- **Seven product decisions** (§29).

Pre-existing issues found (reported, not to be changed in this work):
- the Finance period report adds amounts across currencies;
- an invoice that already has payments can still be cancelled.

---

## 2. Current Architecture Audit

### Projects (`modules/Projects`)

| Question | Answer |
|---|---|
| Data | `projects`: code, name, description, status (draft…cancelled), `owner_id` (manager), `team_id`, `department_id`, `contact_id`, `company_id`, `start_date`, `expected_end_date`, `actual_end_date`, `created_by_user_id`, timestamps. Plus `project_members`, `project_links`, `project_settings`. |
| Linked to Contract? | Yes, via `project_links (linked_type='contract')`. UNIQUE `(project_id, linked_type, linked_id)`, so it is **many-to-many**: a project can link several contracts, and a contract can be linked by several projects. |
| Linked to Deal? | Yes, the same way (`linked_type='deal'`). Auto-create on won is optional and off by default. |
| Organization? | `organization_id` NOT NULL, FK, tenant-scoped repositories. |
| Task / Milestone? | **No.** Sales owns a separate `tasks` table (`related_type/related_id`), but it is not project work tracking. |
| Budget / Cost / Revenue? | **None.** No column or table. |

### Finance (`modules/Finance`)

| Question | Answer |
|---|---|
| Invoice ↔ Contract | `finance_document_links (document_type proforma\|invoice, linked_type 'contract')`. UNIQUE `(organization_id, document_type, document_id, linked_type)`. Set by "create from contract"; carried over when a proforma is converted. |
| Payment ↔ Invoice | `payments.invoice_id`, NOT NULL. Allowed only on **issued** invoices and capped at `grand_total − credited − paid`. It updates `invoices.paid_total` inside a row lock. There is **no currency column**: the payment is in the invoice's currency. There is no refund workflow; `payments.refund` exists as a key but is unused. |
| Credit Note | `credit_notes.invoice_id`. Allowed only on **issued** invoices; amount ≤ `grand_total − already credited`; numbered at creation. It has **no draft state** and no currency of its own (it uses the invoice's). Payment status is derived at read time as `paymentStatus(invoice, credited)`. |
| Document → Project directly? | **Not yet.** The link table is generic (`linked_type VARCHAR`), so `linked_type='project'` needs **no migration**. |
| Payment → Project? | Not directly, and it shouldn't be: a payment inherits its invoice's attribution. |
| Revenue / Expense concept? | Revenue only, in `ReportService::summary()` for a date range: gross = Σ `grand_total` of issued invoices, net = gross − Σ credit notes, outstanding = net − paid. **Pre-existing issue: it sums across currencies.** There is no expense concept. |
| Invoice cancel | `cancel()` checks only "not already cancelled". **Pre-existing issue: an invoice with payments can be cancelled**, and its payments then fall out of every total. |
| Periods | `FinancialPeriodService::isDateLocked()` blocks issuing, paying and crediting in closed periods. Reusable for expenses. |
| Permissions | Core constants: `quotes.*`, `invoices.*`, `payments.*`, `credit_notes.*`, `financial_reports.view`, … There are none for expenses or budgets. |

### Contracts (`modules/Contracts`)

| Question | Answer |
|---|---|
| Contract ↔ Project | Contracts does not know projects. The relation lives only in `project_links` (owned by Projects). The Projects panel appears on the contract page. |
| Contract ↔ Financial documents | Contracts does not know Finance. The relation lives only in `finance_document_links` (owned by Finance). Finance's "Billing" panel appears on the contract page. |
| Cardinality limits | Contract ↔ Project: none (N:N). Contract ↔ document: one contract per document, many documents per contract. |
| Money | `value DECIMAL(38,18)` + `currency_id` (one currency per contract), `start_date`, `end_date`. There are **no obligations or installments** (`contract_obligations` was designed in 2.1 but never built). |

### Timesheet

**Does not exist.** There are no time entries, rates or hours anywhere.

### Other costs

Searched for expense, cost, purchase, vendor, supplier, `cost_price`, hourly and budget across `app`,
`modules`, `database` and `routes`: **nothing**. Products have prices but no cost price. **Nothing to reuse
on the cost side.**

---

## 3. Existing Reusable Components

| Component | Reused for |
|---|---|
| `finance_document_links` (generic `linked_type`) | invoice/proforma → **project** attribution; invoice/proforma → **installment** link; (after an ENUM extension) expense → project |
| `RecordTypeRegistry::find('project'|'contract')` | validating a project or contract (module on, view permission, organization, record scope) without importing either module |
| `RelatedPanelRegistry` (slots already on project and contract pages) | the Project finance panel; the schedule section on the contract page |
| `ProformaService` / `InvoiceService` / `TaxCalculationService` | every amount on a document, unchanged |
| `PaymentService::paymentStatus()` + `CreditNoteRepository::totalCreditedForInvoice()` | installment status and receivables, the same derived rule Finance already uses |
| `FinancialPeriodService::isDateLocked()` | blocking expense create/void in closed periods |
| `DocumentHeaderGuard` (contact of the org + active currency) | the same checks on expenses and installments |
| `financial_profiles.base_currency_id` | the reporting currency, if converted totals are approved (D6) |
| `exchange_rates` (platform-wide, `effective_at` history) | historical conversion (D6) |
| `ContractBillingService` (from-contract prefill, `createLinked`, link carry-over) | extended to "from installment" and "from project" |
| EventBus (listen by class-name string) | a future Timesheet → labor cost bridge (§6) |
| Tenant-scoped repositories, `ModuleGate`, `AuthorizationEngine` | everything |

---

## 4. Project Revenue Design

**No `project_revenues` table.** Revenue is not entered; it is a consequence of Finance documents. A
parallel revenue table would duplicate invoices and drift.

**Attribution:** a proforma or invoice is attributed to **at most one project**, through
`finance_document_links (linked_type='project')`. The existing UNIQUE key enforces this with no
schema change. There are two ways in:
1. **Created from the project page:** `?from_project=N` on the existing proforma/invoice create pages.
   The project is validated through `find('project')`, and the link is stored atomically. This is
   the same pattern as `?from_contract=`.
2. **Attach existing:** in the project's Finance panel, a select of the organization's issued or draft
   invoices that aren't attributed to any project yet (Finance lists its own documents), then POST.
   Detach is allowed while the document is a draft. For issued documents, see D3.

Conversion of a project-attributed proforma copies the project link to the invoice. This
generalizes the existing contract-link copy to "copy all links".

**Figures per project, per currency** (only `status='issued'` invoices count; drafts are listed but
not counted; cancelled ones are excluded entirely):

| Figure | Source |
|---|---|
| Invoiced (gross) | Σ `grand_total` |
| Credited | Σ credit notes of those invoices |
| **Net revenue (for profit)** | Σ (`grand_total − tax_total`) − Σ credited × (net/gross of each invoice) — revenue excludes tax, and a credit note reduces net in the invoice's own net/gross proportion (D2) |
| Paid (cash received) | Σ `paid_total` |
| **Receivable** | Σ max(0, `grand_total − credited − paid`) per invoice |
| Refund due | Σ max(0, `paid − (grand_total − credited)`) per invoice (credited after full payment) |

**Answers to the scenarios:**
1. **Project with a contract:** the contract's documents count for the project only if they are
   attributed to it. Contract → project is N:N, so revenue is never inferred through the contract.
2. **A contract with several invoices:** each invoice carries at most one contract link **and** at most
   one project link. Each view counts it once.
3. **An invoice of a contract:** it shows in the contract's Billing panel (contract lens) and, if
   attributed, in the project panel (project lens). The two are never summed.
4. **The project shows its revenue:** from its attributed documents.
5. **Partial payment:** revenue (accrual) is unaffected; Paid rises; Receivable falls.

- **Revenue = invoice or payment?** Revenue (for profit and margin) = net invoiced (accrual). Payments
  are shown as "Paid / received" and drive Receivable. A cash basis is not used for profit (D2).
- **Credit note:** it reduces credited, net revenue and receivable. Revenue never goes below zero for
  an invoice; the cap already exists in `CreditNoteService`.
- **Cancelled invoice:** excluded from every figure. Pre-existing risk: if it had payments, they
  vanish from totals. Reported in §26; not changed here.

## 5. Project Cost Design

> **Superseded for expenses** by "Phase 4 — Expenses: approved design" above. In particular, E1 uses
> `project_id` on the expense instead of `finance_document_links`. The text below is the original audit.

**New Finance-owned entity `finance_expenses`.** It is generic: it can exist without a project, so the
same entity serves future non-project expenses. It is attributed to a project through
`finance_document_links (document_type='expense', linked_type='project')`, which is the same
mechanism and the same "at most one project" rule.

**Fields:**
- category (fixed list, in line with the request: `labor, procurement, contractor, shipping, services, other`);
- description, amount, currency, expense date;
- vendor (a Core contact and/or company of the organization, both optional);
- owner, team, department (RBAC scope, as on every Finance document);
- status (`recorded`, `void`) and void reason;
- source (`manual` now; `timesheet` later, §6) and source id;
- created by.

**Rules:**
- No hard delete. **Void** keeps the row and leaves totals (audit trail).
- Editing is allowed while the expense date isn't in a closed financial period.
- Amount > 0. The currency must be active. The vendor must belong to the organization (`DocumentHeaderGuard` rules).
- Actual cost = Σ amount of `recorded` expenses attributed to the project, **per currency**.

**Amounts are entered as the cost to the company:** net of recoverable tax (D4). Recoverable VAT
belongs in a tax module, not in project cost.

**Not an expense table:** **Committed cost** (purchase orders not yet received) needs a purchase-order
concept that doesn't exist. Not recommended now (D7). The panel shows Committed only once POs exist.

## 6. Timesheet Integration (future, designed now, not built)

**Ownership split:**
- **Projects owns time entries** (work data): user, project, later task, date, hours, note, approved state.
- **Finance owns labor rates and the resulting cost.**

**Bridge:** Projects dispatches `TimeEntryApproved` (class-name string); a Finance listener creates
one `finance_expenses` row (category `labor`, `source_type='timesheet'`, `source_id` = entry id).
That expense **freezes** the rate used: amount = hours × rate, and the rate and hours are stored on the
row. Re-approving the same entry is a no-op: UNIQUE `(organization_id, source_type, source_id)`.

**Rate source:** a Finance-owned `finance_labor_rates (organization_id, user_id NULL, project_ref NULL, rate, currency_id, effective_from)`. Resolution order:
1. project-specific user rate;
2. user rate;
3. organization default.

It is effective-dated: the rate valid on the entry's date is used.

**Past costs never change:** each expense stores the amount it was created with, and rates are only
ever added with a new `effective_from`, never edited in place.

The role-based rate is not proposed (roles are permission sets, not cost bands).

## 7. Project Budget Design

**Model: budget lines, no header.** The table is `finance_budget_lines (linked_type='project', linked_id, category, currency_id, amount, notes)`.
- A "total budget" is the sum of its lines, per currency.
- A project with one line in category `other` behaves as a single total.
- Lines use the **same categories as expenses**, so the variance is per category with no mapping table.

**Figures per currency (and per category):**
- Budget = Σ lines.
- Actual = Σ recorded expenses (§5).
- Remaining = Budget − Actual.
- Variance = Actual − Budget (positive = over budget).
- Variance % = Variance / Budget.

**Committed:** not modeled now (§5, D7). The design leaves room for: Remaining = Budget − Actual − Committed.

Budget versioning / baselines are out of scope. The edit history goes to the timeline of the project
record, written by Finance through Core `TimelineService`, as Finance already does for contracts.

## 8. Project Profitability

All figures are per currency. A margin only exists when revenue and cost are in the same currency,
or after D6 conversion.

| Figure | Formula / source |
|---|---|
| Contract value | Informational only, never part of profit. The contracts linked to the project live in `project_links` (Projects). Finance can't read them, so the value is shown by Projects' existing contract list (option in D5); see §10. |
| Invoiced | §4 (issued, gross) |
| Paid | §4 |
| Receivable | §4 |
| Net revenue | §4 (excluding tax, net of credit notes) |
| Budget | §7 |
| Actual cost | §5 (recorded, attributed expenses) |
| **Gross profit** | Net revenue − Actual cost |
| **Margin %** | Gross profit / Net revenue × 100, only when Net revenue > 0, else "—" |

## 9. Multi-Currency

**Rule: amounts in different currencies are never added together.**

**Default (no blocker):** every block of the panel is grouped by currency. For example, a project
with USD revenue and EUR cost shows:
- USD: revenue 10 000, cost 0, profit 10 000
- EUR: revenue 0, cost 3 000, profit −3 000

and no combined margin. This is honest and needs nothing new.

**Optional converted view (D6):** a total in the organization's base currency
(`financial_profiles.base_currency_id`):
- **Rate:** the platform rate effective on each document's or expense's date (`exchange_rates`, platform-wide, history by `effective_at`).
- **Frozen on expenses:** for expenses, the rate is frozen onto the row at entry (`base_amount`, `rate_used`), so later rate changes don't rewrite past costs. This is the Phase 12 immutability rule.
- **Not frozen on invoices:** invoices don't store a rate today. Converting them uses the rate at their issue date, computed at read time.
- **BLOCKER:** `ExchangeRateRepository` only offers `latestRate()` (now) and `history(limit 50)`. Rate-at-a-date needs a small new Core method (`rateOn($src, $dst, $date)`). That is a Core change, so it is reported as a blocker, needed only if D6 = yes.
- **Missing rate:** the converted total shows "rate missing" for that currency instead of guessing.

## 10. Contract ↔ Project Relationship

- **A — Contract → Project** and **B — Project → Contract:** both directions exist today through one table (`project_links`), owned by Projects.
- **C — one contract → many projects:** supported and realistic (a framework contract with several projects).
- **D — one project → many contracts:** supported and realistic (a project funded by an original contract plus amendments).

**Recommended: keep N:N, as it is today.** Restricting it would change existing behaviour.

**Why this doesn't double count:** revenue is **never** derived from the relation. It comes only from
document attribution (§4), and each document has at most one contract link and at most one project link.
- A contract total = its documents.
- A project total = its documents.
- An organization total = the documents themselves.

Nothing is summed across lenses.

**Convenience (D5):** when a document is created from a contract, there is no automatic project
attribution, because Finance can't see `project_links`. The user attributes it from the project panel
("Attach existing"), which lists unattributed documents first, with documents of the same contact on top.

## 11. Contract Obligations

**One entity: "installment"** (a monetary payment obligation). Non-monetary obligations (deliverables)
belong to project work, not to billing, so they are out of scope.

`finance_installments` is **Finance-owned** (billing plan). It is linked to the contract by
`linked_type='contract'` + `linked_id`, so Contracts stays unchanged.

**Fields:** sequence, due date, amount, currency (defaults to the contract's; must be active),
description, voided state, created by.

**Rules:**
- The sum of installments may differ from the contract value. The panel shows the difference; it
  doesn't block (D8).
- Sequence is unique per contract.

## 12. Installment State Machine

**The status is derived, never stored**, except `void`. Its source of truth is the linked Finance
document(s), with the same derivation Finance already uses for payment status, so it can never drift.

| Status | Condition |
|---|---|
| `void` (stored, terminal) | voided by a user; allowed only when no issued, non-cancelled invoice is linked |
| `unpaid` | no active linked document, or only a proforma (not converted), or a draft invoice |
| `issued` | linked invoice `issued`, `paid_total = 0` |
| `partially_paid` | linked invoice issued, 0 < paid < effective (`grand_total − credited`) |
| `paid` | paid ≥ effective (includes "fully credited") |

**`overdue` is a flag, not a status:** due date < today and status ∉ {paid, void}. It is computed on
read; there is no scheduler.

The three statuses you required (Unpaid / Issued / Paid) are the main ones. `partially_paid` is kept
because Finance already distinguishes it and hiding it would mislead (D9). `proforma` and `draft
invoice` are shown as a sub-label of Unpaid, not as statuses.

## 13. Installment ↔ Finance

| Step | Behaviour |
|---|---|
| Create proforma | `?from_installment=N` prefills from the installment: contact and terms from the contract, one line "Contract X — installment k", amount, currency. **Duplicate prevention:** refused if the installment already has an active document (a non-converted proforma or a non-cancelled invoice). |
| Create invoice | the same, `?from_installment=N`, with the same duplicate rule |
| Links stored | the document gets **two** links atomically: `contract` (the installment's contract) and `installment`. The UNIQUE key gives one installment per document; the service rule gives one active document per installment. |
| Convert proforma → invoice | the existing flow; "copy all links" carries contract + installment (+ project) to the invoice. The proforma becomes `converted` and no longer counts as active. |
| Delete draft | the link rows are removed (existing behaviour); the installment returns to `unpaid` |
| Cancel invoice | the invoice is no longer active, so the installment returns to `unpaid` and can be invoiced again. Links are kept for history. |
| Payment | recorded on the invoice as today; the installment status follows (derived) |
| Installment edit | amount, date and description are editable only while the installment has no active document |

## 14. Credit Note Effect

**Contract summary (changes the existing Billing panel, approved as option 3), per currency:**
- Billed = Σ `grand_total` of issued invoices.
- Credited = Σ credit notes of those invoices.
- Net billed = Billed − Credited.
- Paid = Σ `paid_total`.
- **Balance** = Σ max(0, `grand_total − credited − paid`).
- Refund due = Σ max(0, `paid − (grand_total − credited)`).

| Question | Answer |
|---|---|
| Reduces the contract balance? | Yes, through Credited (above). |
| Effect on an installment? | Yes: effective amount = invoice `grand_total − credited`; a fully credited installment counts as `paid` (nothing left to collect). |
| Partial credit note | Only its amount is subtracted; the rest remains receivable. |
| Multi-currency | A credit note has no currency of its own; it is always the invoice's currency and is grouped there. A cross-currency credit can't exist. |
| Invoice cancelled | The invoice and its credit notes are both excluded (consistent with §4). |
| Issued but not final | Doesn't exist: credit notes are numbered and final on creation. Nothing to model. |

**`CreditNoteService` is not changed.**

## 15. Project ↔ Finance Architecture

```
Deal ──(Projects: project_links)──▶ Project
 │                                    ▲  (Projects owns the relation; N:N with contracts)
 └─(Contracts: contract_links)─▶ Contract
                                   │
                    Finance: finance_installments (linked_type=contract)
                                   │  from_installment
                                   ▼
               Proforma ──convert──▶ Invoice ──▶ Payments / Credit notes
                   │ links: contract (+installment) (+project)  ◀── ONE row per dimension per document

Project ──(Finance: links linked_type=project)──▶ its proformas / invoices / expenses
   ├─ Budget lines (Finance)       ├─ Expenses (Finance) ◀── future: Timesheet (Projects) via event
   └──────────── Project finance panel (Finance) = revenue, cost, budget, profit
```

**Where the two flows meet:** only at the **document**. An invoice can carry a contract link
(contract flow) and a project link (project flow).

**Where they stay apart:**
- Contract Balance (what the customer still owes on the contract) is shown only on the contract page.
- Project Profit (net revenue − cost) is shown only on the project page.
- Neither is ever computed from the other.

**Guarantees:**
- **No invoice is counted twice:** one row per document per lens, and each lens reads only its own `linked_type`.
- **No payment is counted twice:** payments are never linked themselves; they inherit their invoice's lens.
- **No cost is counted twice:** one project per expense (UNIQUE), and timesheet expenses are unique per source entry.
- **Contract Balance is not confused with Project Profit:** different pages, different formulas, different labels.

## 16. Modular Responsibility

| Module | Owns | Doesn't |
|---|---|---|
| **Finance** | documents, payments, credit notes, **expenses, budget lines, installments, labor rates (future)**, every financial formula, the Project finance panel, the schedule section on the contract page | read Projects' or Contracts' tables; it uses the registry only |
| **Projects** | project, members, project↔contract/deal relation, **time entries (future)**, the panel slot | any money figure |
| **Contracts** | contract terms, value, lifecycle, panel slot | invoicing, schedule, money aggregation |
| **Sales** | deals; the won event | none of the above |

**No change to Projects, Contracts, Sales or Core is required by the recommended design.** The only
exception is the D6 rate method (Core), and only if converted totals are wanted.

## 17. Tenant Isolation

Every new table has `organization_id` NOT NULL (FK to organizations, ON DELETE CASCADE) and a
`TenantScopedRepository`. On every write:

| Input | Check |
|---|---|
| project id | `RecordTypeRegistry::find('project', id, user)`: Projects available, `projects.view`, same organization, record scope (+ membership through the project's own rule is NOT used by `find()`, which is stricter, as documented) |
| contract id | `find('contract', …)` |
| document id (attach / installment) | Finance's own tenant-scoped repositories, plus the document's own permission (`invoices.view` / `quotes.view` with record scope) |
| expense / budget line / installment id | tenant-scoped `findById` → 404 if foreign |
| vendor contact / company | tenant-scoped Core repositories |
| currency | exists + active |
| user (owner) | an active user of the organization |

**Endpoints:** there is no PUT/DELETE in this codebase (convention: POST `/…/{id}` for update,
POST `/…/{id}/delete|void`). Every GET/POST route below gets a cross-tenant test (§23).

## 18. Permissions

The existing keys cover documents: `quotes.*`, `invoices.*`, `payments.*`, `credit_notes.*`, `financial_reports.view`.

**Installments** reuse existing keys: viewing needs `invoices.view`; creating, editing or voiding the
schedule needs `invoices.create` (billing planning is part of invoicing).

**Expenses and budgets have no matching key.** **DECISION REQUIRED (D1):**
- **(a) Recommended:** module-registered keys through the existing `Permission::registerModuleKeys()`.
  This is the same mechanism Contracts and Projects use, with **no Core edit**: `finance.expenses_view`,
  `finance.expenses_manage`, `finance.budgets_view`, `finance.budgets_manage`. A grant migration in
  Finance gives org_admin all of them and members none.
- **(b) No new keys:** reuse `invoices.view` for viewing and `invoices.create` for managing expenses
  and budgets. Simpler, but it mixes "can bill customers" with "can record costs".

The Project finance panel needs at least one of `invoices.view` / `finance.expenses_view` /
`finance.budgets_view` for the respective section, plus `projects.view` on the project (the registry).

`followups.assign` is not touched.

## 19. UI / Dashboard Design

**Project page → Finance panel "Project finance"** (`RelatedPanelRegistry`, subject `project`). It has
one block per currency; sections are hidden without their permission.

```
Revenue        Invoiced · Credited · Net revenue · Paid · Receivable (· Refund due)
Costs          Budget · Actual · Remaining · Variance          (Committed: later, D7)
Profit         Gross profit · Margin %
Cost breakdown table: category × (Budget, Actual, Remaining)
Documents      attributed proformas/invoices (number, date, status, amount) · "Attach existing" · "New proforma/invoice"
Expenses       latest 10 (date, category, vendor, amount, status) · "New expense" · "All expenses"
Budget         "Edit budget"
```

**Contract page → existing Billing panel:**
- the summary gains Credited / Net billed / Refund due;
- a new **"Payment schedule"** table: #, due date, amount, status badge, overdue flag, linked document, actions (create proforma / invoice, edit, void);
- "Add installment".

**Charts:** none at first. The app has no chart component and the design system doesn't define one
(D10). A revenue/cost timeline can come later.

**UI rules:** design-system components only, icon + text buttons, `crm-datepicker` for dates,
`.table-responsive > table.table`, logical CSS, `badge()` for statuses, all text via `t()`.

## 20. Data Model (Finance module migrations; none created yet)

> **Superseded for expenses** by "Phase 4 — Expenses: approved design": the table, migrations 0018 / 0019,
> no `finance_document_links` change. 0017 is now the installments table (Stage 2). The budget table
> below is still a Stage 5 proposal.

| # | Migration | Content |
|---|---|---|
| 0017 | `alter_finance_document_links_add_expense` | `document_type ENUM('proforma','invoice','expense')`. `linked_type` values used: `contract`, `project`, `installment` (VARCHAR, no change). down() restores the ENUM after removing `expense` rows. |
| 0018 | `create_finance_expenses_table` | see below |
| 0019 | `create_finance_budget_lines_table` | see below |
| 0020 | `create_finance_installments_table` | see below |
| 0021 | `grant_finance_expense_budget_permissions` | only if D1 = (a) |

**`finance_expenses`:**
- **Columns:**
  - `id` PK, `organization_id` NOT NULL (FK organizations CASCADE);
  - `category` ENUM(labor, procurement, contractor, shipping, services, other);
  - `description` VARCHAR(255) NOT NULL;
  - `amount` DECIMAL(38,18) NOT NULL, `currency_id` (FK currencies);
  - `expense_date` DATE NOT NULL;
  - `vendor_contact_id` NULL (FK contacts SET NULL), `vendor_company_id` NULL (FK companies SET NULL);
  - `owner_id` (FK users RESTRICT), `team_id` NULL (SET NULL), `department_id` NULL (SET NULL);
  - `status` ENUM(recorded, void) DEFAULT recorded, `voided_at` NULL, `void_reason` VARCHAR(255) NULL, `voided_by_user_id` NULL (SET NULL);
  - `source_type` VARCHAR(30) NOT NULL DEFAULT 'manual', `source_id` INT UNSIGNED NULL;
  - `base_amount` DECIMAL(38,18) NULL, `rate_used` DECIMAL(38,18) NULL (only if D6);
  - `created_by_user_id` (FK users RESTRICT), timestamps.
- **Keys:**
  - KEY (organization_id, expense_date), KEY (organization_id, status, category);
  - UNIQUE (organization_id, source_type, source_id). MySQL allows several NULL `source_id`, so manual entries never collide.

**`finance_budget_lines`:**
- **Columns:**
  - `id` PK, `organization_id` (FK CASCADE);
  - `linked_type` VARCHAR(50) ('project'), `linked_id` INT UNSIGNED (no FK: Projects may be absent);
  - `category` ENUM (same list);
  - `currency_id` (FK), `amount` DECIMAL(38,18) NOT NULL;
  - `notes` VARCHAR(255) NULL;
  - `created_by_user_id`, `updated_by_user_id` NULL, timestamps.
- **Keys:** UNIQUE (organization_id, linked_type, linked_id, category, currency_id); KEY (organization_id, linked_type, linked_id).

**`finance_installments`:**
- **Columns:**
  - `id` PK, `organization_id` (FK CASCADE);
  - `linked_type` VARCHAR(50) ('contract'), `linked_id` INT UNSIGNED (no FK);
  - `sequence` SMALLINT UNSIGNED NOT NULL, `due_date` DATE NOT NULL;
  - `amount` DECIMAL(38,18) NOT NULL, `currency_id` (FK);
  - `description` VARCHAR(255) NULL;
  - `voided_at` NULL, `voided_by_user_id` NULL, `created_by_user_id`, timestamps.
- **Keys:** UNIQUE (organization_id, linked_type, linked_id, sequence); KEY (organization_id, linked_type, linked_id, due_date).

**Reused unchanged:** `invoices`, `proformas`, `payments`, `credit_notes`, `financial_periods`, `financial_profiles`, `currencies`, `exchange_rates`, `project_links`, `contracts`.

Every migration lives in `modules/Finance/Migrations`, has a `down()`, and gets an up → down → up check on `crm_local`.

## 21. API / Controllers / Services (Finance module; routes under the existing `/finance` group: Auth + ModuleMiddleware('finance'))

> **Expense routes and permissions are superseded** by "Phase 4 — Expenses: approved design": the dotted
> keys `finance.expenses.view` / `.manage`, the Finance-specific receipt routes (E6) and the correction
> model (E8). `?from_project=N` for expenses is allowed as a prefill only (D-P1).

| Method | Route | Permission (route) | Validation | Output / errors |
|---|---|---|---|---|
| GET | `/finance/invoices/create?from_project=N` (+ proformas) | `invoices.create` / `quotes.create` | `find('project')` → 404 | prefilled form |
| POST | `/finance/invoices` (+ proformas) with `source_type=project` | same | re-check project → 422; existing create | redirect |
| GET | `/finance/{invoices,proformas}/create?from_installment=N` | create perms | installment tenant + contract `find()` + no active document → 404/422 | prefilled form |
| POST | `/finance/project-links` (`document_type`, `document_id`, `project_id`) | `invoices.edit` (proforma: `quotes.edit`) | document in scope + project `find()` + not attributed yet → 404/422 | redirect to project |
| POST | `/finance/project-links/{linkId}/delete` | same | link tenant + document still draft (D3) → 404/422 | redirect |
| GET | `/finance/expenses` (`?project=N`) | `finance.expenses_view`* | filters | list |
| GET | `/finance/expenses/create?from_project=N` | `finance.expenses_manage`* | project `find()` → 404 | form |
| POST | `/finance/expenses` | manage* | category, amount > 0, active currency, date not locked, vendor tenant, project `find()` → 422 | redirect |
| GET | `/finance/expenses/{id}` · `/{id}/edit` | view* / manage* | tenant + record scope → 404/403 | page |
| POST | `/finance/expenses/{id}` | manage* | same as create; not void; period → 422 | redirect |
| POST | `/finance/expenses/{id}/void` | manage* | reason required; period → 422 | redirect |
| GET / POST | `/finance/budgets/project/{projectId}` | `finance.budgets_view`* / `_manage`* | project `find()`; amounts ≥ 0; active currency; category list | form / redirect |
| POST | `/finance/contracts/{contractId}/installments` | `invoices.create` | contract `find()`; sequence unique; amount > 0; active currency | redirect to contract |
| POST | `/finance/installments/{id}` · `/{id}/void` | `invoices.create` | tenant; no active document (edit) / no issued invoice (void) → 422 | redirect |

\* Or `invoices.view` / `invoices.create` if D1 = (b).

**Services (Finance):**
- `ProjectFinanceService`: panel data, attribution, per-currency aggregation;
- `ExpenseService`;
- `BudgetService`;
- `InstallmentService`: CRUD + derived status + overdue;
- `ContractBillingService`: + `from_installment`, + credit-note effect in the summary;
- `ProformaService` / `InvoiceService`: only the "copy all links" generalization.

**Panels:** `FinancePanels` gains subject `project`; the contract panel gains the schedule section.

## 22. Translation Keys (≈70 keys × 5 languages; final list in the implementation)

`finance.project_finance`, `finance.revenue`, `finance.invoiced`, `finance.credited`,
`finance.net_revenue`, `finance.paid`, `finance.receivable`, `finance.refund_due`, `finance.costs`,
`finance.budget`, `finance.actual_cost`, `finance.remaining_budget`, `finance.variance`,
`finance.gross_profit`, `finance.margin`, `finance.cost_breakdown`, `finance.attach_document`,
`finance.no_rate`, `finance.expense.*` (title, new, edit, category.{labor,procurement,contractor,shipping,services,other},
amount, date, vendor, status.{recorded,void}, void, void_reason, flash.*, error.*),
`finance.budget.*` (title, edit, line, flash.saved, error.*), `finance.installment.*` (title, add,
sequence, due_date, amount, status.{unpaid,issued,partially_paid,paid,void}, overdue,
create_proforma, create_invoice, void, flash.*, error.{has_active_document, already_issued, …}),
`finance.contract.net_billed`.

No hard-coded UI text. `ContractFinanceTest`-style key and render checks run in all five languages.

## 23. Test Plan (new suites; the whole existing suite stays green)

**Project finance:**
- **Expenses:**
  - create (each category);
  - edit, refused when void or in a locked period;
  - void (excluded from totals, row kept);
  - vendor of another organization → 422;
  - inactive currency → 422.
- **Revenue:**
  - aggregation only from issued, attributed invoices;
  - drafts listed but not counted; cancelled ones excluded;
  - payments aggregation, including partial;
  - receivable and refund due.
- **Credit notes:** partial and full; net revenue in the invoice's own net/gross proportion.
- **Profit / margin:** exact `bc` values; margin "—" when revenue ≤ 0.
- **Budget:** lines, totals, remaining, variance per category and currency.
- **Multi-currency:** USD revenue + EUR cost → two blocks, no mixed sum, no margin across them.
- **Duplicate prevention:**
  - a document attributed to a second project → refused;
  - re-approving a timesheet source → no second expense (when built);
  - "copy all links" on conversion → exactly one link per type.

**Contract schedule:**
- create, edit, void (with each refusal);
- due date and computed overdue;
- proforma, invoice and convert from an installment;
- duplicate document refused;
- payment status transitions (unpaid → issued → partially_paid → paid);
- cancel invoice → back to unpaid;
- credit note → effective amount; fully credited → paid;
- contract summary with credited / net billed / refund due.

**Security (every route in §21):**
- cross-tenant project, contract, expense, budget line, installment and document ids → 404 and nothing written;
- out-of-scope project or contract (own scope) → 404 / 422;
- missing permission → 403;
- module off (Finance, Projects, Contracts) → panels hidden, routes 404, data kept;
- CSRF on every POST.

**Regression:**
- all 396 existing tests;
- Finance flows without project or contract;
- `ContractFinanceTest` (summary values change only by the credit-note columns);
- standalone suites.

**Calculations untouched:** totals of documents created through the new paths are identical to the same input without them.

## 24. Mutation Test Plan

Each mutation must turn at least one test red:
1. tenant check removed (expense `findById` unscoped; budget / installment repository unscoped);
2. currency validation removed (expense, budget, installment);
3. duplicate prevention removed (second active document per installment; second project per document);
4. revenue source swapped (Paid used as revenue; drafts counted);
5. credit-note effect removed (summary, installment status, net revenue);
6. payment effect removed (receivable ignores `paid_total`);
7. profit formula changed (gross instead of net of tax; cost of void expenses counted);
8. budget variance removed (remaining = budget);
9. permission check removed (route RBAC and service record scope);
10. project ownership check removed (`find('project')` bypassed on attach / from_project / expense);
11. period lock removed on expense create / void;
12. cross-currency sum introduced.

## 25. Migration Plan

**Order:** Finance `0017` → `0018` → `0019` → `0020` (→ `0021` if D1 = a). All additive except `0017`,
which widens an ENUM; its `down()` removes `expense` rows first. No Core migration, and no migration in
Projects or Contracts.

**Each stage:**
1. back up;
2. up → down → up on `crm_local`;
3. run the full suite.

**Production:** back up → upload → `/system/migrate` → smoke test.

**Implementation stages (each small, tested, separately approved if you prefer):**

| Stage | Content | Acceptance |
|---|---|---|
| 1 | Credit-note effect in the contract Billing summary | new values exact; existing contract-finance tests adjusted only where the summary gained columns; full suite green |
| 2 | Installments (0020) + schedule section + `from_installment` + derived status/overdue + copy-all-links | state-machine and duplicate tests; security tests; full suite green |
| 3 | Project attribution (from_project, attach / detach) + panel revenue block | revenue / receivable tests; no double count across contract/project lenses |
| 4 | Expenses (0017, 0018 [+0021]) + panel cost block | CRUD/void/period/vendor/currency tests |
| 5 | Budget (0019) + variance + profit / margin | formula tests per currency |
| 6 (optional, D6) | base-currency converted totals | needs the Core rate-at-date method |
| later phase | Timesheet (Projects) + labor rates + event bridge | separate audit |

## 26. Risks / Edge Cases

- **Cancelling an invoice that has payments** (pre-existing, Finance): those payments disappear from
  every total, including Paid and Receivable. Recommend a separate small Finance fix (refuse to cancel
  when `paid_total > 0`). Reported, not done here.
- **Finance period report sums across currencies** (pre-existing). Recommend a separate fix (group by
  currency). Reported, not done here.
- **Installment amount ≠ invoice amount** (the user edits the prefilled invoice): status follows the
  invoice; the schedule shows both amounts.
- **A contract linked to several projects:** its documents are attributed to one project each, by
  hand. The panel lists unattributed contract documents to make that easy.
- **Partially paid invoice later credited** below the paid amount → "Refund due" (no refund workflow exists).
- **Expense in a currency later deactivated:** the stored row stays valid; new entries need an active currency.
- **Project deleted** (only draft / planned / cancelled can be): links stay with their label snapshot;
  panels don't render; Finance totals are unaffected.
- **Membership vs `find()`:** an own-scope project member who can see a project through membership
  can't attach documents or expenses to it (stricter, safe; same as Follow-ups).
- **Rounding:** all aggregation in `bc` at scale 18; display rounding only in templates.

## 27. Files Expected to Change (when implemented)

- **Finance (modified):**
  - `FinanceModule.php`, `FinancePanels.php`, `FinanceDocumentLinkRepository.php`;
  - `Services/ContractBillingService.php`, `Services/ProformaService.php`, `Services/InvoiceService.php` (copy-all-links only);
  - `Controllers/ProformaController.php`, `Controllers/InvoiceController.php`;
  - the `_contract_panel.php` and `_contract_source.php` templates.
- **Finance (new):**
  - migrations 0017–0020 (+0021);
  - repositories and services for expenses, budgets, installments and project finance;
  - `Controllers/ExpenseController.php`, `BudgetController.php`, `InstallmentController.php`, `ProjectFinanceController.php` (attach / detach);
  - templates `finance/_project_panel.php`, `finance/expenses/*`, `finance/budgets/form.php`, `finance/_installments.php`.
- **Translation files** (5).
- **Tests:** new suites; `ContractFinanceTest` summary expectations (stage 1).
- **Docs:** CHANGELOG, this document.
- **No change:** Core, Projects, Contracts, Sales, routes/web.php, `Permission.php` (unless D1 = b, which also needs none).

## 28. Out-of-Scope Changes (recorded, not proposed for this work)

- Fixing invoice cancel with payments and the cross-currency period report: separate Finance patches.
- Refund workflow (`payments.refund` unused).
- Purchase orders / committed cost, vendor bills (accounts payable), tax on expenses.
- Timesheet itself, labor rates and project tasks / milestones (later phase, §6).
- Non-monetary contract obligations (deliverables).
- Budget versions / baselines; charts.
- `followups.assign`.

## 29. Decisions Required From Me

| # | Decision | Recommendation |
|---|---|---|
| **D1** | **BLOCKER (permissions):** expenses and budgets need access keys | (a) module-registered `finance.expenses_view/_manage`, `finance.budgets_view/_manage` via `registerModuleKeys` (no Core edit) + a grant migration; or (b) reuse `invoices.view/create` |
| **D2** | Revenue basis for profit | net invoiced (accrual, excluding tax, minus credit notes in proportion); cash shown separately as Paid / Receivable |
| **D3** | Detaching an **issued** document from a project | not allowed (only drafts); prevents silent rewriting of reported revenue |
| **D4** | Expense amounts | entered as the cost to the company (net of recoverable tax); no tax on expenses in this phase |
| **D5** | Contract value on the project page | shown by Projects' existing contract list (a small Projects change, read through the registry), **or** not shown in this phase (no Projects change). Recommendation: not now. |
| **D6** | **BLOCKER only if yes:** base-currency converted totals | not now. Per-currency only. A later step adds a Core `ExchangeRateRepository::rateOn()` plus frozen `base_amount` on expenses. |
| **D7** | Committed cost | not now (no purchase orders exist) |
| **D8** | Sum of installments ≠ contract value | warn on the schedule, don't block |
| **D9** | Installment statuses | unpaid / issued / partially_paid / paid / void, with overdue as a flag |
| **D10** | Charts / timeline | not in this phase |
| **D11** | Order of implementation | Stage 1 (credit-note effect) and Stage 2 (installments) first, completing Contract + Finance; then project finance (Stages 3–5) |
