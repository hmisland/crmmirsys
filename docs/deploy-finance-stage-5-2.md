# Deployment — Finance Stage 5.2 (L1–L5 finalization)

Verified on the LOCAL database only (`crm_local`, MySQL 8.4.3, InnoDB,
REPEATABLE-READ). Production has not been connected to, migrated or verified.
Details of the change: `docs/finance-stage-5-2-finalization.md`.

## Packages

| File | Use |
|---|---|
| `upload.zip` | Full deployable tree (same script as always: `tools/build-upload.ps1`). Safe choice when you are not sure what is on the server. |
| `finance-stage-5-2.zip` | Only the 50 files that changed since the previous package (`upload.zip` of 2026-10-10, SHA-256 `923ed619…48f2`). Use it only if the server runs exactly that previous package. |
| `finance-stage-5-2-docs.zip` | Documentation for your records — do NOT upload to the server. |

Neither server package contains `.env`, `ddddd/`, `docs/`, `tests/`, SQL dumps,
logs, mail, uploads or any credentials. `.env` on the server stays as it is.

## 1. Before deployment (backups and checks)

1. **Full database backup** (cPanel → Backup → MySQL database, or `mysqldump --single-transaction --routines`). Keep it until the smoke tests below pass.
2. **Code backup:** download the current server files of `app/`, `config/`, `lang/`, `modules/`, `themes/`, `routes/`, `public/`, `.htaccess` (or keep the previous `upload.zip`, which is the same thing if it was deployed unchanged).
3. **Which Finance migrations are already applied** (phpMyAdmin → SQL):
   ```sql
   SELECT migration FROM module_migrations WHERE module_key = 'finance' ORDER BY migration;
   ```
   Expected last row: `0022_add_contract_reference_to_finance_expenses`. If earlier ones are missing too, `/system/migrate` will apply them as well — stop and review before continuing.
4. **Database engine and isolation level** (required for the period locking, L5):
   ```sql
   SELECT table_name, engine FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN ('invoices','payments','credit_notes','financial_periods','finance_expenses',
                         'invoice_number_series','credit_note_number_series','timeline_events','financial_profiles');
   SELECT @@GLOBAL.transaction_isolation, @@SESSION.transaction_isolation;
   ```
   Every engine must be `InnoDB`; isolation should be `REPEATABLE-READ`. If not, do not claim production-safe period locking — report it first.
5. PHP 8.1+ with the `bcmath` extension (already required by Finance).

## 2. Deployment order

1. (Optional) announce a short maintenance window — no invoice, payment or credit-note work during the upload.
2. Upload and extract **`upload.zip`** (or `finance-stage-5-2.zip`) into the CRM root, overwriting files. Do not delete `.env` or `storage/`.
3. Run the migrations — code and schema must go together (the new code writes the new columns / table):
   - open `https://crmgroup.ir/system/migrate?token=<MIGRATE_TOKEN>`
   - **order:** Finance `0023_create_finance_submission_keys_table`, then `0024_add_invoice_final_rounding` (the migrator runs them in this order automatically).
   - expected response contains `"finance":["0023_create_finance_submission_keys_table","0024_add_invoice_final_rounding"]` (plus nothing else if step 1.3 matched).
4. Clear / blank `MIGRATE_TOKEN` in `.env` again afterwards.
5. If the host uses OPcache, reset it (cPanel → PHP selector / restart, or wait for revalidation).

## 3. Post-deployment smoke tests

Read-only checks first:

```sql
SELECT migration FROM module_migrations WHERE module_key = 'finance' ORDER BY migration DESC LIMIT 2;  -- 0024, 0023
SHOW TABLES LIKE 'finance_submission_keys';                                                            -- 1 row
SHOW COLUMNS FROM invoices LIKE 'unrounded_total';                                                     -- 1 row
SELECT COUNT(*) FROM financial_profiles WHERE invoice_final_rounding <> 0;                              -- 0 (default OFF)
SELECT COUNT(*) FROM invoices WHERE final_rounding IS NOT NULL;                                         -- 0 right after deploy (no backfill)
```

In the browser (as an organization admin):

1. Finance → Settings: the "Round the final payable amount of new invoices" checkbox is shown and unticked. Do not save unless you want to change it.
2. Finance → Invoices: list loads; open an existing issued invoice — totals identical to before, no "Total before rounding" rows; print / PDF open.
3. Finance → Periods: page loads (history card appears only after a close / reopen).
4. Open a draft invoice: next to "Issue" there is the rounding checkbox (unticked, "0 decimals" for IRR). Do not issue a test invoice in Production unless it is real.
5. Contact page, client record (invoices tab), contract / project billing panels, Finance → Reports, Expenses: pages load, amounts unchanged.
6. When the next real payment / credit note is recorded: the invoice page shows a "Financial history" card with it (only for users with `payments.view` / `credit_notes.view`).
7. Check `storage/logs/` for new errors.

## 4. Rollback

The schema changes are additive and the previous code ignores them, so the
**recommended rollback is code-only**:

1. Re-upload the previous package (`upload.zip` of 2026-10-10, SHA-256 `923ed619…48f2`) or the code backup from step 1.2.
2. Leave migrations 0023 / 0024 in place: the extra table and columns are unused by the old code and keep their data (submission keys, rounding snapshots, audit events). Invoices issued with rounding keep their rounded payable as `grand_total`, which is the legitimate amount.

Full schema rollback (only if really needed, after the code rollback, with a fresh backup):

```sql
ALTER TABLE invoices DROP COLUMN rounding_adjustment, DROP COLUMN unrounded_total, DROP COLUMN rounding_precision, DROP COLUMN final_rounding;
ALTER TABLE financial_profiles DROP COLUMN invoice_final_rounding;
DELETE FROM module_migrations WHERE module_key = 'finance' AND migration = '0024_add_invoice_final_rounding';
DROP TABLE IF EXISTS finance_submission_keys;
DELETE FROM module_migrations WHERE module_key = 'finance' AND migration = '0023_create_finance_submission_keys_table';
```

Dropping the 0024 columns loses the "before rounding / adjustment" record of
invoices issued with rounding; dropping 0023 removes duplicate-submission
protection. Restoring the database backup is the alternative if nothing has
been recorded since the deployment.

## Addendum — Finance help guides (core migration 0078)

Shipped in the same `upload.zip`: 12 Finance help guides in 5 languages and
"?" buttons on the Finance pages. `/system/migrate` also applies core
migration `0078_seed_finance_help_articles` (data only; it inserts guides
that don't exist yet and never overwrites edited ones). Expected in the
response: `"core":["0078_seed_finance_help_articles"]`. Check afterwards:

```sql
SELECT COUNT(*) FROM help_articles WHERE slug LIKE 'finance-%';   -- 12
```

Rollback (only if wanted): `DELETE FROM help_articles WHERE slug LIKE 'finance-%';`
and `DELETE FROM migrations WHERE migration = '0078_seed_finance_help_articles';`
(the translations are removed by the foreign key).

## Production status

PRODUCTION VERIFICATION PENDING — confirm InnoDB, isolation level, and locking
behavior before claiming Production-safe period locking.
