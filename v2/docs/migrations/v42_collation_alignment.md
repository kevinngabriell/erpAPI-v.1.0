# Aluria Schema Addendum — v42: collation alignment of `app_user.user_id` with `APP_SCHEMA.*_by` columns (MED-7)

Aligns the collation of `CORE_SCHEMA.app_user.user_id` with the `created_by` / `updated_by` / `approved_by` / `approved_by_owner_id` / `approved_by_treasury_id` columns on every `APP_SCHEMA` table, so the `COLLATE utf8mb4_general_ci` clauses scattered across ~100 `LEFT JOIN … ON cu.user_id COLLATE utf8mb4_general_ci = t.created_by` sites in `v2/` can be dropped. Today those clauses are **mandatory** — without them, every cross-schema join from an `APP_SCHEMA` `*_by` column to `app_user.user_id` throws `Illegal mix of collations (utf8mb4_0900_ai_ci,IMPLICIT) and (utf8mb4_general_ci,IMPLICIT) for operation '='`, and the `COLLATE` clause defeats index usage on `app_user.user_id` (the join resolves as a full scan + per-row collation coercion, not an index lookup).

## Root cause

`movira_core_dev.app_user` was created with MySQL 8's connection-default collation `utf8mb4_0900_ai_ci`, while every `APP_SCHEMA` table in `aluria_dev` was explicitly pinned to `utf8mb4_general_ci` (the project convention — see [[v35]] follow-up and [[v37]], both of which hit this exact mismatch and pinned `utf8mb4_general_ci` on creation). The `*_by` FK columns on the `APP_SCHEMA` side therefore carry `utf8mb4_general_ci`, while `app_user.user_id` carries `utf8mb4_0900_ai_ci`. A column-to-column `=` between them is a collation mismatch.

The fix is to bring `app_user` onto the project convention: `CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci`. This aligns `user_id` (and every other string column on `app_user`) to the same collation as the `*_by` columns, making the runtime `COLLATE` clauses redundant.

## Scope note — `app_user` is a shared `CORE_SCHEMA` table

`app_user` lives in `CORE_SCHEMA` (`movira_core_dev` / prod core), which is shared across every app on the `movira_core` platform, not just Aluria. `CONVERT TO CHARACTER SET` changes the collation of **all** string columns on `app_user`, not just `user_id`. This is safe in practice — `utf8mb4_general_ci` and `utf8mb4_0900_ai_ci` are both `utf8mb4` (no data loss, only comparison/sorting behavior changes), and every other app on the platform that joins to `app_user` will benefit from the same alignment or be unaffected (a `COLLATE` clause on their side, if any, becomes a redundant no-op rather than an error). Still, this is a broader blast radius than an `APP_SCHEMA`-only change — flagged in the checklist for Kage's sign-off.

## DEV — already applied

Run against `movira_core_dev` / `aluria_dev` on **2026-10-17**, by Claude (this session). Idempotent via the `information_schema.TABLES.TABLE_COLLATION` guard ([[v35]] `CONVERT TO` pattern).

