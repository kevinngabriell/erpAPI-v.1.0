# Aluria Schema Addendum — v34: `product.product_code` (legacy `skuID`) backfill

**Adds the missing `product_code` column to `aluria_dev.product` and backfills it from the legacy `skuID`** — the v1 (`migration_temp_venken`) `product` table used `skuID` as its primary key (a business-facing product code, e.g. `C-029`, `F-051`), but the v2 rewrite (`v2/master/product/index.php`) never carried that field over: `aluria_dev.product` only has `product_name`, `product_desc`, and `hs_code`. Confirmed via `DESCRIBE migration_temp_venken.product` — `skuID varchar(500) NOT NULL PRIMARY KEY` — versus `DESCRIBE aluria_dev.product`, which has no equivalent column at all. This resolves the gap flagged directly against the legacy `migration_temp_venken.product` table (`SELECT * FROM product` showing `skuID` alongside `productName`/`productDesc`).

This is a pure backfill, not a re-migration: `product` was already migrated (confirmed in [[project_aluria_migration]] and via `aluria_dev.legacy_id_map`) — **96/96 rows** on both sides, 1:1, no gap in row count. Because the legacy PK *was* `skuID`, `legacy_id_map.legacy_id` for `entity_type = 'product'` already holds the exact legacy `skuID` value for every row — no fuzzy matching on `product_name` needed, this is an exact key-based backfill via `legacy_id_map.new_id = product.id`.

## Code change (already applied, this session)

- `v2/master/product/index.php`: `createProduct()`/`updateProduct()` accept an optional `product_code` field (nullable string, `_sql`-suffixed pattern like `hs_code`), with a company-scoped duplicate check (409) when non-empty — mirrors the existing `product_name` duplicate check. `getAllProducts()`'s `search` param now also matches against `product_code`. `getDetailProduct()`/list already return it for free via `p.*`.
- `v2/search/index.php`: `searchProducts()` now also matches `product_code` in its `LIKE` filter (alongside `product_name`/`hs_code`).

## Data findings (dev)

- `migration_temp_venken.product.skuID`: `varchar(500) NOT NULL PRIMARY KEY`, but **not actually always populated** — 1 of 96 rows has `skuID = ''` (empty string, not NULL — the column is `NOT NULL` so legacy code used `''` as its "no code" sentinel). Real max length among the 95 non-blank values is 5 characters (e.g. `C-029`, `F-051`) — `varchar(500)` was never needed.
- No duplicate non-blank `skuID` values among the 96 legacy rows — safe to add an app-level uniqueness check going forward without conflicting with historical data.
- All 96 rows (both legacy and migrated) belong to a single company in dev: `cmp0fcd1b1c010aa508`.
- Column sized `varchar(50)` — matches the existing `account_code.account_code` convention for a short business code field, comfortably larger than the observed 5-character legacy values.

## DEV — already applied

Run against `aluria_dev` on **2026-07-26**, via direct `mysqli` through `v2/connection/db.php` (same tool used for `v32`/`v33`). Idempotent via the `information_schema` guard + `PREPARE`/`EXECUTE` pattern (`v26`/`v30` style).

```sql
-- ============================================================================
-- v34 DEV — product.product_code column + backfill from legacy skuID
-- ============================================================================

-- Step 1: add the column (idempotent)
SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_dev' AND table_name = 'product'
    AND column_name = 'product_code'
);
SET @alter_sql := IF(@schema_check > 0,
  'SELECT ''product.product_code already exists, skipping'' AS note',
  'ALTER TABLE aluria_dev.product ADD COLUMN product_code VARCHAR(50) NULL AFTER product_name'
);
PREPARE alter_product_code FROM @alter_sql;
EXECUTE alter_product_code;
DEALLOCATE PREPARE alter_product_code;

-- Step 2: backfill from legacy_id_map — legacy PK *was* skuID, so this is an
-- exact key join, not a name-matching heuristic. Blank legacy skuID ('') is
-- explicitly excluded, not backfilled as '' — stays NULL (no real code to
-- carry over for that row, same "don't guess" reasoning as every other
-- migration doc in this series). Re-runnable: only touches rows still NULL.
UPDATE aluria_dev.product p
JOIN aluria_dev.legacy_id_map lim
  ON lim.entity_type = 'product' AND lim.new_id = p.id
SET p.product_code = lim.legacy_id
WHERE p.product_code IS NULL
  AND lim.legacy_id IS NOT NULL
  AND lim.legacy_id != '';

-- Verification
DESCRIBE aluria_dev.product;                                     -- expect product_code VARCHAR(50) NULL, right after product_name
SELECT COUNT(*) AS total, COUNT(product_code) AS with_code
FROM aluria_dev.product;                                          -- expect total=96, with_code=95
SELECT id, product_name, product_code FROM aluria_dev.product
WHERE product_code IS NULL;                                       -- expect exactly the 1 row with blank legacy skuID ("Sandwich panel...")
```

