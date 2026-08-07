# Aluria Schema Addendum — v35: `reorder_point` table + low-stock notification

**Adds `aluria_dev.reorder_point`** — a per-`product_id` + `location_id` minimum-stock threshold, backing the `dashboard.low_stock.view` / `warehouse.low_stock.view` / `warehouse.reorder_point.update` permission keys already seeded in [[v24_permission_catalog_seed]] but never given a schema. `v2/dashboard/index.php` has carried a `low_stock` widget as "no backing schema yet" since it was written — this closes that gap.

This addendum also documents a code change (not a schema change) that the feature depends on: `warehouse_lot.end_balance` was previously never touched by `warehouse_transaction`/`warehouse_transaction_item` writes — it was only ever set directly via `warehouse-lot`'s own CRUD. `v2/warehouse/warehouse-transaction/index.php`'s `createWarehouseTransaction()` now applies a signed delta to the referenced lot's `end_balance` on every post (`stock_in` +, `stock_out` −, `adjustment`/`transfer` applied as the caller-supplied signed `quantity`), then checks the product+location's summed balance against `reorder_point.min_stock` and fires a `low_stock_alert` notification via the existing `notify()` dispatcher when it crosses below threshold.

## Code changes (already applied, this session)

- `v2/warehouse/reorder-point/index.php` (new): standard CRUD for `reorder_point`, modeled on `warehouse-lot`'s shape (`product_id` + `location_id`). Only `min_stock` is mutable after creation.
- `v2/index.php`: routes `reorder-point` → the new module, alongside `warehouse-lot`/`warehouse-transaction`.
- `v2/warehouse/warehouse-transaction/index.php`: `createWarehouseTransaction()` now applies a lot-balance delta per item and calls `checkReorderPointAndNotify()` once per unique `(product_id, location_id)` touched, after `$conn->commit()`. `updateWarehouseTransaction()`/`deleteWarehouseTransaction()` are unchanged — they still don't touch items or balances, matching their existing (pre-existing) scope.
- `v2/helpers/notification.php`: added `'low_stock_alert'` to `NOTIFICATION_ACTIONABLE_TYPES` (enables WhatsApp delivery), `resolveLowStockRecipients()` (permission-key-driven, `warehouse.low_stock.view`), and `checkReorderPointAndNotify()` (the threshold check + `alerted_at` de-dup).
- `v2/notification/index.php`: added a `warehouse` category (`['warehouse_transaction', 'reorder_point']`) to `NOTIFICATION_CATEGORY_MODULES` — there wasn't one before despite `warehouse_transaction`/`warehouse_lot` already existing as modules.
- `v2/dashboard/index.php`: added `buildLowStock()`, wired to `dashboard.low_stock.view`; removed `low_stock` from the "no backing schema yet" comment.

## DEV — already applied

Run against `aluria_dev` on **2026-08-07**, via direct `mysqli` through `v2/connection/db.php` (same tool used for `v32`/`v33`/`v34`). Idempotent via the `information_schema` guard.

```sql
-- ============================================================================
-- v35 DEV — reorder_point table
-- ============================================================================

SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE table_schema = 'aluria_dev' AND table_name = 'reorder_point'
);
SET @create_sql := IF(@schema_check > 0,
  'SELECT ''reorder_point already exists, skipping'' AS note',
  'CREATE TABLE aluria_dev.reorder_point (
     id          VARCHAR(50) NOT NULL PRIMARY KEY,
     company_id  VARCHAR(50) NOT NULL,
     product_id  VARCHAR(50) NOT NULL,
     location_id VARCHAR(50) NOT NULL,
     min_stock   DECIMAL(15,3) NOT NULL DEFAULT 0,
     alerted_at  DATETIME NULL,
     created_by  VARCHAR(50) NOT NULL,
     created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     updated_by  VARCHAR(50) NULL,
     updated_at  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
     deleted_at  DATETIME NULL,
     KEY idx_reorder_point_company (company_id),
     KEY idx_reorder_point_product (product_id),
     KEY idx_reorder_point_location (location_id)
   ) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
);
PREPARE create_reorder_point FROM @create_sql;
EXECUTE create_reorder_point;
DEALLOCATE PREPARE create_reorder_point;

-- Verification
DESCRIBE aluria_dev.reorder_point;
```

**DEV RESULT:** ran 2026-08-07. Table created — `id`/`company_id`/`product_id`/`location_id` all `varchar(50)` (matches `product.id`/`warehouse_location.id`), `min_stock decimal(15,3)` (matches `warehouse_lot.end_balance`/`warehouse_transaction_item.quantity`, confirmed via live `DESCRIBE` before writing this addendum), `alerted_at` nullable datetime. No rows yet — net-new table, no backfill needed.

