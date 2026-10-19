# Aluria Schema Addendum — v40: unique constraints for duplicate prevention (CRIT-12)

Adds soft-delete-aware `UNIQUE` indexes to the 11 `APP_SCHEMA` tables that carry a business-facing document/party number, closing the race-condition gap where two concurrent `create{Thing}()` calls can generate the same display number and both insert successfully (the app-layer `SELECT … LIMIT 1` duplicate check is not atomic under concurrency). The database-level constraint makes the second insert fail with `1062` instead of silently creating a duplicate.

## Why a generated column, not `deleted_at` directly

Every target table uses `deleted_at DATETIME NULL` for soft deletes (a row is active when `deleted_at IS NULL`, soft-deleted when it holds a timestamp). Putting `deleted_at` straight into a `UNIQUE` index does **not** work for this use case: MySQL treats each `NULL` in a unique index as distinct, so two active rows (both `deleted_at = NULL`) with the same display number would **not** collide — exactly the duplicate we want to prevent — while two soft-deleted rows (different timestamps) would be treated as distinct anyway. The net effect of indexing `deleted_at` directly would be "allow active duplicates, allow deleted duplicates" — i.e. no protection at all.

The fix (same trick already used by `subscription_payment.pending_company_key` in [[v39]] and `app_payment.pending_invoice_key` in core) is a **virtual generated column**:

```sql
ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL
```

- Active row → `deleted_flag = 0` (a real, non-`NULL` value) → two active rows with the same `(company_id, display_number, 0)` **do** collide → duplicate insert rejected.
- Soft-deleted row → `deleted_flag = NULL` → each soft-deleted row is distinct in the unique index → multiple historical deletes with the same display number are allowed (correct — they are gone from the active set).

The unique index is then `(company_id, <display_column>, deleted_flag)`. `VIRTUAL` (not `STORED`) is used so the column consumes no disk — MySQL 8 materializes the value in the secondary index only.

## Schema reconciliation note

This addendum maps the CRIT-12 spec's column names to the **actual** column names found in the live `APP_SCHEMA` (derived from `v2/` PHP source, not assumed). Four names in the original spec did not match the schema:

| Spec said | Actual column | Table | Action |
|-----------|---------------|-------|--------|
| `name` | `customer_name` | `customer` | Used `customer_name` |
| `delivery_number` | `do_display_number` | `sales_delivery` | Used `do_display_number` |
| `sppb_number` | `sppb_display_number` | `sales_sppb` | Used `sppb_display_number` |
| `receiving_number` | *(column does not exist)* | `purchase_receive` | **Deferred — see below** |

**`purchase_receive` is excluded from this migration.** It has no human-facing display/document-number column at all (`DESCRIBE`-equivalent via `v2/purchase/purchase-receive/index.php`: `id, company_id, purchase_order_id, supplier_id, receiving_date, ship_via_id, status_id, approved_by, created_by, created_at, updated_by, deleted_at`). It is identified by its `id` and FK-linked to `purchase_order_id`. A `(company_id, purchase_order_id, deleted_flag)` constraint would be **wrong** — multiple receives against one PO are legitimate (partial shipments). Adding a duplicate-prevention constraint here requires a prior migration to add and backfill a `receiving_number` column; that is a separate task and is **not** included here.

Final constraint set (11 tables):

| Table | Display column | Index name |
|-------|---------------|------------|
| `purchase_order` | `po_display_number` | `uq_po_display_active` |
| `sales_order` | `so_display_number` | `uq_so_display_active` |
| `purchase_invoice` | `invoice_display_number` | `uq_pinv_display_active` |
| `sales_invoice` | `invoice_display_number` | `uq_sinv_display_active` |
| `sales_delivery` | `do_display_number` | `uq_sd_display_active` |
| `sales_sppb` | `sppb_display_number` | `uq_sppb_display_active` |
| `customer` | `customer_name` | `uq_customer_name_active` |
| `supplier` | `supplier_name` | `uq_supplier_name_active` |
| `product` | `product_code` | `uq_product_code_active` |
| `finance_transaction` | `voucher_number` | `uq_ft_voucher_active` |
| `finance_payment` | `invoice_number` | `uq_fp_invoice_active` |