**DEV RESULT:** ran 2026-07-26. Column added after `product_name`. Backfill matched all 95 rows with a non-blank legacy `skuID` (`total=96`, `with_code=95`); the 1 row with blank legacy `skuID` (`productName = 'Sandwich panel（LZG-901）'`) correctly left `NULL`. Re-run confirmed idempotent — second pass hit `already exists, skipping` on the column add, and the `UPDATE` matched 0 rows (all already backfilled).

---

## PROD — NOT yet applied

**Do not run this against production without Kage's sign-off first** — same review gate as every other schema/data change in this project. Mirrors the DEV block above exactly, scoped to prod schema names.

```sql
-- Replace aluria_prod / migration_temp_venken below with prod's real schema names
-- (from prod's .env APP_SCHEMA, and wherever prod's legacy source DB lives) before running.

SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_prod' AND table_name = 'product'
    AND column_name = 'product_code'
);
SET @alter_sql := IF(@schema_check > 0,
  'SELECT ''product.product_code already exists, skipping'' AS note',
  'ALTER TABLE aluria_prod.product ADD COLUMN product_code VARCHAR(50) NULL AFTER product_name'
);
PREPARE alter_product_code FROM @alter_sql;
EXECUTE alter_product_code;
DEALLOCATE PREPARE alter_product_code;

UPDATE aluria_prod.product p
JOIN aluria_prod.legacy_id_map lim
  ON lim.entity_type = 'product' AND lim.new_id = p.id
SET p.product_code = lim.legacy_id
WHERE p.product_code IS NULL
  AND lim.legacy_id IS NOT NULL
  AND lim.legacy_id != '';

-- Verification
DESCRIBE aluria_prod.product;
SELECT COUNT(*) AS total, COUNT(product_code) AS with_code FROM aluria_prod.product;
SELECT id, product_name, product_code FROM aluria_prod.product WHERE product_code IS NULL;
```

**Precondition:** this backfill only works if prod's `product` rows were migrated the same way as dev — via `legacy_id_map` with `entity_type = 'product'` rows whose `legacy_id` is the legacy `skuID`. **Verify that assumption against prod's own `legacy_id_map` before running** (`SELECT COUNT(*) FROM aluria_prod.legacy_id_map WHERE entity_type = 'product'` should be > 0 and match `aluria_prod.product`'s row count). If prod's `product` table was populated some other way (hand-entered, different migration script), this join will silently backfill 0 rows — check the verification query's `with_code` count against expectations before considering it done, don't assume success from a clean run.

**Deploy order:** code (the `product_code` field on create/update/search) can deploy before or after this schema change — the column addition is additive and nullable, so old code against the new schema is a no-op (extra column simply unused), and new code against the old schema will error on every write since `product_code` won't exist yet. **Schema change must land in prod before the code deploy**, not after.

---

## Post-migration checklist

- [ ] Get Kage's sign-off, then run the PROD block above, after confirming prod's `legacy_id_map` actually covers `product` the same way dev's does (see precondition above).
- [ ] `docs/api/product.md` regenerated via `/standards` to document the new `product_code` field on create/update/list/detail (done this session for dev's shape — re-verify field is present after prod backfill).
- [ ] No permission keys or role assignments involved — this is a data/schema addition to an already-authenticated, already-scoped module.
- [ ] The 1 dev product row with `product_code IS NULL` (blank legacy `skuID`) is a legitimate "no code" product, not a migration bug — do not backfill it with a guessed value later without checking with the business first.
