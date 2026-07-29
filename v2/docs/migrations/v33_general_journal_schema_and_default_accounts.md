# Aluria Schema Addendum — v33: General Journal + default account mapping

**Adds a non-cash General Journal so `reports/balance-sheet`, `reports/general-ledger`, and `reports/profit-loss` reflect real invoice/payment activity.** All three reports read exclusively from `account_code` ⟵ `finance_transaction_detail` ⟵ `finance_transaction` — a cash book (`finance_transaction.bank_account_id` is `NOT NULL`, every row represents a real bank/cash movement). Sales/purchase invoice approval (`approveSalesInvoice()`, `approvePurchaseInvoice()`) and payment settlement (`approveFinancePayment()`) only ever wrote to `finance_payment` (a disconnected AR/AP subledger — confirmed zero references to `account_code` anywhere in that file), so approved invoices never moved Piutang Usaha/Hutang Usaha/Revenue on any of the three reports. Confirmed against `aluria_dev` company `cmp0fcd1b1c010aa508` before this migration: GL-side Piutang Usaha summed to **Rp 0** across 316 `finance_transaction_detail` rows while `finance_payment` (the real subledger) showed **~-Rp 11 billion** net outstanding — the two were completely disconnected.

Legacy v1 (`finance/getneraca.php`) never had this problem because it computes Piutang/Hutang/Modal live from the invoice subledger directly, with no chart-of-accounts/GL layer at all.

## Code change (already applied, this session)