> `product.product_code` is nullable ([[v34]]), and `finance_transaction.voucher_number` / `finance_payment.invoice_number` may be `NULL` when the caller omits the field. `NULL` values in the unique index are distinct, so rows with no code/number are never blocked — only non-`NULL` active duplicates collide. This matches the app-layer duplicate checks, which also skip when the value is empty.

## DEV — already applied

Run against `aluria_dev` on **2026-10-17**, by Claude (this session). All statements are idempotent via the `information_schema` guard + `PREPARE`/`EXECUTE` pattern ([[v21]]/[[v34]] style — not `ADD COLUMN IF NOT EXISTS`, which needs MySQL 8.0.29+ and errors on older servers).

```sql
-- ============================================================================
-- v40 DEV — soft-delete-aware UNIQUE constraints on document/party numbers
-- ============================================================================

USE `aluria_dev`;

-- ----------------------------------------------------------------------------
-- STEP 0 — DUPLICATE AUDIT (read-only). The ADD UNIQUE KEY in Step 1 will fail
-- with errno 1062 if active duplicates already exist. Run this first; every
-- query below must return 0 rows before Step 1 is safe. If any return rows,
-- resolve the duplicates manually (merge or soft-delete the surplus) before
-- proceeding. Re-runnable.
-- ----------------------------------------------------------------------------
SELECT company_id, po_display_number, COUNT(*) AS dup_count FROM aluria_dev.purchase_order      WHERE deleted_at IS NULL AND po_display_number      IS NOT NULL GROUP BY company_id, po_display_number      HAVING COUNT(*) > 1;
SELECT company_id, so_display_number, COUNT(*) AS dup_count FROM aluria_dev.sales_order         WHERE deleted_at IS NULL AND so_display_number      IS NOT NULL GROUP BY company_id, so_display_number      HAVING COUNT(*) > 1;
SELECT company_id, invoice_display_number, COUNT(*) AS dup_count FROM aluria_dev.purchase_invoice WHERE deleted_at IS NULL AND invoice_display_number IS NOT NULL GROUP BY company_id, invoice_display_number HAVING COUNT(*) > 1;
SELECT company_id, invoice_display_number, COUNT(*) AS dup_count FROM aluria_dev.sales_invoice    WHERE deleted_at IS NULL AND invoice_display_number IS NOT NULL GROUP BY company_id, invoice_display_number HAVING COUNT(*) > 1;
SELECT company_id, do_display_number, COUNT(*) AS dup_count FROM aluria_dev.sales_delivery      WHERE deleted_at IS NULL AND do_display_number       IS NOT NULL GROUP BY company_id, do_display_number       HAVING COUNT(*) > 1;
SELECT company_id, sppb_display_number, COUNT(*) AS dup_count FROM aluria_dev.sales_sppb         WHERE deleted_at IS NULL AND sppb_display_number    IS NOT NULL GROUP BY company_id, sppb_display_number    HAVING COUNT(*) > 1;
SELECT company_id, customer_name, COUNT(*) AS dup_count FROM aluria_dev.customer               WHERE deleted_at IS NULL AND customer_name           IS NOT NULL GROUP BY company_id, customer_name           HAVING COUNT(*) > 1;
SELECT company_id, supplier_name, COUNT(*) AS dup_count FROM aluria_dev.supplier               WHERE deleted_at IS NULL AND supplier_name           IS NOT NULL GROUP BY company_id, supplier_name           HAVING COUNT(*) > 1;
SELECT company_id, product_code, COUNT(*) AS dup_count FROM aluria_dev.product                 WHERE deleted_at IS NULL AND product_code            IS NOT NULL GROUP BY company_id, product_code            HAVING COUNT(*) > 1;
SELECT company_id, voucher_number, COUNT(*) AS dup_count FROM aluria_dev.finance_transaction    WHERE deleted_at IS NULL AND voucher_number          IS NOT NULL GROUP BY company_id, voucher_number          HAVING COUNT(*) > 1;
SELECT company_id, invoice_number, COUNT(*) AS dup_count FROM aluria_dev.finance_payment        WHERE deleted_at IS NULL AND invoice_number          IS NOT NULL GROUP BY company_id, invoice_number          HAVING COUNT(*) > 1;

-- ----------------------------------------------------------------------------
-- STEP 1 — add deleted_flag generated column + UNIQUE index per table.
-- Idempotency: CASE on three states —
--   (a) index already exists  → skip (fully applied)
--   (b) column exists, index missing → ADD UNIQUE KEY only
--   (c) neither exists        → ADD COLUMN + ADD UNIQUE KEY together
-- Re-runnable.
-- ----------------------------------------------------------------------------

-- purchase_order (company_id, po_display_number, deleted_flag)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema='aluria_dev' AND table_name='purchase_order' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='purchase_order' AND index_name='uq_po_display_active');
SET @alter_sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''purchase_order: uq_po_display_active already exists, skipping'' AS note'
  WHEN @col_exists > 0 THEN 'ALTER TABLE aluria_dev.purchase_order ADD UNIQUE KEY uq_po_display_active (company_id, po_display_number, deleted_flag)'
  ELSE 'ALTER TABLE aluria_dev.purchase_order ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_po_display_active (company_id, po_display_number, deleted_flag)'
END;
PREPARE stmt FROM @alter_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- sales_order (company_id, so_display_number, deleted_flag)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema='aluria_dev' AND table_name='sales_order' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='sales_order' AND index_name='uq_so_display_active');
SET @alter_sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''sales_order: uq_so_display_active already exists, skipping'' AS note'
  WHEN @col_exists > 0 THEN 'ALTER TABLE aluria_dev.sales_order ADD UNIQUE KEY uq_so_display_active (company_id, so_display_number, deleted_flag)'
  ELSE 'ALTER TABLE aluria_dev.sales_order ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_so_display_active (company_id, so_display_number, deleted_flag)'
END;
PREPARE stmt FROM @alter_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- purchase_invoice (company_id, invoice_display_number, deleted_flag)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema='aluria_dev' AND table_name='purchase_invoice' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='purchase_invoice' AND index_name='uq_pinv_display_active');
SET @alter_sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''purchase_invoice: uq_pinv_display_active already exists, skipping'' AS note'
  WHEN @col_exists > 0 THEN 'ALTER TABLE aluria_dev.purchase_invoice ADD UNIQUE KEY uq_pinv_display_active (company_id, invoice_display_number, deleted_flag)'
  ELSE 'ALTER TABLE aluria_dev.purchase_invoice ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_pinv_display_active (company_id, invoice_display_number, deleted_flag)'
END;
PREPARE stmt FROM @alter_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- sales_invoice (company_id, invoice_display_number, deleted_flag)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema='aluria_dev' AND table_name='sales_invoice' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='sales_invoice' AND index_name='uq_sinv_display_active');
SET @alter_sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''sales_invoice: uq_sinv_display_active already exists, skipping'' AS note'
  WHEN @col_exists > 0 THEN 'ALTER TABLE aluria_dev.sales_invoice ADD UNIQUE KEY uq_sinv_display_active (company_id, invoice_display_number, deleted_flag)'
  ELSE 'ALTER TABLE aluria_dev.sales_invoice ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_sinv_display_active (company_id, invoice_display_number, deleted_flag)'
END;
PREPARE stmt FROM @alter_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- sales_delivery (company_id, do_display_number, deleted_flag)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema='aluria_dev' AND table_name='sales_delivery' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='sales_delivery' AND index_name='uq_sd_display_active');
SET @alter_sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''sales_delivery: uq_sd_display_active already exists, skipping'' AS note'
  WHEN @col_exists > 0 THEN 'ALTER TABLE aluria_dev.sales_delivery ADD UNIQUE KEY uq_sd_display_active (company_id, do_display_number, deleted_flag)'
  ELSE 'ALTER TABLE aluria_dev.sales_delivery ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_sd_display_active (company_id, do_display_number, deleted_flag)'
END;
PREPARE stmt FROM @alter_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- sales_sppb (company_id, sppb_display_number, deleted_flag)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema='aluria_dev' AND table_name='sales_sppb' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='sales_sppb' AND index_name='uq_sppb_display_active');
SET @alter_sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''sales_sppb: uq_sppb_display_active already exists, skipping'' AS note'
  WHEN @col_exists > 0 THEN 'ALTER TABLE aluria_dev.sales_sppb ADD UNIQUE KEY uq_sppb_display_active (company_id, sppb_display_number, deleted_flag)'
  ELSE 'ALTER TABLE aluria_dev.sales_sppb ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_sppb_display_active (company_id, sppb_display_number, deleted_flag)'
END;
PREPARE stmt FROM @alter_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- customer (company_id, customer_name, deleted_flag)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema='aluria_dev' AND table_name='customer' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='customer' AND index_name='uq_customer_name_active');
SET @alter_sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''customer: uq_customer_name_active already exists, skipping'' AS note'
  WHEN @col_exists > 0 THEN 'ALTER TABLE aluria_dev.customer ADD UNIQUE KEY uq_customer_name_active (company_id, customer_name, deleted_flag)'
  ELSE 'ALTER TABLE aluria_dev.customer ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_customer_name_active (company_id, customer_name, deleted_flag)'
END;
PREPARE stmt FROM @alter_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- supplier (company_id, supplier_name, deleted_flag)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema='aluria_dev' AND table_name='supplier' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='supplier' AND index_name='uq_supplier_name_active');
SET @alter_sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''supplier: uq_supplier_name_active already exists, skipping'' AS note'
  WHEN @col_exists > 0 THEN 'ALTER TABLE aluria_dev.supplier ADD UNIQUE KEY uq_supplier_name_active (company_id, supplier_name, deleted_flag)'
  ELSE 'ALTER TABLE aluria_dev.supplier ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_supplier_name_active (company_id, supplier_name, deleted_flag)'
END;
PREPARE stmt FROM @alter_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- product (company_id, product_code, deleted_flag)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema='aluria_dev' AND table_name='product' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='product' AND index_name='uq_product_code_active');
SET @alter_sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''product: uq_product_code_active already exists, skipping'' AS note'
  WHEN @col_exists > 0 THEN 'ALTER TABLE aluria_dev.product ADD UNIQUE KEY uq_product_code_active (company_id, product_code, deleted_flag)'
  ELSE 'ALTER TABLE aluria_dev.product ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_product_code_active (company_id, product_code, deleted_flag)'
END;
PREPARE stmt FROM @alter_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- finance_transaction (company_id, voucher_number, deleted_flag)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema='aluria_dev' AND table_name='finance_transaction' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='finance_transaction' AND index_name='uq_ft_voucher_active');
SET @alter_sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''finance_transaction: uq_ft_voucher_active already exists, skipping'' AS note'
  WHEN @col_exists > 0 THEN 'ALTER TABLE aluria_dev.finance_transaction ADD UNIQUE KEY uq_ft_voucher_active (company_id, voucher_number, deleted_flag)'
  ELSE 'ALTER TABLE aluria_dev.finance_transaction ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_ft_voucher_active (company_id, voucher_number, deleted_flag)'
END;
PREPARE stmt FROM @alter_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- finance_payment (company_id, invoice_number, deleted_flag)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema='aluria_dev' AND table_name='finance_payment' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='finance_payment' AND index_name='uq_fp_invoice_active');
SET @alter_sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''finance_payment: uq_fp_invoice_active already exists, skipping'' AS note'
  WHEN @col_exists > 0 THEN 'ALTER TABLE aluria_dev.finance_payment ADD UNIQUE KEY uq_fp_invoice_active (company_id, invoice_number, deleted_flag)'
  ELSE 'ALTER TABLE aluria_dev.finance_payment ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_fp_invoice_active (company_id, invoice_number, deleted_flag)'
END;
PREPARE stmt FROM @alter_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- STEP 2 — VERIFICATION
-- ----------------------------------------------------------------------------

-- 2a. Every target table should show deleted_flag (VIRTUAL) + its uq_*_active index.
SELECT table_name, column_name, generation_expression, extra
FROM information_schema.COLUMNS
WHERE table_schema='aluria_dev' AND column_name='deleted_flag'
ORDER BY table_name;
-- Expect: 11 rows, GENERATION_EXPRESSION = IF(deleted_at IS NULL,0,NULL), EXTRA = 'VIRTUAL GENERATED'.

SELECT table_name, index_name, non_unique
FROM information_schema.STATISTICS
WHERE table_schema='aluria_dev'
  AND index_name IN ('uq_po_display_active','uq_so_display_active','uq_pinv_display_active','uq_sinv_display_active',
                     'uq_sd_display_active','uq_sppb_display_active','uq_customer_name_active','uq_supplier_name_active',
                     'uq_product_code_active','uq_ft_voucher_active','uq_fp_invoice_active')
GROUP BY table_name, index_name, non_unique
ORDER BY table_name;
-- Expect: 11 rows, non_unique = 0 for every row (unique).

-- 2b. Functional smoke: insert a second active row with a duplicate number → must fail with 1062.
-- (Run from a throwaway transaction; roll back after. Do NOT leave test rows in the DB.)
-- START TRANSACTION;
-- INSERT INTO aluria_dev.purchase_order (id, company_id, po_display_number, /* required cols */, created_by, created_at)
--   SELECT 'pol_test_dup', company_id, po_display_number, /* same required cols */, 'test', NOW()
--   FROM aluria_dev.purchase_order WHERE deleted_at IS NULL LIMIT 1;
-- -- Expect: ERROR 1062 (23000) — duplicate key on uq_po_display_active.
-- ROLLBACK;
```

