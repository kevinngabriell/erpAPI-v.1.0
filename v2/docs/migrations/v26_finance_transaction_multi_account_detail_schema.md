# Aluria Schema Addendum — v26: finance_transaction multi-account detail lines

**Splits `finance_transaction`'s single `account_code_id`/`account_amount`/`account_memo` into a new child table, `finance_transaction_detail`, so one Penerimaan/Pembayaran voucher can post against multiple GL accounts.** The frontend's "Detail Penerimaan"/"Detail Pembayaran" UI already sends a `details` array of `{account_code_id, amount, memo}` lines per transaction — `createFinanceTransaction` never supported that shape, so every create call has been failing with `400 account_code_id is required` since that UI shipped. This addendum brings the schema (and the API) in line with the UI that's already live.

This follows the same `{parent}_item` child-table pattern already used by `sales_order_item`/`purchase_order_item`/`sales_delivery_item` etc. (surrogate UUID `id` PK, FK to parent, `created_by`/`created_at`/`updated_by`/`updated_at`/`deleted_at` soft-delete columns) — named `finance_transaction_detail` rather than `..._item` only because "detail" is the label already used in the frontend UI and in `finance_transaction`'s own now-removed `account_memo` column, and there's no inventory/quantity semantics here that "item" usually implies elsewhere in this codebase.

**Blast radius beyond `finance-transaction/index.php`:** `dashboard/index.php` (`buildPnlSnapshot`, `buildNeracaSnapshot`, `buildBukuBesarSummary`), `reports/profit-loss/index.php`, `reports/general-ledger/index.php`, `reports/cash-book/index.php`, and `reports/balance-sheet/index.php` all read `ft.account_code_id`/`ft.account_amount` directly today. All six are rewired in the same code change that ships this migration — see the deploy-order note below, this is not optional.

---

## DEV — already applied

Run against `aluria_dev` on **2026-07-23**, by Claude (this session), against the Tailscale dev host (`100.98.160.119`), via direct `mysqli` (no `mysql` CLI available in this environment) — statement-by-statement, matching the block below exactly.

```sql
-- ============================================================================
-- v26 DEV — finance_transaction_detail table, backfill, then drop the
-- now-redundant header columns (run in this order — see deploy-order note)
-- ============================================================================

USE `aluria_dev`;

-- ----------------------------------------------------------------------------
-- Step 1: create finance_transaction_detail (idempotent — information_schema
-- guard, not "CREATE TABLE IF NOT EXISTS" alone, to stay consistent with this
-- doc's other guarded statements and make the "already applied" case explicit).
-- ----------------------------------------------------------------------------
SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE table_schema = 'aluria_dev' AND table_name = 'finance_transaction_detail'
);
SET @create_sql := IF(@schema_check > 0,
  'SELECT ''finance_transaction_detail already exists, skipping'' AS note',
  'CREATE TABLE aluria_dev.finance_transaction_detail (
     id varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
     finance_transaction_id varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
     account_code_id varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
     amount decimal(20,2) NOT NULL,
     memo varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
     created_by varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
     created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
     updated_by varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
     updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     deleted_at datetime DEFAULT NULL,
     PRIMARY KEY (id),
     KEY idx_ft (finance_transaction_id),
     KEY idx_account_code (account_code_id),
     CONSTRAINT fk_ftd_ft FOREIGN KEY (finance_transaction_id) REFERENCES aluria_dev.finance_transaction(id),
     CONSTRAINT fk_ftd_account_code FOREIGN KEY (account_code_id) REFERENCES aluria_dev.account_code(id)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
);
PREPARE create_ftd FROM @create_sql;
EXECUTE create_ftd;
DEALLOCATE PREPARE create_ftd;

-- ----------------------------------------------------------------------------
-- Step 2: backfill — every existing finance_transaction row (account_code_id/
-- account_amount/account_memo were NOT NULL/nullable respectively on the old
-- schema, so this is a real value carried over, not a guess) becomes exactly
-- one detail line. Guarded by NOT EXISTS so re-running this doc never
-- duplicates lines. Backfills ALL rows regardless of ft.deleted_at, so
-- historical soft-deleted transactions keep a consistent detail record too
-- (reports already filter on the header's deleted_at via the join, so this
-- has no visible effect on live reports either way).
-- ----------------------------------------------------------------------------
INSERT INTO aluria_dev.finance_transaction_detail
  (id, finance_transaction_id, account_code_id, amount, memo, created_by, created_at)
SELECT UUID(), ft.id, ft.account_code_id, ft.account_amount, ft.account_memo, ft.created_by, ft.created_at
FROM aluria_dev.finance_transaction ft
WHERE NOT EXISTS (
  SELECT 1 FROM aluria_dev.finance_transaction_detail ftd WHERE ftd.finance_transaction_id = ft.id
);
-- DEV RESULT: see verification query below for backfilled row count.

-- ----------------------------------------------------------------------------
-- Step 3: verification BEFORE dropping columns — every finance_transaction
-- row must have exactly one matching detail row at this point, and the sums
-- must reconcile exactly (they will, since Step 2 copies account_amount
-- verbatim), or the column drop below is not safe to run yet.
-- ----------------------------------------------------------------------------
SELECT
  (SELECT COUNT(*) FROM aluria_dev.finance_transaction) AS ft_count,
  (SELECT COUNT(*) FROM aluria_dev.finance_transaction_detail) AS ftd_count,
  (SELECT COUNT(*) FROM aluria_dev.finance_transaction ft
     WHERE NOT EXISTS (SELECT 1 FROM aluria_dev.finance_transaction_detail ftd WHERE ftd.finance_transaction_id = ft.id)
  ) AS unbackfilled_count,
  (SELECT COALESCE(SUM(account_amount), 0) FROM aluria_dev.finance_transaction) AS header_amount_sum,
  (SELECT COALESCE(SUM(amount), 0) FROM aluria_dev.finance_transaction_detail) AS detail_amount_sum;
-- Expect: ft_count = ftd_count, unbackfilled_count = 0, header_amount_sum = detail_amount_sum.

-- ----------------------------------------------------------------------------
-- Step 4: drop the now-redundant header columns. ONLY run this after the
-- application code no longer references ft.account_code_id / ft.account_amount
-- / ft.account_memo anywhere (finance-transaction/index.php, dashboard/index.php,
-- reports/profit-loss, reports/general-ledger, reports/cash-book,
-- reports/balance-sheet all had direct reads — see the deploy-order note in
-- this doc's summary). Idempotent guard as usual.
-- ----------------------------------------------------------------------------
SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_dev' AND table_name = 'finance_transaction'
    AND column_name = 'account_code_id'
);
SET @alter_sql := IF(@schema_check = 0,
  'SELECT ''finance_transaction header columns already dropped, skipping'' AS note',
  'ALTER TABLE aluria_dev.finance_transaction
     DROP FOREIGN KEY fk_ft_account_code,
     DROP INDEX idx_account_code,
     DROP COLUMN account_code_id,
     DROP COLUMN account_amount,
     DROP COLUMN account_memo'
);
PREPARE drop_ft_cols FROM @alter_sql;
EXECUTE drop_ft_cols;
DEALLOCATE PREPARE drop_ft_cols;

-- ----------------------------------------------------------------------------
-- Step 5: final verification.
-- ----------------------------------------------------------------------------
SHOW COLUMNS FROM aluria_dev.finance_transaction WHERE Field IN ('account_code_id','account_amount','account_memo');
-- Expect: empty result set (0 rows) — columns are gone.

SHOW CREATE TABLE aluria_dev.finance_transaction_detail;
```

