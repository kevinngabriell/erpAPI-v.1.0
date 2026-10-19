# Aluria Schema Addendum — v43: composite indexes for query performance (MED-13)

Adds covering/composite secondary indexes across `APP_SCHEMA` and one `CORE_SCHEMA` table to eliminate the index-merge and full-scan patterns the current single-column `KEY (company_id)` indexes produce. Every list endpoint in `v2/` filters on `company_id` plus at least one more column (`deleted_at`, a partner FK, a status FK, or a display number), and the optimizer today picks the single `company_id` index then filters the rest by hand — these composites let it satisfy the common `WHERE company_id = … AND deleted_at IS NULL [AND …]` shape directly.

## Index set

| Scope | Index | Applies to |
|-------|-------|------------|
| Bulk | `(company_id, deleted_at)` | every `APP_SCHEMA` table that has both columns |
| Bulk | `(company_id, deleted_at, created_at)` | every `APP_SCHEMA` table that has all three |
| `purchase_order` | `(company_id, supplier_id)` | partner-filtered PO lists |
| `purchase_order` | `(company_id, status_id)` | status-filtered PO lists |
| `purchase_order` | `(company_id, po_display_number)` | display-number lookups / search |
| `sales_order` | `(company_id, customer_id)` | partner-filtered SO lists |
| `sales_order` | `(company_id, status_id)` | status-filtered SO lists |
| `sales_order` | `(company_id, so_display_number)` | display-number lookups / search |
| `finance_payment` | `(company_id, customer_id)` | AR payment lists by customer |
| `finance_payment` | `(company_id, invoice_number)` | payment-by-invoice lookup |
| `finance_payment` | `(company_id, transaction_status)` | status-filtered payment workflow |
| `finance_transaction` | `(company_id, transaction_status)` | approval-workflow filtered lists |
| `finance_transaction` | `(company_id, transaction_date)` | date-range cash-book / ledger queries |
| `warehouse_lot` | `(company_id, product_id)` | stock-by-product lookup |
| `warehouse_lot` | `(company_id, location_id)` | stock-by-location lookup |
| `audit_log` | `(company_id, module)` | audit list filtered by module |
| `audit_log` | `(company_id, reference_id)` | audit trail for a given record |
| every `*_item` table | `(parent_fk, deleted_at)` | child-row fetch per parent (see note) |
| `app_role_permission` (core) | `(app_role_id, permission_id)` | role-permission lookup / dedup |

## Note on `*_item` tables — `parent_id` maps to the actual FK column

The MED-13 spec lists `(parent_id, deleted_at)` for "all `*_item` tables", but no `*_item` table in this schema has a column literally named `parent_id` — each uses its parent entity's FK name (verified via `v2/` PHP source). This migration uses the **actual** FK column per table:

| Item table | Parent FK column (used in index) |
|------------|----------------------------------|
| `purchase_order_item` | `purchase_order_id` |
| `purchase_invoice_item` | `purchase_invoice_id` |
| `purchase_receive_item` | `purchase_receive_id` |
| `sales_order_item` | `sales_order_id` |
| `sales_delivery_item` | `sales_delivery_id` |
| `sales_invoice_item` | `sales_invoice_id` |
| `sales_sppb_item` | `sales_sppb_id` |
| `sales_profit_item` | `sales_profit_id` |
| `warehouse_transaction_item` | `warehouse_transaction_id` |

Every child-row fetch in `v2/` is `SELECT … FROM <table>_item WHERE <parent_fk> = '$id' AND deleted_at IS NULL` — this index covers that exact shape. The `*_item` tables do not carry `company_id` (they inherit company scope via the parent), so they are excluded from the two bulk `(company_id, …)` passes — the cursor over `information_schema.COLUMNS` naturally skips them because they have no `company_id` column.

## Bulk indexes use a cursor (not a hardcoded table list)

The two "every `APP_SCHEMA` table with `company_id`" passes are driven by a `information_schema.COLUMNS` cursor inside a stored procedure, not a hardcoded list of table names. This is deliberate: it guarantees only tables that actually have `company_id` (and `deleted_at` / `created_at`) get the index, and it stays correct as new tables are added without editing this migration. The procedure is created, called, and dropped in the same script — it leaves no permanent object behind. Each candidate table is individually guarded on `information_schema.STATISTICS` (index name) so the whole script is re-runnable.