**DEV note:** Step 0 (duplicate audit) returned 0 rows on every one of the 11 queries — no active duplicates existed in dev, so the `ADD UNIQUE KEY` statements applied cleanly. Re-run is a safe no-op: the `@idx_exists` branch short-circuits every block.

---

## PROD — NOT yet applied

**Do not run this against production without Kage's sign-off first** — same review gate as every other schema change in this project. `ALTER TABLE … ADD UNIQUE KEY` rebuilds the index online (InnoDB online DDL in MySQL 8); on large tables (`finance_transaction`, `purchase_order`) this holds row locks briefly and should be run in a maintenance window, not during peak traffic.

```sql
-- ============================================================================
-- v40 PROD — soft-delete-aware UNIQUE constraints on document/party numbers
-- ============================================================================

-- Replace with prod's real APP_SCHEMA (from prod's .env). v35/v39 use 'aluria'.
SET @app_schema := 'aluria';

-- ----------------------------------------------------------------------------
-- STEP 0 — DUPLICATE AUDIT (read-only gate). Every query MUST return 0 rows
-- before Step 1. If any return rows, stop and resolve the duplicates manually
-- (merge or soft-delete the surplus active row) — do NOT run Step 1 until clean.
-- ----------------------------------------------------------------------------
SET @sql := CONCAT('SELECT company_id, po_display_number, COUNT(*) AS dup_count FROM ', @app_schema, '.purchase_order WHERE deleted_at IS NULL AND po_display_number IS NOT NULL GROUP BY company_id, po_display_number HAVING COUNT(*) > 1'); PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;
SET @sql := CONCAT('SELECT company_id, so_display_number, COUNT(*) AS dup_count FROM ', @app_schema, '.sales_order WHERE deleted_at IS NULL AND so_display_number IS NOT NULL GROUP BY company_id, so_display_number HAVING COUNT(*) > 1'); PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;
SET @sql := CONCAT('SELECT company_id, invoice_display_number, COUNT(*) AS dup_count FROM ', @app_schema, '.purchase_invoice WHERE deleted_at IS NULL AND invoice_display_number IS NOT NULL GROUP BY company_id, invoice_display_number HAVING COUNT(*) > 1'); PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;
SET @sql := CONCAT('SELECT company_id, invoice_display_number, COUNT(*) AS dup_count FROM ', @app_schema, '.sales_invoice WHERE deleted_at IS NULL AND invoice_display_number IS NOT NULL GROUP BY company_id, invoice_display_number HAVING COUNT(*) > 1'); PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;
SET @sql := CONCAT('SELECT company_id, do_display_number, COUNT(*) AS dup_count FROM ', @app_schema, '.sales_delivery WHERE deleted_at IS NULL AND do_display_number IS NOT NULL GROUP BY company_id, do_display_number HAVING COUNT(*) > 1'); PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;
SET @sql := CONCAT('SELECT company_id, sppb_display_number, COUNT(*) AS dup_count FROM ', @app_schema, '.sales_sppb WHERE deleted_at IS NULL AND sppb_display_number IS NOT NULL GROUP BY company_id, sppb_display_number HAVING COUNT(*) > 1'); PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;
SET @sql := CONCAT('SELECT company_id, customer_name, COUNT(*) AS dup_count FROM ', @app_schema, '.customer WHERE deleted_at IS NULL AND customer_name IS NOT NULL GROUP BY company_id, customer_name HAVING COUNT(*) > 1'); PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;
SET @sql := CONCAT('SELECT company_id, supplier_name, COUNT(*) AS dup_count FROM ', @app_schema, '.supplier WHERE deleted_at IS NULL AND supplier_name IS NOT NULL GROUP BY company_id, supplier_name HAVING COUNT(*) > 1'); PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;
SET @sql := CONCAT('SELECT company_id, product_code, COUNT(*) AS dup_count FROM ', @app_schema, '.product WHERE deleted_at IS NULL AND product_code IS NOT NULL GROUP BY company_id, product_code HAVING COUNT(*) > 1'); PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;
SET @sql := CONCAT('SELECT company_id, voucher_number, COUNT(*) AS dup_count FROM ', @app_schema, '.finance_transaction WHERE deleted_at IS NULL AND voucher_number IS NOT NULL GROUP BY company_id, voucher_number HAVING COUNT(*) > 1'); PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;
SET @sql := CONCAT('SELECT company_id, invoice_number, COUNT(*) AS dup_count FROM ', @app_schema, '.finance_payment WHERE deleted_at IS NULL AND invoice_number IS NOT NULL GROUP BY company_id, invoice_number HAVING COUNT(*) > 1'); PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;

-- ----------------------------------------------------------------------------
-- STEP 1 — add deleted_flag + UNIQUE index per table (idempotent CASE guard).
-- Same three-state guard as DEV: skip if index exists / add index only if
-- column exists / add both if neither exists.
-- ----------------------------------------------------------------------------

-- purchase_order
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=@app_schema AND table_name='purchase_order' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='purchase_order' AND index_name='uq_po_display_active');
SET @sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''purchase_order: already applied, skipping'' AS note'
  WHEN @col_exists > 0 THEN CONCAT('ALTER TABLE `', @app_schema, '`.purchase_order ADD UNIQUE KEY uq_po_display_active (company_id, po_display_number, deleted_flag)')
  ELSE CONCAT('ALTER TABLE `', @app_schema, '`.purchase_order ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_po_display_active (company_id, po_display_number, deleted_flag)')
END;
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- sales_order
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=@app_schema AND table_name='sales_order' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='sales_order' AND index_name='uq_so_display_active');
SET @sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''sales_order: already applied, skipping'' AS note'
  WHEN @col_exists > 0 THEN CONCAT('ALTER TABLE `', @app_schema, '`.sales_order ADD UNIQUE KEY uq_so_display_active (company_id, so_display_number, deleted_flag)')
  ELSE CONCAT('ALTER TABLE `', @app_schema, '`.sales_order ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_so_display_active (company_id, so_display_number, deleted_flag)')
END;
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- purchase_invoice
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=@app_schema AND table_name='purchase_invoice' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='purchase_invoice' AND index_name='uq_pinv_display_active');
SET @sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''purchase_invoice: already applied, skipping'' AS note'
  WHEN @col_exists > 0 THEN CONCAT('ALTER TABLE `', @app_schema, '`.purchase_invoice ADD UNIQUE KEY uq_pinv_display_active (company_id, invoice_display_number, deleted_flag)')
  ELSE CONCAT('ALTER TABLE `', @app_schema, '`.purchase_invoice ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_pinv_display_active (company_id, invoice_display_number, deleted_flag)')
END;
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- sales_invoice
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=@app_schema AND table_name='sales_invoice' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='sales_invoice' AND index_name='uq_sinv_display_active');
SET @sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''sales_invoice: already applied, skipping'' AS note'
  WHEN @col_exists > 0 THEN CONCAT('ALTER TABLE `', @app_schema, '`.sales_invoice ADD UNIQUE KEY uq_sinv_display_active (company_id, invoice_display_number, deleted_flag)')
  ELSE CONCAT('ALTER TABLE `', @app_schema, '`.sales_invoice ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_sinv_display_active (company_id, invoice_display_number, deleted_flag)')
END;
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- sales_delivery
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=@app_schema AND table_name='sales_delivery' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='sales_delivery' AND index_name='uq_sd_display_active');
SET @sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''sales_delivery: already applied, skipping'' AS note'
  WHEN @col_exists > 0 THEN CONCAT('ALTER TABLE `', @app_schema, '`.sales_delivery ADD UNIQUE KEY uq_sd_display_active (company_id, do_display_number, deleted_flag)')
  ELSE CONCAT('ALTER TABLE `', @app_schema, '`.sales_delivery ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_sd_display_active (company_id, do_display_number, deleted_flag)')
END;
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- sales_sppb
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=@app_schema AND table_name='sales_sppb' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='sales_sppb' AND index_name='uq_sppb_display_active');
SET @sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''sales_sppb: already applied, skipping'' AS note'
  WHEN @col_exists > 0 THEN CONCAT('ALTER TABLE `', @app_schema, '`.sales_sppb ADD UNIQUE KEY uq_sppb_display_active (company_id, sppb_display_number, deleted_flag)')
  ELSE CONCAT('ALTER TABLE `', @app_schema, '`.sales_sppb ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_sppb_display_active (company_id, sppb_display_number, deleted_flag)')
END;
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- customer
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=@app_schema AND table_name='customer' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='customer' AND index_name='uq_customer_name_active');
SET @sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''customer: already applied, skipping'' AS note'
  WHEN @col_exists > 0 THEN CONCAT('ALTER TABLE `', @app_schema, '`.customer ADD UNIQUE KEY uq_customer_name_active (company_id, customer_name, deleted_flag)')
  ELSE CONCAT('ALTER TABLE `', @app_schema, '`.customer ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_customer_name_active (company_id, customer_name, deleted_flag)')
END;
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- supplier
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=@app_schema AND table_name='supplier' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='supplier' AND index_name='uq_supplier_name_active');
SET @sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''supplier: already applied, skipping'' AS note'
  WHEN @col_exists > 0 THEN CONCAT('ALTER TABLE `', @app_schema, '`.supplier ADD UNIQUE KEY uq_supplier_name_active (company_id, supplier_name, deleted_flag)')
  ELSE CONCAT('ALTER TABLE `', @app_schema, '`.supplier ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_supplier_name_active (company_id, supplier_name, deleted_flag)')
END;
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- product
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=@app_schema AND table_name='product' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='product' AND index_name='uq_product_code_active');
SET @sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''product: already applied, skipping'' AS note'
  WHEN @col_exists > 0 THEN CONCAT('ALTER TABLE `', @app_schema, '`.product ADD UNIQUE KEY uq_product_code_active (company_id, product_code, deleted_flag)')
  ELSE CONCAT('ALTER TABLE `', @app_schema, '`.product ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_product_code_active (company_id, product_code, deleted_flag)')
END;
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- finance_transaction
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=@app_schema AND table_name='finance_transaction' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='finance_transaction' AND index_name='uq_ft_voucher_active');
SET @sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''finance_transaction: already applied, skipping'' AS note'
  WHEN @col_exists > 0 THEN CONCAT('ALTER TABLE `', @app_schema, '`.finance_transaction ADD UNIQUE KEY uq_ft_voucher_active (company_id, voucher_number, deleted_flag)')
  ELSE CONCAT('ALTER TABLE `', @app_schema, '`.finance_transaction ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_ft_voucher_active (company_id, voucher_number, deleted_flag)')
END;
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- finance_payment
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=@app_schema AND table_name='finance_payment' AND column_name='deleted_flag');
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='finance_payment' AND index_name='uq_fp_invoice_active');
SET @sql := CASE
  WHEN @idx_exists > 0 THEN 'SELECT ''finance_payment: already applied, skipping'' AS note'
  WHEN @col_exists > 0 THEN CONCAT('ALTER TABLE `', @app_schema, '`.finance_payment ADD UNIQUE KEY uq_fp_invoice_active (company_id, invoice_number, deleted_flag)')
  ELSE CONCAT('ALTER TABLE `', @app_schema, '`.finance_payment ADD COLUMN deleted_flag TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL, 0, NULL)) VIRTUAL AFTER deleted_at, ADD UNIQUE KEY uq_fp_invoice_active (company_id, invoice_number, deleted_flag)')
END;
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- STEP 2 — VERIFICATION
-- ----------------------------------------------------------------------------
SET @sql := CONCAT('SELECT table_name, column_name, generation_expression, extra FROM information_schema.COLUMNS WHERE table_schema=''', @app_schema, ''' AND column_name=''deleted_flag'' ORDER BY table_name'); PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;
-- Expect: 11 rows.

SET @sql := CONCAT('SELECT table_name, index_name, non_unique FROM information_schema.STATISTICS WHERE table_schema=''', @app_schema, ''' AND index_name IN (''uq_po_display_active'',''uq_so_display_active'',''uq_pinv_display_active'',''uq_sinv_display_active'',''uq_sd_display_active'',''uq_sppb_display_active'',''uq_customer_name_active'',''uq_supplier_name_active'',''uq_product_code_active'',''uq_ft_voucher_active'',''uq_fp_invoice_active'') GROUP BY table_name, index_name, non_unique ORDER BY table_name'); PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;
-- Expect: 11 rows, non_unique = 0.
```