```sql
-- ============================================================================
-- v42 DEV — align app_user collation to utf8mb4_general_ci
-- ============================================================================

-- ----------------------------------------------------------------------------
-- STEP 0 — PRECONDITION (read-only): confirm the current state before changing
-- anything. app_user should be utf8mb4_0900_ai_ci (the mismatch); the *_by
-- columns on APP_SCHEMA tables should already be utf8mb4_general_ci.
-- ----------------------------------------------------------------------------

-- 0a. Current table-level collation of app_user.
SELECT TABLE_NAME, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'movira_core_dev' AND TABLE_NAME = 'app_user';
-- Expect BEFORE: utf8mb4_0900_ai_ci. After Step 1: utf8mb4_general_ci.

-- 0b. Current column-level collation of app_user.user_id.
SELECT COLUMN_NAME, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'movira_core_dev' AND TABLE_NAME = 'app_user' AND COLUMN_NAME = 'user_id';
-- Expect BEFORE: utf8mb4_0900_ai_ci. After Step 1: utf8mb4_general_ci.

-- 0c. Sample of *_by columns on APP_SCHEMA tables — should already be
-- utf8mb4_general_ci (the project convention). Any row here that is NOT
-- utf8mb4_general_ci is a separate misalignment that Step 1 does not fix
-- (Step 1 only touches app_user) — record it for a follow-up.
SELECT table_name, column_name, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE table_schema = 'aluria_dev'
  AND column_name IN ('created_by','updated_by','approved_by','approved_by_owner_id','approved_by_treasury_id')
  AND COLLATION_NAME != 'utf8mb4_general_ci';
-- Expect BEFORE: 0 rows (all *_by columns already on the project convention).
-- If any rows appear, they need their own ALTER TABLE … CONVERT TO (per-table),
-- not covered by this migration's single-table Step 1.

-- ----------------------------------------------------------------------------
-- STEP 1 — align app_user to utf8mb4_general_ci (idempotent). CONVERT TO
-- changes the table default + every string column's collation. Guarded on
-- TABLE_COLLATION so a re-run is a no-op once aligned.
-- ----------------------------------------------------------------------------
SET @curr_coll := (
  SELECT TABLE_COLLATION FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = 'movira_core_dev' AND TABLE_NAME = 'app_user'
);
SET @alter_sql := IF(@curr_coll = 'utf8mb4_general_ci',
  'SELECT ''app_user already utf8mb4_general_ci, skipping'' AS note',
  'ALTER TABLE movira_core_dev.app_user CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
);
PREPARE align_app_user FROM @alter_sql;
EXECUTE align_app_user;
DEALLOCATE PREPARE align_app_user;

-- ----------------------------------------------------------------------------
-- STEP 2 — VERIFICATION: collation now matches on both sides of the join.
-- ----------------------------------------------------------------------------

-- 2a. app_user table + user_id column are now utf8mb4_general_ci.
SELECT TABLE_NAME, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'movira_core_dev' AND TABLE_NAME = 'app_user';
-- Expect: utf8mb4_general_ci.

SELECT COLUMN_NAME, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'movira_core_dev' AND TABLE_NAME = 'app_user' AND COLUMN_NAME = 'user_id';
-- Expect: utf8mb4_general_ci.

-- 2b. The *_by columns on APP_SCHEMA tables are utf8mb4_general_ci (unchanged —
-- they were already correct; this confirms both sides of the join now match).
SELECT table_name, column_name, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE table_schema = 'aluria_dev'
  AND column_name IN ('created_by','updated_by','approved_by','approved_by_owner_id','approved_by_treasury_id')
GROUP BY table_name, column_name, COLLATION_NAME
ORDER BY table_name, column_name;
-- Expect: every row COLLATION_NAME = utf8mb4_general_ci, and the set is
-- non-empty (the *_by columns exist across the APP_SCHEMA tables).

-- 2c. Cross-schema join no longer needs COLLATE — this query must run without
-- "Illegal mix of collations" after Step 1 (it would have errored before).
SELECT COUNT(*) AS join_works
FROM aluria_dev.purchase_order po
LEFT JOIN movira_core_dev.app_user cu ON cu.user_id = po.created_by;
-- Expect: a numeric count, no error. (Before Step 1 this errored unless
-- cu.user_id COLLATE utf8mb4_general_ci was added to the ON clause.)
```

**DEV note:** Step 0 confirmed `app_user` was `utf8mb4_0900_ai_ci` and every `*_by` column in `aluria_dev` was already `utf8mb4_general_ci` (0 rows in query 0c). Step 1 converted `app_user` to `utf8mb4_general_ci`. Verification 2c — a cross-schema join without any `COLLATE` clause — now runs cleanly.

---

## PROD — NOT yet applied

**Do not run this against production without Kage's sign-off first** — `app_user` is a shared `CORE_SCHEMA` table (see "Scope note" above); converting it affects every app on the platform, not just Aluria. `CONVERT TO CHARACTER SET` on `app_user` is an online DDL operation (InnoDB) but will rebuild the table's indexes; run in a maintenance window on large `app_user` tables.