> Run via the `mysql` CLI (or MySQL Workbench / any client that supports `DELIMITER`). The `DELIMITER`-based procedure block is standard MySQL but is not runnable through `mysqli::query` (which takes one statement). Every other statement in this migration uses the `PREPARE`/`EXECUTE` pattern and is runnable anywhere.

## DEV — already applied

Run against `aluria_dev` / `movira_core_dev` on **2026-10-17**, by Claude (this session). All statements idempotent.

```sql
-- ============================================================================
-- v43 DEV — composite indexes
-- ============================================================================

USE `aluria_dev`;

-- ----------------------------------------------------------------------------
-- STEP 1 — BULK: (company_id, deleted_at) on every APP_SCHEMA table that has
-- both columns. Cursor-driven so only qualifying tables are touched; each
-- guarded on index name via information_schema.STATISTICS. Procedure is
-- created, called, and dropped — no permanent object.
-- ----------------------------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS v43_bulk_company_deleted$$
CREATE PROCEDURE v43_bulk_company_deleted(IN p_schema VARCHAR(64))
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_table VARCHAR(64);
  DECLARE v_index VARCHAR(133);
  DECLARE cur CURSOR FOR
    SELECT DISTINCT c1.table_name
    FROM information_schema.COLUMNS c1
    WHERE c1.table_schema = p_schema
      AND c1.column_name = 'company_id'
      AND EXISTS (
        SELECT 1 FROM information_schema.COLUMNS c2
        WHERE c2.table_schema = p_schema
          AND c2.table_name = c1.table_name
          AND c2.column_name = 'deleted_at'
      );
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
  OPEN cur;
  bulk_loop: LOOP
    FETCH cur INTO v_table;
    IF done = 1 THEN LEAVE bulk_loop; END IF;
    SET v_index = CONCAT('idx_', v_table, '_company_deleted');
    SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE table_schema = p_schema AND table_name = v_table AND index_name = v_index);
    IF @idx_exists = 0 THEN
      SET @ddl = CONCAT('ALTER TABLE `', p_schema, '`.`', v_table,
        '` ADD INDEX `', v_index, '` (company_id, deleted_at)');
      PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
  END LOOP;
  CLOSE cur;
END$$
DELIMITER ;
CALL v43_bulk_company_deleted('aluria_dev');
DROP PROCEDURE IF EXISTS v43_bulk_company_deleted;

-- ----------------------------------------------------------------------------
-- STEP 2 — BULK: (company_id, deleted_at, created_at) on every APP_SCHEMA
-- table that has all three. Same cursor pattern.
-- ----------------------------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS v43_bulk_company_deleted_created$$
CREATE PROCEDURE v43_bulk_company_deleted_created(IN p_schema VARCHAR(64))
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_table VARCHAR(64);
  DECLARE v_index VARCHAR(133);
  DECLARE cur CURSOR FOR
    SELECT DISTINCT c1.table_name
    FROM information_schema.COLUMNS c1
    WHERE c1.table_schema = p_schema
      AND c1.column_name = 'company_id'
      AND EXISTS (SELECT 1 FROM information_schema.COLUMNS c2
        WHERE c2.table_schema = p_schema AND c2.table_name = c1.table_name AND c2.column_name = 'deleted_at')
      AND EXISTS (SELECT 1 FROM information_schema.COLUMNS c3
        WHERE c3.table_schema = p_schema AND c3.table_name = c1.table_name AND c3.column_name = 'created_at');
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
  OPEN cur;
  bulk_loop: LOOP
    FETCH cur INTO v_table;
    IF done = 1 THEN LEAVE bulk_loop; END IF;
    SET v_index = CONCAT('idx_', v_table, '_company_deleted_created');
    SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE table_schema = p_schema AND table_name = v_table AND index_name = v_index);
    IF @idx_exists = 0 THEN
      SET @ddl = CONCAT('ALTER TABLE `', p_schema, '`.`', v_table,
        '` ADD INDEX `', v_index, '` (company_id, deleted_at, created_at)');
      PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
  END LOOP;
  CLOSE cur;
END$$
DELIMITER ;
CALL v43_bulk_company_deleted_created('aluria_dev');
DROP PROCEDURE IF EXISTS v43_bulk_company_deleted_created;

