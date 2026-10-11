# Contract ↔ Finance integration — audit and design

Status: **implemented (2026-10-09), all five stages, per decisions D1–D5.**
Baseline 341 → **366 passed, 0 failed, 0 skipped** (new suite `ContractFinanceTest`, 25 tests).

Implementation notes (inside the approved design):
- **Unique key is tenant-local:** `UNIQUE (organization_id, document_type, document_id, linked_type)`.
  Test P5 showed that the first draft's global key would let a row of one organization interfere
  with another's. The migration was not deployed yet and was re-run with a rollback check.
- **Integration code lives in Finance-owned classes:**
  - `Services/ContractBillingService` (prefill, `createLinked`, `linkOf`, `panelFor`);
  - `Services/DocumentHeaderGuard` (F1);
  - `FinancePanels`.
  `ProformaService` / `InvoiceService` only gained the F1 call, the conversion link copy and the
  link cleanup on delete, all through optional constructor parameters.
- **Panel rows** are read through the existing `ProformaService::find()` / `InvoiceService::find()`,
  so Finance's permission + record scope apply per document. With OWN scope the summary covers
  only the documents the user may see.
- **Fixtures:** issued/paid invoices in the tests are set by writing the stored fields. `issue()`
  and payments open their own DB transaction, which can't nest in the suite's rollback transaction.
- **Wiring bug caught by the tests:** C1 found a construction-order bug in `FinanceModule` before
  shipping (the proforma controller was built before the integration service). The negative tests
  alone would not have shown it, because "not available" is the safe default.
- **Proof:**
  - The F1 tests fail against the unpatched services.
  - C3 proves the created totals are identical with and without a contract.
  - 9 mutation checks are all caught:
    - the POST contract re-check;
    - the registry bypass;
    - the per-row scope bypass;
    - drafts counted in the summary;
    - the F1 contact check;
    - the F1 active-currency check;
    - the panel-button contract check (P7);
    - the conversion carry-over;
    - the link cleanup on delete.

Conditions set with the approval, binding for the implementation:
1. Existing financial calculations do not change (tax, totals, numbering, payments, credit notes, periods).
2. Organization isolation is kept everywhere (documents, links, contacts, contracts).
3. If either module is disabled for the user, the integration is disabled under the current
   access policy (see B.6). Nothing is deleted.

The last integration of the original plan (`docs/contracts-projects-design.md` §4.3/§5 step 4):
"Contract + Finance: create a proforma / invoice from a contract; the contract shows billed /
paid / balance by reading the linked documents. Finance stays unaware of contracts."

Hard rule from `CLAUDE.md`: **financial calculations are not changed.** Every amount is still
produced by the existing `ProformaService` / `InvoiceService` / `TaxCalculationService`.

---

## A. Audit

### A.1 Finance today (module `finance`, addon, no dependencies)

| Area | Facts |
|---|---|
| Documents | `proformas` (status `draft, sent, accepted, rejected, expired, converted`; `converted_invoice_id`), `invoices` (status `draft, issued, cancelled`; `paid_total`), `payments`, `credit_notes` |
| Party | **`contact_id` only.** There is no company, deal or source column on either document |
| Money | `currency_id` FK + `DECIMAL(38,18)`, `subtotal / tax_total / grand_total`, computed by `TaxCalculationService` from lines + tax profile + pricing mode |
| Lifecycle | Proforma → `createFromProforma()` → **draft** invoice (copies header + lines) → `issue()` (org_admin, numbered `INV-00001`, immutable) → payments / credit notes |
| Permissions | Core constants: `quotes.view/create/edit/delete/convert`, `invoices.view/create/edit/delete/issue/cancel`, `payments.*` |
| Services | Bound by the old `instanceof FinanceModule` block in `Application` (`ProformaService`, `InvoiceService`, `PaymentService`, `ProductService`) |
| Extension points | Finance uses **none**: no `RecordTypeRegistry` type, no `RelatedPanelRegistry` panel, no events |
| Create pages | `/finance/proformas/create` and `/finance/invoices/create` take **no prefill** (`showCreate()` renders an empty form) |
| Atomicity | `ProformaService::create()` inserts the header, then the lines, without a transaction (pre-existing) |
| Tests | No `tests/lite` suite covers Finance |

### A.2 Contracts today (relevant parts, unchanged since Step 2)

- `contracts`:
  - parties: `contact_id` and/or `company_id`;
  - money: `value DECIMAL(38,18)` + `currency_id`;
  - other: `start_date / end_date`, `terms`, `status`.