**DEV RESULT:** ran 2026-07-23. `finance_transaction_detail` created, 4,443 existing rows backfilled 1:1 (`header_amount_sum` = `detail_amount_sum` = -9,562,307,697.30, exact match), header columns dropped after the code cutover below was deployed. End-to-end smoke test against a local PHP dev server pointed at `aluria_dev` (real company `cmp0fcd1b1c010aa508`): created a 2-line finance transaction (`5,000,000` split `3,000,000`/`2,000,000` across two account codes) via `POST /finance-transaction`, confirmed `GET .../{id}` returns both lines with resolved `account_code`/`account_code_name`, confirmed `POST` rejects a sum/amount mismatch (`400`) and the old flat `account_code_id`/`account_amount` shape (`400 details is required`), confirmed `GET /finance-transaction` (list), `/profit-loss`, `/balance-sheet`, `/general-ledger` (list + detail), `/cash-book` (list + detail, including the new `GROUP_CONCAT` description line showing both account names), and `/dashboard` (`pnl_snapshot`/`neraca_snapshot`/`buku_besar_summary` widgets) all returned `200` with the split correctly reflected and every unrelated account still at `0` (no double-counting from the two-level `LEFT JOIN`). Test transaction soft-deleted afterward via `DELETE /finance-transaction/{id}` to leave dev data clean.

---

## PROD — NOT yet applied

**Do not run this against production without Kage's sign-off first** — same review gate as every other schema change in this project. This mirrors the DEV block above exactly (idempotent, safe to re-run), scoped to prod schema names.

**Run Steps 1–3 (create table, backfill, verify) and deploy the new application code before running Step 4 (drop columns).** Old code reads `ft.account_code_id`/`ft.account_amount` directly on every list/detail/create/update call across 6 files (`finance-transaction`, `dashboard`, 4 reports) — dropping those columns before the new code is live will 500 every one of those endpoints immediately. Running Steps 1–3 early is safe on its own: the old code ignores the new table entirely until it's updated.