-- ----------------------------------------------------------------------------
-- STEP 3 — SPECIFIC composite indexes (idempotent, each guarded on
-- information_schema.STATISTICS index name).
-- ----------------------------------------------------------------------------

-- purchase_order: (company_id, supplier_id), (company_id, status_id), (company_id, po_display_number)
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='purchase_order' AND index_name='idx_po_company_supplier');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_po_company_supplier exists'' AS note', 'ALTER TABLE aluria_dev.purchase_order ADD INDEX idx_po_company_supplier (company_id, supplier_id)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='purchase_order' AND index_name='idx_po_company_status');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_po_company_status exists'' AS note', 'ALTER TABLE aluria_dev.purchase_order ADD INDEX idx_po_company_status (company_id, status_id)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='purchase_order' AND index_name='idx_po_company_display');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_po_company_display exists'' AS note', 'ALTER TABLE aluria_dev.purchase_order ADD INDEX idx_po_company_display (company_id, po_display_number)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- sales_order: (company_id, customer_id), (company_id, status_id), (company_id, so_display_number)
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='sales_order' AND index_name='idx_so_company_customer');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_so_company_customer exists'' AS note', 'ALTER TABLE aluria_dev.sales_order ADD INDEX idx_so_company_customer (company_id, customer_id)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='sales_order' AND index_name='idx_so_company_status');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_so_company_status exists'' AS note', 'ALTER TABLE aluria_dev.sales_order ADD INDEX idx_so_company_status (company_id, status_id)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='sales_order' AND index_name='idx_so_company_display');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_so_company_display exists'' AS note', 'ALTER TABLE aluria_dev.sales_order ADD INDEX idx_so_company_display (company_id, so_display_number)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- finance_payment: (company_id, customer_id), (company_id, invoice_number), (company_id, transaction_status)
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='finance_payment' AND index_name='idx_fp_company_customer');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_fp_company_customer exists'' AS note', 'ALTER TABLE aluria_dev.finance_payment ADD INDEX idx_fp_company_customer (company_id, customer_id)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='finance_payment' AND index_name='idx_fp_company_invoice');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_fp_company_invoice exists'' AS note', 'ALTER TABLE aluria_dev.finance_payment ADD INDEX idx_fp_company_invoice (company_id, invoice_number)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='finance_payment' AND index_name='idx_fp_company_status');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_fp_company_status exists'' AS note', 'ALTER TABLE aluria_dev.finance_payment ADD INDEX idx_fp_company_status (company_id, transaction_status)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- finance_transaction: (company_id, transaction_status), (company_id, transaction_date)
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='finance_transaction' AND index_name='idx_ft_company_status');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_ft_company_status exists'' AS note', 'ALTER TABLE aluria_dev.finance_transaction ADD INDEX idx_ft_company_status (company_id, transaction_status)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='finance_transaction' AND index_name='idx_ft_company_date');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_ft_company_date exists'' AS note', 'ALTER TABLE aluria_dev.finance_transaction ADD INDEX idx_ft_company_date (company_id, transaction_date)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- warehouse_lot: (company_id, product_id), (company_id, location_id)
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='warehouse_lot' AND index_name='idx_wl_company_product');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_wl_company_product exists'' AS note', 'ALTER TABLE aluria_dev.warehouse_lot ADD INDEX idx_wl_company_product (company_id, product_id)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='warehouse_lot' AND index_name='idx_wl_company_location');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_wl_company_location exists'' AS note', 'ALTER TABLE aluria_dev.warehouse_lot ADD INDEX idx_wl_company_location (company_id, location_id)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- audit_log: (company_id, module), (company_id, reference_id)
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='audit_log' AND index_name='idx_audit_company_module');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_audit_company_module exists'' AS note', 'ALTER TABLE aluria_dev.audit_log ADD INDEX idx_audit_company_module (company_id, module)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='audit_log' AND index_name='idx_audit_company_reference');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_audit_company_reference exists'' AS note', 'ALTER TABLE aluria_dev.audit_log ADD INDEX idx_audit_company_reference (company_id, reference_id)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ----------------------------------------------------------------------------
-- STEP 4 — *_item tables: (parent_fk, deleted_at). Parent FK column is the
-- actual per-table name (see the mapping table in the doc header — no
-- *_item table has a literal 'parent_id' column).
-- ----------------------------------------------------------------------------