- The contract page has the related-panel slot (`subject = contract`); Projects and Follow-ups already add panels there.
- The `contract` record type has a finder: `RecordTypeRegistry::find('contract', $id, $user)` = module available + `contracts.view` + same organization + record scope.
- `contract_links` (owned by Contracts) was designed to hold "invoice later", but Contracts can only validate a target through `RecordTypeRegistry`. Finance registers no record types.
- The architecture test forbids any other module's namespace or table name in Contracts' code. **Contracts cannot call Finance.**

### A.3 Findings

| # | Finding | Severity | Proposed |
|---|---|---|---|
| F1 | **Pre-existing cross-tenant leak in Finance.** `ProformaService` / `InvoiceService::validatedHeader()` accept any `contact_id` (FK only, no organization check). The list queries show the name through an **unscoped** subquery (`SELECT … FROM contacts WHERE contacts.id = i.contact_id` in `InvoiceRepository:29` / `ProformaRepository:30`). A tampered form can therefore make another organization's contact name appear in your lists. `currency_id` is not checked for being active either. | medium | Fix in this phase (D4): validate `contact_id` through the tenant-scoped `ContactRepository`. Calculations untouched. |
| F2 | There is no link between a contract and its billing documents. The contract page can't show billed / paid. | — (the feature gap) | this design |
| F3 | Invoices have no company: a contract with a **company only** has no party to carry over. | — | prefill leaves the contact empty and says so (B.3) |
| F4 | `ProformaService::create()` isn't atomic (header without lines possible on a crash). | low | not changed; the new linked create wraps its own work in one transaction (B.4) |

### A.4 Where the integration can live (decoupling)

| Option | Contracts | Finance | Verdict |
|---|---|---|---|
| **A. Finance owns it** | unchanged | reads the contract only through `RecordTypeRegistry::find('contract')`; stores its own links; adds a panel to the contract page | ✅ **Recommended.** Same pattern as Projects and Follow-ups. |
| B. Contracts owns it (`contract_links` + Finance registers `proforma` / `invoice` record types) | must call Finance to create documents, which **breaks the architecture rule / test** | small change too | ❌ |
| C. Add `source_type` / `source_id` columns to `proformas` / `invoices` | unchanged | alters the core financial tables | ❌ (schema change on money tables, avoidable) |

---

## B. Design (option A)

### B.1 Data: one new Finance-owned link table (module migration `finance/0016`)

```
finance_document_links
  id, organization_id → organizations CASCADE,
  document_type ENUM('proforma', 'invoice'),
  document_id INT UNSIGNED,              -- proformas.id / invoices.id (same module)
  linked_type VARCHAR(50),               -- 'contract' (RecordTypeRegistry key)
  linked_id INT UNSIGNED,                -- NO FK: Contracts may not be installed
  linked_label VARCHAR(255),             -- snapshot shown when Contracts is off / record gone
  created_by_user_id → users SET NULL, created_at
  UNIQUE (organization_id, document_type, document_id, linked_type)  -- a document belongs to at most one contract
  KEY (organization_id, linked_type, linked_id)
```

- The table is purely additive: **existing proforma, invoice and payment tables are not altered**.
- It has a `down()`. Deleting a proforma or invoice (draft only, existing rules) also deletes its
  link row, inside the same service call. `document_id` has no FK because the column points at two tables.
- `linked_type` stays generic (`contract` only now), so a project or deal link later needs no new migration.

### B.2 Contract page: Finance panel ("Billing")

`Modules\Finance\FinancePanels` registers a panel for subject `contract` through `RelatedPanelRegistry`
(module `finance`).

**What it shows:**
- Proformas linked to the contract (only with `quotes.view`; own scope applied as in `ProformaService::list`).
- Invoices linked to the contract (only with `invoices.view`; same scope rule).
- **Summary, per currency:**
  - *billed* = Σ `grand_total` of **issued** invoices;
  - *paid* = Σ `paid_total`;
  - *balance* = billed − paid;
  - the contract value beside it when its currency matches.
- Drafts and cancelled invoices are listed but not counted. This is read-only addition of stored
  totals (`bcadd`); no tax or price is recomputed.

**Buttons:**
- "New proforma" (with `quotes.create`) and "New invoice" (with `invoices.create`).
- Each is shown only when `find('contract')` succeeds, so a button never leads to a refusal.

The panel returns `''` (hidden) when the user holds neither view permission.

### B.3 Create from a contract (existing URLs + one query parameter)