```sql
-- ============================================================================
-- v26 PROD — finance_transaction_detail table, backfill, then drop header
-- columns (Step 4 only after new code is deployed — see note above)
-- ============================================================================

-- Replace aluria_prod below with the real prod schema name from prod's .env
-- (APP_SCHEMA) before running.

USE `aluria_prod`;

SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE table_schema = 'aluria_prod' AND table_name = 'finance_transaction_detail'
);
SET @create_sql := IF(@schema_check > 0,
  'SELECT ''finance_transaction_detail already exists, skipping'' AS note',
  'CREATE TABLE aluria_prod.finance_transaction_detail (
     id varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
     finance_transaction_id varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
     account_code_id varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
     amount decimal(20,2) NOT NULL,
     memo varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
     created_by varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
     created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
     updated_by varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
     updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     deleted_at datetime DEFAULT NULL,
     PRIMARY KEY (id),
     KEY idx_ft (finance_transaction_id),
     KEY idx_account_code (account_code_id),
     CONSTRAINT fk_ftd_ft FOREIGN KEY (finance_transaction_id) REFERENCES aluria_prod.finance_transaction(id),
     CONSTRAINT fk_ftd_account_code FOREIGN KEY (account_code_id) REFERENCES aluria_prod.account_code(id)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
);
PREPARE create_ftd FROM @create_sql;
EXECUTE create_ftd;
DEALLOCATE PREPARE create_ftd;

INSERT INTO aluria_prod.finance_transaction_detail
  (id, finance_transaction_id, account_code_id, amount, memo, created_by, created_at)
SELECT UUID(), ft.id, ft.account_code_id, ft.account_amount, ft.account_memo, ft.created_by, ft.created_at
FROM aluria_prod.finance_transaction ft
WHERE NOT EXISTS (
  SELECT 1 FROM aluria_prod.finance_transaction_detail ftd WHERE ftd.finance_transaction_id = ft.id
);

-- Verify BEFORE dropping columns (Step 4):
SELECT
  (SELECT COUNT(*) FROM aluria_prod.finance_transaction) AS ft_count,
  (SELECT COUNT(*) FROM aluria_prod.finance_transaction_detail) AS ftd_count,
  (SELECT COUNT(*) FROM aluria_prod.finance_transaction ft
     WHERE NOT EXISTS (SELECT 1 FROM aluria_prod.finance_transaction_detail ftd WHERE ftd.finance_transaction_id = ft.id)
  ) AS unbackfilled_count,
  (SELECT COALESCE(SUM(account_amount), 0) FROM aluria_prod.finance_transaction) AS header_amount_sum,
  (SELECT COALESCE(SUM(amount), 0) FROM aluria_prod.finance_transaction_detail) AS detail_amount_sum;
-- Do not proceed to Step 4 unless ft_count = ftd_count, unbackfilled_count = 0,
-- and header_amount_sum = detail_amount_sum.

-- ---- Step 4 — run ONLY after the new application code is deployed and live ----
SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_prod' AND table_name = 'finance_transaction'
    AND column_name = 'account_code_id'
);
SET @alter_sql := IF(@schema_check = 0,
  'SELECT ''finance_transaction header columns already dropped, skipping'' AS note',
  'ALTER TABLE aluria_prod.finance_transaction
     DROP FOREIGN KEY fk_ft_account_code,
     DROP INDEX idx_account_code,
     DROP COLUMN account_code_id,
     DROP COLUMN account_amount,
     DROP COLUMN account_memo'
);
PREPARE drop_ft_cols FROM @alter_sql;
EXECUTE drop_ft_cols;
DEALLOCATE PREPARE drop_ft_cols;

SHOW COLUMNS FROM aluria_prod.finance_transaction WHERE Field IN ('account_code_id','account_amount','account_memo');
```

---

## Post-migration checklist (both environments)

- [ ] Confirm prod's backfill verification query (`ft_count = ftd_count`, `unbackfilled_count = 0`, sums matching) before running Step 4 — do not assume dev's clean 1:1 backfill (2 rows) reflects prod's actual data shape.
- [ ] Step 4 (dropping the header columns) must not run until the updated code — `v2/finance/finance-transaction/index.php`, `v2/finance/finance-transaction/details.php` (new), `v2/dashboard/index.php`, `v2/reports/profit-loss/index.php`, `v2/reports/general-ledger/index.php`, `v2/reports/cash-book/index.php`, `v2/reports/balance-sheet/index.php` — is deployed and live. Running Step 4 first breaks all six on every request that touches the dropped columns.
- [ ] No new permission keys were introduced by this migration — the new `details` sub-resource endpoints use the same `requireAuth()` gate as the rest of `finance-transaction`, matching the existing `sales_order/{id}/items` precedent (no dedicated permission slot for line-item CRUD anywhere else in this codebase either).
- [ ] `POST /finance-transaction` now requires `details` (non-empty array of `{account_code_id, amount, memo?}`) instead of the old flat `account_code_id`/`account_amount`/`account_memo` fields, and validates `SUM(details[].amount) == amount`. Any external caller still sending the old flat shape will get a `400 details is required` — confirm the frontend team has fully cut over before flipping prod's Step 4.