-- purchase_order_item (purchase_order_id, deleted_at)
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='purchase_order_item' AND index_name='idx_poi_parent_deleted');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_poi_parent_deleted exists'' AS note', 'ALTER TABLE aluria_dev.purchase_order_item ADD INDEX idx_poi_parent_deleted (purchase_order_id, deleted_at)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- purchase_invoice_item (purchase_invoice_id, deleted_at)
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='purchase_invoice_item' AND index_name='idx_pii_parent_deleted');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_pii_parent_deleted exists'' AS note', 'ALTER TABLE aluria_dev.purchase_invoice_item ADD INDEX idx_pii_parent_deleted (purchase_invoice_id, deleted_at)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- purchase_receive_item (purchase_receive_id, deleted_at)
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='purchase_receive_item' AND index_name='idx_pri_parent_deleted');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_pri_parent_deleted exists'' AS note', 'ALTER TABLE aluria_dev.purchase_receive_item ADD INDEX idx_pri_parent_deleted (purchase_receive_id, deleted_at)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- sales_order_item (sales_order_id, deleted_at)
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='sales_order_item' AND index_name='idx_soi_parent_deleted');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_soi_parent_deleted exists'' AS note', 'ALTER TABLE aluria_dev.sales_order_item ADD INDEX idx_soi_parent_deleted (sales_order_id, deleted_at)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- sales_delivery_item (sales_delivery_id, deleted_at)
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='sales_delivery_item' AND index_name='idx_sdi_parent_deleted');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_sdi_parent_deleted exists'' AS note', 'ALTER TABLE aluria_dev.sales_delivery_item ADD INDEX idx_sdi_parent_deleted (sales_delivery_id, deleted_at)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- sales_invoice_item (sales_invoice_id, deleted_at)
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='sales_invoice_item' AND index_name='idx_sii_parent_deleted');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_sii_parent_deleted exists'' AS note', 'ALTER TABLE aluria_dev.sales_invoice_item ADD INDEX idx_sii_parent_deleted (sales_invoice_id, deleted_at)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- sales_sppb_item (sales_sppb_id, deleted_at)
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='sales_sppb_item' AND index_name='idx_sppbi_parent_deleted');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_sppbi_parent_deleted exists'' AS note', 'ALTER TABLE aluria_dev.sales_sppb_item ADD INDEX idx_sppbi_parent_deleted (sales_sppb_id, deleted_at)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- sales_profit_item (sales_profit_id, deleted_at)
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='sales_profit_item' AND index_name='idx_spi_parent_deleted');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_spi_parent_deleted exists'' AS note', 'ALTER TABLE aluria_dev.sales_profit_item ADD INDEX idx_spi_parent_deleted (sales_profit_id, deleted_at)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- warehouse_transaction_item (warehouse_transaction_id, deleted_at)
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='aluria_dev' AND table_name='warehouse_transaction_item' AND index_name='idx_wti_parent_deleted');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_wti_parent_deleted exists'' AS note', 'ALTER TABLE aluria_dev.warehouse_transaction_item ADD INDEX idx_wti_parent_deleted (warehouse_transaction_id, deleted_at)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ----------------------------------------------------------------------------
-- STEP 5 — CORE_SCHEMA: app_role_permission (app_role_id, permission_id)
-- ----------------------------------------------------------------------------
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema='movira_core_dev' AND table_name='app_role_permission' AND index_name='idx_arp_role_permission');
SET @sql := IF(@idx_exists > 0, 'SELECT ''idx_arp_role_permission exists'' AS note', 'ALTER TABLE movira_core_dev.app_role_permission ADD INDEX idx_arp_role_permission (app_role_id, permission_id)');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ----------------------------------------------------------------------------
-- STEP 6 — VERIFICATION
-- ----------------------------------------------------------------------------

-- 6a. Bulk pass 1: every company_id+deleted_at table has its idx_*_company_deleted.
SELECT t.table_name
FROM information_schema.TABLES t
WHERE t.table_schema='aluria_dev'
  AND EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.table_schema=t.table_schema AND c.table_name=t.table_name AND c.column_name='company_id')
  AND EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.table_schema=t.table_schema AND c.table_name=t.table_name AND c.column_name='deleted_at')
  AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS s WHERE s.table_schema=t.table_schema AND s.table_name=t.table_name AND s.index_name=CONCAT('idx_', t.table_name, '_company_deleted'));
-- Expect: 0 rows (every qualifying table has the index).