- `GET /finance/proformas/create?from_contract=N` and `GET /finance/invoices/create?from_contract=N`:
  - the contract is read with `RecordTypeRegistry::find('contract', N, $user)`; if it isn't viewable → **404**;
  - the form is **prefilled**, not saved:
    - **contact:** the contract's contact (empty for a company-only contract, with a notice);
    - **currency:** the contract's currency (else the organization default);
    - **one line:** description = contract title (+ number), quantity 1, unit price = contract value (empty when the contract has no value);
    - **terms:** the contract's terms;
    - **issue date:** today;
    - **tax profile / pricing mode:** the organization's defaults, as in an empty form.
  - The user reviews everything; **nothing financial is decided by the integration**.
  - A "created from contract X" notice appears, re-derived on the server.
- **POST (existing create routes)** with hidden `source_type=contract` / `source_id=N`:
  1. the contract is **re-checked on the server** with `find()`; a tampered or unavailable source → 422, nothing created;
  2. then the existing `create()` runs, unchanged;
  3. the link row is inserted;
  4. all of this in one transaction (`createLinked()` in each service).
  A contract timeline entry follows ("Proforma / Invoice … created from this contract"). The
  `contract` type has timeline support, so Finance writes it through Core `TimelineService`;
  no Contracts code is involved.
- **Conversion:** `createFromProforma()` copies the proforma's contract link to the new invoice in
  the same flow. That is one extra insert after the existing conversion; the conversion logic itself is unchanged.

### B.4 Finance hardening (F1, decision D4)

In `ProformaService` and `InvoiceService::validatedHeader()`:
- `contact_id` must be found through the tenant-scoped `ContactRepository`, else `ValidationException`;
- `currency_id` must exist and be active (`CurrencyRepository`).

No calculation, status or number logic changes. Existing valid documents are unaffected; the check only runs on create and update.

### B.5 Access summary

| Action | Needs |
|---|---|
| See the Billing panel | Finance available + `quotes.view` and/or `invoices.view` (each section separately); rows in the user's existing Finance scope |
| Prefill / create from a contract | the existing create permission (`quotes.create` / `invoices.create`) **and** `find('contract')` (Contracts available + `contracts.view` + record scope + same organization) |
| Issue, pay, cancel, credit | unchanged Finance rules (`invoices.issue` = org_admin, …) |

Organization isolation: every query is tenant-scoped. The panel reads links with explicit `organization_id`, documents through Finance's own scoped repositories, and the contract only through the registry.

### B.6 Module disabled → integration disabled (current access policy, nothing new)

Module access is the existing chain: installed → globally active → organization entitled → per-user
override, through `ModuleGate`, `ModuleMiddleware` and `RelatedPanelRegistry`.

| State (for this user) | Effect |
|---|---|
| Finance off | Its routes 404 (existing `ModuleMiddleware`). The Billing panel isn't rendered (`RelatedPanelRegistry` checks module `finance`). Contracts works as today. |
| Contracts off | No contract page, so no panel. `?from_contract=` → 404 (`find()` returns null). A POST with `source_type=contract` → 422, nothing created. Already linked documents open normally in Finance; the "created from contract" line shows the stored label without a link. |
| Both on, but the user lacks `contracts.view` or is outside the contract's record scope | Same as "Contracts off" for that contract |
| Both on, but the user lacks `quotes.*` / `invoices.*` | The matching panel section and button are hidden; the Finance routes refuse as today |
| Re-enabled | Everything reappears; links were kept |

Link rows are **never deleted** because a module is turned off; this is the same rule as `contract_links` and `project_links`.

### B.7 Files

- **Finance (modified):**
  - `FinanceModule.php`: wiring of the registry, panel and link repository;
  - `Services/ProformaService.php`, `Services/InvoiceService.php`: `createLinked()`, conversion copies the link, link removed on delete, B.4 validation;
  - `Controllers/ProformaController.php`, `Controllers/InvoiceController.php`: `?from_contract=` prefill and the source re-check on POST;
  - the two form templates: notice + hidden fields.
- **Finance (new):**
  - `Migrations/0016_create_finance_document_links_table.php`
  - `FinanceDocumentLinkRepository.php`
  - `FinancePanels.php`
  - `themes/default/templates/finance/_contract_panel.php`
- **Unchanged:**
  - Contracts, Projects, Sales;
  - Core and `Permission.php`;
  - `TaxCalculationService`, numbering, payments, credit notes, periods.
- About 15 translation keys × 5 languages; tests; CHANGELOG; this document.