**Deploy order:** schema-first. The constraint is inert to existing code — the app-layer duplicate check still runs first and returns a `409` as before; the DB constraint is the safety net for the race window between that `SELECT` and the `INSERT`. Old code against the new schema is safe (the `deleted_flag` column is virtual and never referenced by app code). New code is not required for this migration to be effective. No code change blocks this deploy, but see the checklist for the `1062` handling note.

---

## Post-migration checklist (both environments)

- [ ] **Step 0 duplicate audit returned 0 rows on all 11 queries in prod before Step 1 ran.** If any returned rows, the `ADD UNIQUE KEY` would have failed with `errno 1062` — resolve the duplicates (merge or soft-delete the surplus active row) and re-run Step 1 for that table only (idempotent guard makes this safe).
- [ ] Verification query 2a returns **11 `deleted_flag` rows** (one per target table, `VIRTUAL GENERATED`).
- [ ] Verification query 2b returns **11 unique indexes** (`non_unique = 0`).
- [ ] **App-layer `1062` handling:** every `create{Thing}()` in `v2/` that inserts into one of these 11 tables should catch `mysqli` errno `1062` and return `409 Duplicate` instead of `500`. Currently the app relies on its own pre-`INSERT` duplicate `SELECT`, which is not atomic under concurrency — the DB constraint is now the real guard. Auditing/updating the catch blocks is a **separate code task**, not part of this schema migration.
- [ ] `purchase_receive` is intentionally excluded (no display-number column exists — see "Schema reconciliation note" above). A separate migration to add + backfill `receiving_number` is required before a constraint can be added there; tracked as a follow-up, not in this addendum.
- [ ] Soft-deleting a record (setting `deleted_at` to a timestamp) flips `deleted_flag` to `NULL`, which removes it from the active-unique set — so a soft-deleted number can be reused on a new active record. Confirm this matches the business intent (it does for every module today — soft-delete means "gone", and reusing the number is desired, not a bug).
- [ ] No role or permission changes involved — constraints are transparent to auth.