-- 6b. Bulk pass 2: every company_id+deleted_at+created_at table has its 3-col index.
SELECT t.table_name
FROM information_schema.TABLES t
WHERE t.table_schema='aluria_dev'
  AND EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.table_schema=t.table_schema AND c.table_name=t.table_name AND c.column_name='company_id')
  AND EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.table_schema=t.table_schema AND c.table_name=t.table_name AND c.column_name='deleted_at')
  AND EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.table_schema=t.table_schema AND c.table_name=t.table_name AND c.column_name='created_at')
  AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS s WHERE s.table_schema=t.table_schema AND s.table_name=t.table_name AND s.index_name=CONCAT('idx_', t.table_name, '_company_deleted_created'));
-- Expect: 0 rows.

-- 6c. Specific + item + core indexes all present.
SELECT table_name, index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols
FROM information_schema.STATISTICS
WHERE table_schema IN ('aluria_dev','movira_core_dev')
  AND index_name IN (
    'idx_po_company_supplier','idx_po_company_status','idx_po_company_display',
    'idx_so_company_customer','idx_so_company_status','idx_so_company_display',
    'idx_fp_company_customer','idx_fp_company_invoice','idx_fp_company_status',
    'idx_ft_company_status','idx_ft_company_date',
    'idx_wl_company_product','idx_wl_company_location',
    'idx_audit_company_module','idx_audit_company_reference',
    'idx_poi_parent_deleted','idx_pii_parent_deleted','idx_pri_parent_deleted',
    'idx_soi_parent_deleted','idx_sdi_parent_deleted','idx_sii_parent_deleted',
    'idx_sppbi_parent_deleted','idx_spi_parent_deleted','idx_wti_parent_deleted',
    'idx_arp_role_permission'
  )
GROUP BY table_name, index_name
ORDER BY table_name, index_name;
-- Expect: 25 rows (15 specific + 9 item + 1 core).

-- 6d. Spot-check the optimizer uses the new index for a typical list query.
EXPLAIN SELECT * FROM aluria_dev.purchase_order
WHERE company_id='cmp0fcd1b1c010aa508' AND deleted_at IS NULL AND supplier_id='sup_test';
-- Expect: key = idx_po_company_supplier (or idx_purchase_order_company_deleted), not the
-- old single-column company_id index. (Exact key depends on row count / cardinality.)
```

**DEV note:** Steps 1–2 (bulk cursor passes) added `idx_<table>_company_deleted` and `idx_<table>_company_deleted_created` to every qualifying table. Steps 3–5 added the 25 specific/item/core indexes. Verification 6a/6b return 0 rows (no qualifying table missing its bulk index); 6c returns all 25 specific indexes. Re-run is a safe no-op — every `ADD INDEX` is guarded on `information_schema.STATISTICS`.

---

## PROD — NOT yet applied

**Do not run this against production without Kage's sign-off first** — same review gate as every other schema change. `ADD INDEX` on large tables (`finance_transaction`, `purchase_order`, `audit_log`) is online DDL in InnoDB (MySQL 8) but holds row locks during the build; run in a maintenance window, not peak traffic. The bulk cursor passes touch many tables at once — review the table count from Step 0 before proceeding.

```sql
-- ============================================================================
-- v43 PROD — composite indexes
-- ============================================================================

-- Replace with prod's real schema names (from prod's .env).
SET @app_schema  := 'aluria';
SET @core_schema := 'movira_core_prod';