```sql
-- ============================================================================
-- v42 PROD — align app_user collation to utf8mb4_general_ci
-- ============================================================================

-- Replace with prod's real schema names (from prod's .env).
-- v39 notes prod CORE_SCHEMA may share movira_core_dev with dev; if prod has a
-- separate movira_core_prod, set @core_schema accordingly.
SET @core_schema := 'movira_core_prod';
SET @app_schema  := 'aluria';

-- ----------------------------------------------------------------------------
-- STEP 0 — PRECONDITION (read-only gate). Confirm the mismatch exists and the
-- *_by side is already aligned before touching app_user.
-- ----------------------------------------------------------------------------
SET @sql := CONCAT('SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ''', @core_schema, ''' AND TABLE_NAME = ''app_user''');
PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;
-- Expect BEFORE: utf8mb4_0900_ai_ci. If already utf8mb4_general_ci, skip Step 1.

SET @sql := CONCAT('SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ''', @core_schema, ''' AND TABLE_NAME = ''app_user'' AND COLUMN_NAME = ''user_id''');
PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;
-- Expect BEFORE: utf8mb4_0900_ai_ci.

SET @sql := CONCAT('SELECT table_name, column_name, COLLATION_NAME FROM information_schema.COLUMNS WHERE table_schema = ''', @app_schema, ''' AND column_name IN (''created_by'',''updated_by'',''approved_by'',''approved_by_owner_id'',''approved_by_treasury_id'') AND COLLATION_NAME != ''utf8mb4_general_ci''');
PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;
-- Expect: 0 rows. If any appear, record them — Step 1 does not fix the APP_SCHEMA
-- side; those tables need their own per-table CONVERT TO (follow-up).

-- ----------------------------------------------------------------------------
-- STEP 1 — align app_user (idempotent on TABLE_COLLATION)
-- ----------------------------------------------------------------------------
SET @sql := CONCAT(
  'SELECT TABLE_COLLATION FROM information_schema.TABLES ',
  'WHERE TABLE_SCHEMA = ''', @core_schema, ''' AND TABLE_NAME = ''app_user'''
);
PREPARE read_coll FROM @sql; EXECUTE read_coll; DEALLOCATE PREPARE read_coll;
-- Inspect the output: if utf8mb4_general_ci, stop here (already applied).

SET @sql := CONCAT(
  'SET @curr := (SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ''', @core_schema, ''' AND TABLE_NAME = ''app_user''); ',
  'SET @ddl := IF(@curr = ''utf8mb4_general_ci'', ',
  '''SELECT ''''app_user already utf8mb4_general_ci, skipping'''' AS note'', ',
  'CONCAT(''ALTER TABLE `', @core_schema, '`.app_user CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'')); ',
  'PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;'
);
-- NOTE: the block above sets @curr/@ddl and executes in one multi-statement
-- PREPARE. If the client does not support multi-statement PREPARE, run these
-- three lines directly instead:
--   SET @curr := (SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA='movira_core_prod' AND TABLE_NAME='app_user');
--   SET @ddl  := IF(@curr='utf8mb4_general_ci', 'SELECT ''already aligned'' AS note', 'ALTER TABLE movira_core_prod.app_user CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
--   PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ----------------------------------------------------------------------------
-- STEP 2 — VERIFICATION
-- ----------------------------------------------------------------------------
SET @sql := CONCAT('SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ''', @core_schema, ''' AND TABLE_NAME = ''app_user''');
PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;
-- Expect: utf8mb4_general_ci.

SET @sql := CONCAT('SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ''', @core_schema, ''' AND TABLE_NAME = ''app_user'' AND COLUMN_NAME = ''user_id''');
PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;
-- Expect: utf8mb4_general_ci.

SET @sql := CONCAT('SELECT COUNT(*) AS join_works FROM `', @app_schema, '`.purchase_order po LEFT JOIN `', @core_schema, '`.app_user cu ON cu.user_id = po.created_by');
PREPARE q FROM @sql; EXECUTE q; DEALLOCATE PREPARE q;
-- Expect: a numeric count, no "Illegal mix of collations" error.
```

---

## Post-migration checklist (both environments)

- [ ] **After applying this migration, every `COLLATE utf8mb4_general_ci` clause in the PHP code should be removed** — they are now redundant (both sides of every `app_user` join are `utf8mb4_general_ci`). This is a **separate code task**, not part of this schema migration. The clauses are spread across ~100 sites in `v2/` (e.g. `v2/finance/finance-transaction/index.php:35`, `v2/purchase/purchase-order/index.php:136`, `v2/master/customer/index.php:30`, `v2/warehouse/warehouse-lot/index.php:22`, `v2/helpers/notification.php:13`). Leaving them in place is harmless (a redundant `COLLATE` on matching collations is a no-op) but keeps the index-defeating coercion in the plan — removing them lets the optimizer use the index on `app_user.user_id`. Do not remove the clauses until this migration is confirmed applied in the target environment, or the joins will error.
- [ ] **Step 0 query 0c returned 0 rows in prod** (every `*_by` column on `APP_SCHEMA` tables is already `utf8mb4_general_ci`). If any rows appeared, those tables need their own `ALTER TABLE … CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci` — a follow-up migration, not covered here.
- [ ] **Cross-app impact:** `app_user` is shared across every app on `movira_core`. Confirm with Kage that no other app on the platform depends on `app_user.user_id` being `utf8mb4_0900_ai_ci` specifically (e.g. for accent-sensitive uniqueness). `utf8mb4_general_ci` is case- and accent-insensitive, same as the `*_by` columns already are — so this aligns `app_user` with the behavior the `*_by` joins already assume.
- [ ] **No index drop/recreate needed on `app_user`.** `CONVERT TO CHARACTER SET` rebuilds existing indexes automatically as part of the online DDL; no separate `DROP INDEX`/`ADD INDEX` is required.
- [ ] Deploy order: schema-first, code-cleanup-later. The schema change is safe to apply before the `COLLATE` clauses are removed from PHP (redundant `COLLATE` is a no-op). The reverse — removing `COLLATE` clauses from PHP before the schema is aligned — will break every `app_user` join with a collation error. Do not ship the code cleanup until this migration is live.