### B.8 Tests (new suite `ContractFinanceTest`, crm_local, rolled back)

**Feature:**
1. Prefill from a contract (contact, currency, line, terms) for both proforma and invoice. A
   company-only contract gets an empty contact plus a notice. A contract without a value gets an empty price.
2. Create from a contract: the link is stored, a timeline entry lands on the contract, and the
   totals are **identical** to the same input submitted without a contract (proves calculations untouched).
3. Convert a linked proforma to an invoice: the invoice is linked to the same contract.
4. Panel:
   - lists proformas and invoices;
   - summary counts only issued invoices, per currency;
   - after a payment, paid and balance update;
   - drafts and cancelled invoices are not counted.

**Security / isolation:**
5. `?from_contract=` with a missing id, another organization's contract, or a contract out of
   scope (`contracts.view` own) → 404. A tampered POST `source_id` (foreign or missing) or a
   `source_type` other than `contract` → 422, no document, no link.
6. The panel shows only documents in the user's Finance scope (own), and never another
   organization's link or document. Each section is hidden without its view permission.
7. **F1:**
   - a proforma or invoice with another organization's `contact_id` → 422;
   - an inactive or unknown currency → 422;
   - the list never shows a foreign contact name.

**Module states:**
8. Contracts off → no panel (no page), `from_contract` 404, and existing linked documents still
   open normally. Finance off → no panel on the contract page.
9. The standalone suites keep working: Contracts without Finance, Projects without Finance.

**Regression:**
10. Proforma/invoice create, edit, delete, convert, issue, payment and credit note without any
    contract, exactly as before. The whole suite (341) stays green.
11. Translations: new keys in all five languages; the panel and prefilled forms render in fa/ar/en/tr/fr.

**Mutation checks:**
- remove the server re-check of the contract;
- remove the panel scope filter;
- count draft invoices in "billed";
- drop the F1 contact check.

## C. Decisions (final, approved 2026-10-09)

| # | Decision | Consequence in this design |
|---|---|---|
| **D1** | **Finance owns the integration; Contracts is not changed.** | Option A (A.4). Finance reads the contract only through `RecordTypeRegistry::find('contract')`, stores its own links (B.1) and adds its own panel (B.2). No Contracts file, test or architecture rule changes. |
| **D2** | **Scope:** create proforma/invoice from a contract, store the link, show the billing summary. **Obligations / payment schedule are a separate, later step.** | B.1–B.5 only. No `contract_obligations`, no schedule, no automatic invoicing. |
| **D3** | **A document belongs to at most one contract; a contract can have many documents.** | `UNIQUE (document_type, document_id, linked_type)` in `finance_document_links`. Conversion copies the proforma's single link to the new invoice. |
| **D4** | **Fix F1 in this step.** Server-side organization validation of `contact_id` and an active-currency check. | B.4: `ProformaService` / `InvoiceService::validatedHeader()` validate through the tenant-scoped `ContactRepository` and `CurrencyRepository`, giving 422 otherwise. Covered by test 7. |
| **D5** (superseded 2026-10-10 by Stage 1 of `docs/project-finance-design.md`: credits now reduce the summary) | **"Billed" = issued invoices only. Credit notes are not reflected in the summary for now.** | B.2 summary: Σ `grand_total` / `paid_total` of `status = 'issued'` invoices, per currency. Drafts and cancelled invoices are listed but not counted. Credit notes are untouched and still shown in Finance. |

### Out of scope (recorded, not done in this step)

- Contract obligations / payment schedule, and invoices generated from them (D2).
- Credit notes in the contract summary (D5).
- Links from Finance documents to projects or deals. The table allows it later without a migration.
- Making `ProformaService::create()` atomic in general (F4). Only the new linked create runs in one transaction.
- A company party on invoices (F3). Finance's data model is unchanged.

### Implementation order (after the separate go-ahead)

1. Migration `finance/0016` + `FinanceDocumentLinkRepository` (+ rollback check).
2. F1 hardening (B.4) + tests 7 and 10. This is the security part first, on its own.
3. `createLinked()` + `?from_contract=` prefill + POST re-check + conversion carry-over (B.3) + tests 1–3 and 5.
4. Billing panel (B.2) + tests 4, 6 and 8.
5. Translations, standalone/module-state tests (9, 11), mutation checks, docs, CHANGELOG, `upload.zip`.

`php tests/lite/run.php` runs after every stage. Anything that turns out to need a change outside
Finance (architecture, Contracts, Core, permissions) stops the work and is reported first.