- New tables `general_journal`/`general_journal_detail` (DDL below) — mirrors `finance_transaction`/`finance_transaction_detail` column style, but with **no `bank_account_id`** (that's the entire point — it exists to record entries that have no cash leg) and no `approved_by_owner_id`/`approved_by_treasury_id` columns. Every row this migration's code creates is system-generated from an already-approved invoice/payment — same reasoning `v31` used for `finance_payment` baseline rows: the source document's own approval is the authorization, a second manual sign-off would be redundant. `transaction_status` defaults straight to `'posted'`.
- New helper `v2/helpers/general_journal.php` — `getDefaultAccountCode()` and `postGeneralJournalEntry()`. Sign convention matches `finance_transaction_detail` exactly: positive amount increases that account's own natural balance (debit-normal for asset/expense, credit-normal for liability/equity/revenue). `postGeneralJournalEntry()` validates the entry balances (`SUM(debit_equivalent) == 0`, converting each line via its `account_code.account_type`) before inserting — throws if not, same style as `createFinanceTransaction()`'s own `detail_sum !== amount` check.
- New read-only module `v2/finance/general-journal/index.php` (`GET /api/v2/general-journal`, `GET /api/v2/general-journal/{id}`) for auditability. **No create/update/delete/approve** — nothing today hand-keys a journal entry, only system postings exist.
- `account_code` gets 4 new nullable boolean columns — `is_default_receivable`, `is_default_payable`, `is_default_sales_revenue`, `is_default_purchase_expense` — settable via the existing `PUT /api/v2/account-code/{id}` (and at creation). Only one account per company can hold a given flag; setting it on one account clears it from every other account of that company. Each flag is restricted to the matching `account_type` (e.g. `is_default_receivable` only on an `asset` account) — enforced in `createAccountCode()`/`updateAccountCode()`, `400` otherwise.
- `bank_account` gets a new nullable `account_code_id` FK — maps a bank/cash register to its GL asset account code. **Confirmed there was zero link between `bank_account` and `account_code` before this** — they only coincidentally share similar names in seed data (e.g. dev's `bank_account` rows are named "Test Bank c750" etc., completely unrelated to the "BANK BCA IDR..." `account_code` rows). Restricted to `account_type = 'asset'`, enforced in `createBankAccount()`/`updateBankAccount()`.
- Hooks: `approveSalesInvoice()` (`v2/sales/sales-invoice/index.php`) posts `[Piutang Usaha: +total, Revenue: +total]` right after its existing `seedFinancePaymentBaseline()` call. `approvePurchaseInvoice()` (`v2/purchase/purchase-invoice/index.php`) posts `[Expense: +total, Hutang Usaha: +total]` the same way — no inventory/COGS layer exists anywhere in the codebase (confirmed), so this posts straight to a default expense account. `approveFinancePayment()` (`v2/finance/finance-payment/index.php`), once `applyDualApproval()` flips `transaction_status` to `'posted'`, posts the cash-side settlement using the payment's `bank_account_id` → `bank_account.account_code_id` mapping. **All three skip posting (never block the approval, never guess/fabricate an account) if a required default account or bank mapping is missing** — logged via `insertAuditLog(..., 'general_journal', ..., 'gl_posting_skipped', ..., '<reason>')` so the gap is discoverable.
- `reports/balance-sheet`, `reports/profit-loss`, `reports/general-ledger`: the `account_code` ⟵ `finance_transaction_detail` ⟵ `finance_transaction` join in each is replaced with a `LEFT JOIN` onto a derived table that `UNION ALL`s that same join with `general_journal_detail` ⟵ `general_journal`, projecting `(account_code_id, amount, transaction_date)`. `general-ledger`'s per-transaction detail list additionally gets a `source` field (`'cash_transaction'` | `'general_journal'`) and a unified `reference_number` (was `voucher_number`-only) per row.

**Explicitly out of scope** (so it isn't assumed later): per-line-item account mapping on invoices (no column/UI exists for it — one configurable default account per role, company-wide, is what this ships), a manual journal-entry CRUD/approval UI, and inventory/COGS recognition.

## DEV — already applied

Run against `aluria_dev` on **2026-07-26**, via direct `mysqli` through `v2/connection/db.php` (no `mysql` CLI in this environment, same as prior migrations in this doc series). Idempotent via the `information_schema` guard + `PREPARE`/`EXECUTE` pattern `v26` uses.

```sql
-- ============================================================================
-- v33 DEV — general_journal + general_journal_detail tables, account_code
-- default-account flags, bank_account.account_code_id mapping
-- ============================================================================

USE `aluria_dev`;

CREATE TABLE aluria_dev.general_journal (
  id varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  company_id varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  journal_number varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  transaction_date date NOT NULL,
  memo varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  source_module varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  source_document_id varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  transaction_status enum('draft','submitted','partially_approved','posted','rejected') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'posted',
  created_by varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_company_date (company_id, transaction_date),
  KEY idx_source (source_module, source_document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE aluria_dev.general_journal_detail (
  id varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  general_journal_id varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  account_code_id varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  amount decimal(20,2) NOT NULL,
  memo varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  created_by varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_gj (general_journal_id),
  KEY idx_account_code (account_code_id),
  CONSTRAINT fk_gjd_gj FOREIGN KEY (general_journal_id) REFERENCES aluria_dev.general_journal(id),
  CONSTRAINT fk_gjd_account_code FOREIGN KEY (account_code_id) REFERENCES aluria_dev.account_code(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE aluria_dev.account_code
  ADD COLUMN is_default_receivable TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN is_default_payable TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN is_default_sales_revenue TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN is_default_purchase_expense TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE aluria_dev.bank_account
  ADD COLUMN account_code_id varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  ADD CONSTRAINT fk_ba_account_code FOREIGN KEY (account_code_id) REFERENCES aluria_dev.account_code(id);

-- Verification
SHOW CREATE TABLE aluria_dev.general_journal;
SHOW CREATE TABLE aluria_dev.general_journal_detail;
DESCRIBE aluria_dev.account_code;   -- expect the 4 new is_default_* columns
DESCRIBE aluria_dev.bank_account;   -- expect account_code_id
```

**DEV RESULT:** ran 2026-07-26. Both tables created; re-run confirmed idempotent (second pass hit the `already exists, skipping` branch on every statement, no errors). All 4 `account_code` columns and `bank_account.account_code_id` confirmed present via `DESCRIBE`.

### Configuration applied for `cmp0fcd1b1c010aa508` (dev company)

No default accounts existed before this migration — set for testing/backfill purposes via the extended `account-code`/`bank-account` endpoints (equivalent direct `UPDATE`s used in this session):
- `is_default_receivable` → `105-013-000 PIUTANG USAHA`
- `is_default_payable` → `205-006-000 HUTANG USAHA`
- `is_default_sales_revenue` → `401-001-000 PENDAPATAN LAIN-LAIN`
- `is_default_purchase_expense` → `502-004-000 BEBAN LAIN-LAIN` (generic "other expense" — no line-item mapping exists yet, see out-of-scope note above)
- `bank_account` "Test Bank c750" → `account_code_id` = `101-003-000 BANK BCA IDR 8015.373.888` (dev seed data has no real bank-to-account correspondence; picked arbitrarily to exercise the payment-settlement path end to end)

### Backfill — already applied

Same reasoning as `v31`'s `finance_payment` backfill: the code change only fixes new approvals going forward; already-`Approved` invoices and already-`posted` payments needed a one-time backfill or they'd stay invisible forever. Run via a one-off PHP script through `v2/connection/db.php` (same tool used for `v32`), looping documents missing a `general_journal` row (`NOT EXISTS` guard on `source_module`/`source_document_id` — idempotent, safe to re-run), calling the same `postGeneralJournalEntry()`/wrapper functions the live code paths use.

**DEV RESULT:**
- Sales invoices: **1 already posted** (during interactive testing, before the bulk backfill ran) for invoice `ffffffffff` — Piutang Usaha and Revenue both moved by `Rp 6,877,500,000`. No further approved sales invoices were missing a journal row.
- Purchase invoices: **2 posted** (`DEV-AL/L/VII/2026/001` — Rp 2,016,000; `DEV-AL/26/VII/00` — Rp 1,200). **136 skipped** — their `purchase_invoice_item` totals sum to exactly `Rp 0` (no item rows, or all-zero items — matches the same test-seed pattern `v31` found: "2 of the 4 have no purchase_invoice_item rows at all"). Correctly skipped, not fabricated.
- Finance payments: **0 posted, 245 skipped** — every one of this company's `finance_payment` rows with a `bank_account_id` points at a bank ("Test Bank 9cf7" etc.) that has no `account_code_id` mapped (only "Test Bank c750" was mapped, for the one end-to-end test above). Each skip logged an `audit_log` row (`module='general_journal'`, `action='gl_posting_skipped'`) with the specific reason — **245 audit_log rows**, all `'backfill: bank_account has no mapped account_code_id'`.
- Balance sheet, `as_of_date` = today, before → after this migration + backfill: Total Assets `-Rp 9,562,307,697.30` → `-Rp 2,684,807,697.30` (Piutang Usaha now `+Rp 6,877,500,000`); Total Liabilities `Rp 6,000,000` → `Rp 8,017,200` (the 2 backfilled purchase invoices); Total Equity unchanged at `Rp 0` (no equity postings in this migration — see `v32` for the separate equity-account gap).

---

## PROD — NOT yet applied

**Do not run this against production without Kage's sign-off first** — same review gate as every other schema/data change in this project. Mirrors the DEV block above exactly, scoped to prod schema names. **Before running the backfill in PROD**, re-derive real default-account and bank-mapping configuration with prod's actual accounting team — the DEV configuration above (e.g. mapping "Test Bank c750" arbitrarily) was for testing this migration's mechanics only and must not be copied into PROD as-is.

```sql
-- Replace aluria_prod below with the real prod schema name from prod's .env (APP_SCHEMA) before running.

USE `aluria_prod`;

CREATE TABLE aluria_prod.general_journal ( -- identical DDL to DEV above, schema name only differs
  id varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  company_id varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  journal_number varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  transaction_date date NOT NULL,
  memo varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  source_module varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  source_document_id varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  transaction_status enum('draft','submitted','partially_approved','posted','rejected') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'posted',
  created_by varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_company_date (company_id, transaction_date),
  KEY idx_source (source_module, source_document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE aluria_prod.general_journal_detail (
  id varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  general_journal_id varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  account_code_id varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  amount decimal(20,2) NOT NULL,
  memo varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  created_by varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_gj (general_journal_id),
  KEY idx_account_code (account_code_id),
  CONSTRAINT fk_gjd_gj FOREIGN KEY (general_journal_id) REFERENCES aluria_prod.general_journal(id),
  CONSTRAINT fk_gjd_account_code FOREIGN KEY (account_code_id) REFERENCES aluria_prod.account_code(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE aluria_prod.account_code
  ADD COLUMN is_default_receivable TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN is_default_payable TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN is_default_sales_revenue TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN is_default_purchase_expense TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE aluria_prod.bank_account
  ADD COLUMN account_code_id varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  ADD CONSTRAINT fk_ba_account_code FOREIGN KEY (account_code_id) REFERENCES aluria_prod.account_code(id);

-- Verification
SHOW CREATE TABLE aluria_prod.general_journal;
SHOW CREATE TABLE aluria_prod.general_journal_detail;
DESCRIBE aluria_prod.account_code;
DESCRIBE aluria_prod.bank_account;

-- Backfill: run the same one-off PHP script used in DEV, pointed at prod, only
-- after prod's default accounts + bank_account.account_code_id mappings are
-- configured for real by the accounting team (not the arbitrary DEV test mapping).
```

**Deploy order:** the code change (hooks + report joins) must land in production before the backfill matters going forward, but the backfill itself is independent and can run before or after the code deploy — it only touches historical rows already `Approved`/`posted` at backfill time.

---

## Post-migration checklist

- [ ] **This company's Piutang/Hutang/Revenue numbers now come from two disconnected sources that happen to agree only where both are configured**: `finance_payment` (real subledger, 587 rows, ~-Rp 11B outstanding) vs `general_journal` (new, only backfilled where a default account + bank mapping existed — 245 payment rows skipped entirely). The Balance Sheet will **not** match `finance_payment`'s outstanding total until every bank account gets an `account_code_id` mapping. Check `audit_log` for `module='general_journal' AND action='gl_posting_skipped'` to find every gap — this is the authoritative "what's still missing" list, not a bug to silently work around.
- [ ] **Balance sheet still does not balance** (Assets ≠ Liabilities + Equity) — this migration only wires AR/AP/Revenue/Expense recognition; it does not address the pre-existing equity gap `v32` already flagged (no opening-balance/retained-earnings entry exists for the historical -Rp 9.56B asset swing).
- [ ] Map every real bank account's `account_code_id` (via `PUT /api/v2/bank-account/{id}`) and configure all 4 default accounts (via `PUT /api/v2/account-code/{id}`) for each company before relying on these reports for real decisions — nothing posts until this is done, by design (no guessing).
- [ ] Get Kage's sign-off, then run the PROD block above — including configuring PROD's own default accounts/bank mappings with the accounting team before backfilling, not copying DEV's test configuration.
- [ ] `docs/api/finance-payment.md`, a new `docs/api/general-journal.md`, and `docs/api/account-code.md`/`docs/api/bank-account.md` need regenerating via `/standards` to reflect the new endpoint and fields.
- [ ] New permission keys: none introduced — `general-journal` reuses the same `requireAuth()` gate as every other module; no dual-approval workflow exists for it (system-generated only).