**Follow-up fix, same day:** the original `CREATE TABLE` (run without an explicit `COLLATE`) picked up `aluria_dev`'s connection-default collation, `utf8mb4_0900_ai_ci` — but every sibling table (`product`, `warehouse_location`, `warehouse_lot`) was created with `utf8mb4_general_ci`. This surfaced immediately as `Illegal mix of collations (utf8mb4_general_ci,IMPLICIT) and (utf8mb4_0900_ai_ci,IMPLICIT) for operation '='` the moment `buildLowStock()` in `v2/dashboard/index.php` joined `reorder_point` to `product`/`warehouse_location`/`warehouse_lot` column-to-column (caught during this session's own end-to-end verification, not in production). Fixed via `ALTER TABLE aluria_dev.reorder_point CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;` — confirmed via `information_schema.TABLES.TABLE_COLLATION` afterward, and `buildLowStock()` re-verified working. The `CREATE TABLE` block above now has the `COLLATE` clause built in so a fresh run (or the PROD run below) doesn't hit this. Reinforces [[project_schema_collation_mismatch]] — that memory was written for *cross-schema* joins (CORE_SCHEMA vs APP_SCHEMA); this is the same root cause (MySQL 8's connection-default collation diverging from this project's established `utf8mb4_general_ci` convention) showing up *within* a single schema instead, on a table that never pinned its collation explicitly.

No `movira_core_dev` (CORE_SCHEMA) changes in this addendum — the permission keys this feature relies on (`dashboard.low_stock.view`, `warehouse.low_stock.view`, `warehouse.reorder_point.update`) were already seeded by `v24`.

---

## PROD — NOT yet applied

**Do not run this against production without Kage's sign-off first** — same review gate as every other schema change in this project. Purely additive (new table, no existing data touched), so no precondition/backfill step is needed the way `v34` needed one — but the sign-off gate still applies.

```sql
-- ============================================================================
-- v35 PROD — reorder_point table
-- ============================================================================

SET @app_schema := 'aluria'; -- replace with prod's real APP_SCHEMA (from prod's .env)

SET @sql := CONCAT(
  'SELECT COUNT(*) FROM information_schema.TABLES ',
  'WHERE table_schema = ''', @app_schema, ''' AND table_name = ''reorder_point'''
);
PREPARE schema_check FROM @sql; EXECUTE schema_check; DEALLOCATE PREPARE schema_check;
-- If the count above is > 0, skip Step 1 — table already exists.

-- ----------------------------------------------------------------------------
-- STEP 1 — create table
-- ----------------------------------------------------------------------------
SET @sql := CONCAT(
  'CREATE TABLE ', @app_schema, '.reorder_point (
     id          VARCHAR(50) NOT NULL PRIMARY KEY,
     company_id  VARCHAR(50) NOT NULL,
     product_id  VARCHAR(50) NOT NULL,
     location_id VARCHAR(50) NOT NULL,
     min_stock   DECIMAL(15,3) NOT NULL DEFAULT 0,
     alerted_at  DATETIME NULL,
     created_by  VARCHAR(50) NOT NULL,
     created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     updated_by  VARCHAR(50) NULL,
     updated_at  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
     deleted_at  DATETIME NULL,
     KEY idx_reorder_point_company (company_id),
     KEY idx_reorder_point_product (product_id),
     KEY idx_reorder_point_location (location_id)
   ) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
);
PREPARE create_reorder_point FROM @sql;
EXECUTE create_reorder_point;
DEALLOCATE PREPARE create_reorder_point;

-- ----------------------------------------------------------------------------
-- STEP 2 — verification
-- ----------------------------------------------------------------------------
SET @sql := CONCAT('DESCRIBE ', @app_schema, '.reorder_point');
PREPARE verify_describe FROM @sql; EXECUTE verify_describe; DEALLOCATE PREPARE verify_describe;
```

**Deploy order:** schema change (this table) must land before the code deploy — `reorder-point`/`warehouse-transaction`'s new code paths will error on every write/check against prod if the table doesn't exist yet. Deploying the code first without the table is not a safe no-op the way `v34`'s nullable-column addition was, because `checkReorderPointAndNotify()` queries the table unconditionally on every warehouse-transaction post.

---

## Post-migration checklist

- [ ] Get Kage's sign-off, then run the PROD block above — its `CREATE TABLE` already has `COLLATE utf8mb4_general_ci` pinned (see the DEV follow-up fix note above for why that matters), so this should not need the same day-of correction DEV did.
- [ ] `docs/api/reorder-point.md` and `docs/api/warehouse-transaction.md` regenerated via `/standards` (done this session for dev's shape).
- [ ] No permission keys need seeding — `dashboard.low_stock.view`, `warehouse.low_stock.view`, `warehouse.reorder_point.update` already exist per `v24`. Role grants for those keys (which roles can actually see/manage this) are whatever `v24` already assigned — not re-verified here.
- [ ] `warehouse_lot.end_balance` values that predate this change (set manually before `createWarehouseTransaction()` started applying deltas) are not retroactively corrected — this addendum only changes behavior going forward. If dev/prod's existing lot balances are already out of sync with actual stock movements, that's a pre-existing data-quality question, not something this migration attempts to fix.