-- ----------------------------------------------------------------------------
-- STEP 0 — PRECONDITION (read-only): see how many tables qualify for each bulk
-- pass, so the maintenance window can be sized. No writes here.
-- ----------------------------------------------------------------------------
SET @sql := CONCAT(
  'SELECT COUNT(DISTINCT c1.table_name) AS tables_company_deleted ',
  'FROM information_schema.COLUMNS c1 ',
  'WHERE c1.table_schema=''', @app_schema, ''' AND c1.column_name=''company_id'' ',
  'AND EXISTS (SELECT 1 FROM information_schema.COLUMNS c2 WHERE c2.table_schema=''', @app_schema, ''' AND c2.table_name=c1.table_name AND c2.column_name=''deleted_at'')'
);
PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;

SET @sql := CONCAT(
  'SELECT COUNT(DISTINCT c1.table_name) AS tables_company_deleted_created ',
  'FROM information_schema.COLUMNS c1 ',
  'WHERE c1.table_schema=''', @app_schema, ''' AND c1.column_name=''company_id'' ',
  'AND EXISTS (SELECT 1 FROM information_schema.COLUMNS c2 WHERE c2.table_schema=''', @app_schema, ''' AND c2.table_name=c1.table_name AND c2.column_name=''deleted_at'') ',
  'AND EXISTS (SELECT 1 FROM information_schema.COLUMNS c3 WHERE c3.table_schema=''', @app_schema, ''' AND c3.table_name=c1.table_name AND c3.column_name=''created_at'')'
);
PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;

-- ----------------------------------------------------------------------------
-- STEP 1 — BULK: (company_id, deleted_at). Replace p_schema argument with
-- @app_schema's value (the procedure takes a literal; pass the prod APP_SCHEMA
-- name resolved above). Run via mysql CLI / Workbench (DELIMITER block).
-- ----------------------------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS v43_bulk_company_deleted$$
CREATE PROCEDURE v43_bulk_company_deleted(IN p_schema VARCHAR(64))
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_table VARCHAR(64);
  DECLARE v_index VARCHAR(133);
  DECLARE cur CURSOR FOR
    SELECT DISTINCT c1.table_name
    FROM information_schema.COLUMNS c1
    WHERE c1.table_schema = p_schema
      AND c1.column_name = 'company_id'
      AND EXISTS (SELECT 1 FROM information_schema.COLUMNS c2 WHERE c2.table_schema = p_schema AND c2.table_name = c1.table_name AND c2.column_name = 'deleted_at');
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
  OPEN cur;
  bulk_loop: LOOP
    FETCH cur INTO v_table;
    IF done = 1 THEN LEAVE bulk_loop; END IF;
    SET v_index = CONCAT('idx_', v_table, '_company_deleted');
    SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema = p_schema AND table_name = v_table AND index_name = v_index);
    IF @idx_exists = 0 THEN
      SET @ddl = CONCAT('ALTER TABLE `', p_schema, '`.`', v_table, '` ADD INDEX `', v_index, '` (company_id, deleted_at)');
      PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
  END LOOP;
  CLOSE cur;
END$$
DELIMITER ;
-- Pass the prod APP_SCHEMA name here (the value of @app_schema set above):
CALL v43_bulk_company_deleted('aluria');
DROP PROCEDURE IF EXISTS v43_bulk_company_deleted;

-- ----------------------------------------------------------------------------
-- STEP 2 — BULK: (company_id, deleted_at, created_at). Same pattern.
-- ----------------------------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS v43_bulk_company_deleted_created$$
CREATE PROCEDURE v43_bulk_company_deleted_created(IN p_schema VARCHAR(64))
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_table VARCHAR(64);
  DECLARE v_index VARCHAR(133);
  DECLARE cur CURSOR FOR
    SELECT DISTINCT c1.table_name
    FROM information_schema.COLUMNS c1
    WHERE c1.table_schema = p_schema
      AND c1.column_name = 'company_id'
      AND EXISTS (SELECT 1 FROM information_schema.COLUMNS c2 WHERE c2.table_schema = p_schema AND c2.table_name = c1.table_name AND c2.column_name = 'deleted_at')
      AND EXISTS (SELECT 1 FROM information_schema.COLUMNS c3 WHERE c3.table_schema = p_schema AND c3.table_name = c1.table_name AND c3.column_name = 'created_at');
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
  OPEN cur;
  bulk_loop: LOOP
    FETCH cur INTO v_table;
    IF done = 1 THEN LEAVE bulk_loop; END IF;
    SET v_index = CONCAT('idx_', v_table, '_company_deleted_created');
    SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema = p_schema AND table_name = v_table AND index_name = v_index);
    IF @idx_exists = 0 THEN
      SET @ddl = CONCAT('ALTER TABLE `', p_schema, '`.`', v_table, '` ADD INDEX `', v_index, '` (company_id, deleted_at, created_at)');
      PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
  END LOOP;
  CLOSE cur;
END$$
DELIMITER ;
CALL v43_bulk_company_deleted_created('aluria');
DROP PROCEDURE IF EXISTS v43_bulk_company_deleted_created;

-- ----------------------------------------------------------------------------
-- STEP 3 — SPECIFIC indexes (idempotent, guarded on STATISTICS).
-- (Mirror of DEV Step 3, with @app_schema/@core_schema via CONCAT.)
-- ----------------------------------------------------------------------------
-- purchase_order
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='purchase_order' AND index_name='idx_po_company_supplier');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.purchase_order ADD INDEX idx_po_company_supplier (company_id, supplier_id)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='purchase_order' AND index_name='idx_po_company_status');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.purchase_order ADD INDEX idx_po_company_status (company_id, status_id)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='purchase_order' AND index_name='idx_po_company_display');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.purchase_order ADD INDEX idx_po_company_display (company_id, po_display_number)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- sales_order
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='sales_order' AND index_name='idx_so_company_customer');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.sales_order ADD INDEX idx_so_company_customer (company_id, customer_id)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='sales_order' AND index_name='idx_so_company_status');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.sales_order ADD INDEX idx_so_company_status (company_id, status_id)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='sales_order' AND index_name='idx_so_company_display');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.sales_order ADD INDEX idx_so_company_display (company_id, so_display_number)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- finance_payment
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='finance_payment' AND index_name='idx_fp_company_customer');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.finance_payment ADD INDEX idx_fp_company_customer (company_id, customer_id)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='finance_payment' AND index_name='idx_fp_company_invoice');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.finance_payment ADD INDEX idx_fp_company_invoice (company_id, invoice_number)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='finance_payment' AND index_name='idx_fp_company_status');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.finance_payment ADD INDEX idx_fp_company_status (company_id, transaction_status)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- finance_transaction
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='finance_transaction' AND index_name='idx_ft_company_status');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.finance_transaction ADD INDEX idx_ft_company_status (company_id, transaction_status)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='finance_transaction' AND index_name='idx_ft_company_date');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.finance_transaction ADD INDEX idx_ft_company_date (company_id, transaction_date)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- warehouse_lot
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='warehouse_lot' AND index_name='idx_wl_company_product');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.warehouse_lot ADD INDEX idx_wl_company_product (company_id, product_id)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='warehouse_lot' AND index_name='idx_wl_company_location');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.warehouse_lot ADD INDEX idx_wl_company_location (company_id, location_id)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- audit_log
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='audit_log' AND index_name='idx_audit_company_module');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.audit_log ADD INDEX idx_audit_company_module (company_id, module)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='audit_log' AND index_name='idx_audit_company_reference');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.audit_log ADD INDEX idx_audit_company_reference (company_id, reference_id)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ----------------------------------------------------------------------------
-- STEP 4 — *_item tables: (parent_fk, deleted_at)
-- ----------------------------------------------------------------------------
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='purchase_order_item' AND index_name='idx_poi_parent_deleted');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.purchase_order_item ADD INDEX idx_poi_parent_deleted (purchase_order_id, deleted_at)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='purchase_invoice_item' AND index_name='idx_pii_parent_deleted');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.purchase_invoice_item ADD INDEX idx_pii_parent_deleted (purchase_invoice_id, deleted_at)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='purchase_receive_item' AND index_name='idx_pri_parent_deleted');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.purchase_receive_item ADD INDEX idx_pri_parent_deleted (purchase_receive_id, deleted_at)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='sales_order_item' AND index_name='idx_soi_parent_deleted');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.sales_order_item ADD INDEX idx_soi_parent_deleted (sales_order_id, deleted_at)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='sales_delivery_item' AND index_name='idx_sdi_parent_deleted');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.sales_delivery_item ADD INDEX idx_sdi_parent_deleted (sales_delivery_id, deleted_at)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='sales_invoice_item' AND index_name='idx_sii_parent_deleted');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.sales_invoice_item ADD INDEX idx_sii_parent_deleted (sales_invoice_id, deleted_at)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='sales_sppb_item' AND index_name='idx_sppbi_parent_deleted');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.sales_sppb_item ADD INDEX idx_sppbi_parent_deleted (sales_sppb_id, deleted_at)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='sales_profit_item' AND index_name='idx_spi_parent_deleted');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.sales_profit_item ADD INDEX idx_spi_parent_deleted (sales_profit_id, deleted_at)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@app_schema AND table_name='warehouse_transaction_item' AND index_name='idx_wti_parent_deleted');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@app_schema,'`.warehouse_transaction_item ADD INDEX idx_wti_parent_deleted (warehouse_transaction_id, deleted_at)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ----------------------------------------------------------------------------
-- STEP 5 — CORE: app_role_permission (app_role_id, permission_id)
-- ----------------------------------------------------------------------------
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@core_schema AND table_name='app_role_permission' AND index_name='idx_arp_role_permission');
SET @sql := IF(@idx>0,'SELECT ''skip''',CONCAT('ALTER TABLE `',@core_schema,'`.app_role_permission ADD INDEX idx_arp_role_permission (app_role_id, permission_id)')); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ----------------------------------------------------------------------------
-- STEP 6 — VERIFICATION (same shape as DEV Step 6, parameterized)
-- ----------------------------------------------------------------------------
SET @sql := CONCAT('SELECT t.table_name FROM information_schema.TABLES t WHERE t.table_schema=''',@app_schema,''' AND EXISTS(SELECT 1 FROM information_schema.COLUMNS c WHERE c.table_schema=t.table_schema AND c.table_name=t.table_name AND c.column_name=''company_id'') AND EXISTS(SELECT 1 FROM information_schema.COLUMNS c WHERE c.table_schema=t.table_schema AND c.table_name=t.table_name AND c.column_name=''deleted_at'') AND NOT EXISTS(SELECT 1 FROM information_schema.STATISTICS s WHERE s.table_schema=t.table_schema AND s.table_name=t.table_name AND s.index_name=CONCAT(''idx_'',t.table_name,''_company_deleted''))'); PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;
-- Expect: 0 rows.

SET @sql := CONCAT('SELECT table_name, index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.STATISTICS WHERE table_schema IN (''',@app_schema,''',''',@core_schema,''') AND index_name IN (''idx_po_company_supplier'',''idx_po_company_status'',''idx_po_company_display'',''idx_so_company_customer'',''idx_so_company_status'',''idx_so_company_display'',''idx_fp_company_customer'',''idx_fp_company_invoice'',''idx_fp_company_status'',''idx_ft_company_status'',''idx_ft_company_date'',''idx_wl_company_product'',''idx_wl_company_location'',''idx_audit_company_module'',''idx_audit_company_reference'',''idx_poi_parent_deleted'',''idx_pii_parent_deleted'',''idx_pri_parent_deleted'',''idx_soi_parent_deleted'',''idx_sdi_parent_deleted'',''idx_sii_parent_deleted'',''idx_sppbi_parent_deleted'',''idx_spi_parent_deleted'',''idx_wti_parent_deleted'',''idx_arp_role_permission'') GROUP BY table_name, index_name ORDER BY table_name, index_name'); PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;
-- Expect: 25 rows.
```

**Deploy order:** schema-only, no code dependency. The new indexes are transparent to existing queries — the optimizer picks them up automatically. Old code benefits immediately; no code deploy is required for the indexes to take effect. If a later code change drops a filter column from a query, the index simply goes unused (no error). Safe to apply before, alongside, or after any code release.

---

## Post-migration checklist (both environments)

- [ ] **Step 0 (prod) precondition reviewed** — the two bulk-pass counts tell you how many `ALTER TABLE … ADD INDEX` statements the cursor will issue. Size the maintenance window accordingly; large tables (`finance_transaction`, `purchase_order`, `audit_log`) dominate the build time.
- [ ] Verification 6a (prod: Step 6 first query) returns **0 rows** — every `company_id`+`deleted_at` table has its bulk index.
- [ ] Verification 6c (prod: Step 6 second query) returns **25 rows** — all specific, item, and core indexes present.
- [ ] **Duplicate-index awareness:** some tables already have a single-column `KEY (company_id)` from their original `CREATE TABLE`. This migration adds composites on top of it — the single-column index is still useful for `company_id`-only filters, so it is **not** dropped. If storage becomes a concern, a separate cleanup migration can drop single-column `(company_id)` indexes that are fully covered by a composite whose leftmost column is `company_id` — but that is out of scope here and should be verified per-query with `EXPLAIN` first.
- [ ] **`*_item` parent-FK mapping** is documented in the doc header — no `*_item` table has a literal `parent_id` column; each index uses the table's actual parent FK name. If a new `*_item` table is added later, add its `(parent_fk, deleted_at)` index explicitly (the bulk cursor skips item tables because they have no `company_id`).
- [ ] No permission or role changes involved — indexes are transparent to auth and API response shapes.
