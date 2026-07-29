# Aluria Schema Addendum — v27: sales_invoice approval columns

**Extends the sales module's single-approval workflow to `sales_invoice`.** `sales_order` and `sales_sppb` already have `status_id` / `approved_by` / `approved_at` (FK `fk_so_status`/`fk_sppb_status` → `sales_status.id`, composite index `idx_company_status` on `(company_id, status_id)`); v25 added the same three columns to `sales_delivery`/`sales_profit`. `sales_invoice` was the one sales module left without them — its list/detail responses had no way to expose approval state, so the frontend had no field to gate its Approve/Reject buttons on. This addendum, plus the matching `PATCH .../approve`, `PATCH .../reject`, `PATCH .../revise`, and `GET .../export` endpoints added to `v2/sales/sales-invoice/index.php` in the same session, bring `sales_invoice` in line with `sales_order`/`sales_sppb`/`sales_delivery`/`sales_profit`.

This is a **single-approval** workflow (one `approved_by`/`approved_at` pair) — same as `sales_order`/`sales_sppb`/`sales_delivery`/`sales_profit`, and unrelated to the 2-signer Owner+Treasury dual-approval flow added in v21 for `finance_transaction`/`finance_payment`.

Reuses the existing shared `sales_status` lookup table (same one every other sales module points to) — no new status table.

**One deliberate difference from v25:** `status_id` is added here as `NOT NULL` (matching `sales_order`'s original column, confirmed via `SHOW CREATE TABLE`), not left nullable like v25 left `sales_delivery`/`sales_profit`. Since this addendum's backfill covers 100% of existing rows in the same migration, there's no reason to leave the column nullable afterward.

**Backfill choice — explicit user decision, not inferred:** v25 backfilled `sales_delivery`/`sales_profit` to `'Approved'` (documents already in active use before any approval gate existed). For `sales_invoice`, the user was asked directly and chose **`'Draft'`** instead — existing invoices are treated as not-yet-approved rather than grandfathered in. This means all 200 pre-existing `sales_invoice` rows will now appear in any "pending approval" view the frontend builds around `status_name = 'Draft'`. This is intentional, not an oversight — flagged here per the "never guess a backfill default" rule so it's visible to anyone reading this doc later.

---

## DEV — already applied

Run against `aluria_dev` on **2026-07-24**, by Claude (this session), against the Tailscale dev host (`100.98.160.119`), with the user's explicit go-ahead (the initial attempt was blocked by the harness's auto-mode safety classifier for writing to a shared dev database; the user then approved running it).

```sql
-- ============================================================================
-- v27 DEV — sales_invoice approval columns
-- (status_id / approved_by / approved_at, mirrors sales_order/sales_sppb)
-- ============================================================================

USE `aluria_dev`;

-- ----------------------------------------------------------------------------
-- Step 1: add columns (idempotent, information_schema guard — not "ADD COLUMN
-- IF NOT EXISTS", that syntax needs MySQL 8.0.29+ and errors on older
-- servers). status_id starts NULL here so the backfill in Step 2 can run;
-- Step 3 enforces NOT NULL once every row has a value.
-- ----------------------------------------------------------------------------
SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_dev' AND table_name = 'sales_invoice'
    AND column_name = 'status_id'
);
SET @alter_sql := IF(@schema_check > 0,
  'SELECT ''sales_invoice approval columns already exist, skipping'' AS note',
  'ALTER TABLE aluria_dev.sales_invoice
     ADD COLUMN status_id   VARCHAR(50) NULL AFTER bill_to_address,
     ADD COLUMN approved_by VARCHAR(50) NULL AFTER status_id COMMENT ''app_user_id, enforced at app layer'',
     ADD COLUMN approved_at DATETIME    NULL AFTER approved_by'
);
PREPARE alter_sales_invoice FROM @alter_sql;
EXECUTE alter_sales_invoice;
DEALLOCATE PREPARE alter_sales_invoice;

-- ----------------------------------------------------------------------------
-- Step 2: backfill existing rows to 'Draft' (explicit user decision — see
-- note above; approved_by/approved_at stay NULL since no real approver was
-- ever recorded for these historical rows).
-- ----------------------------------------------------------------------------
SET @draft_status_id := (SELECT id FROM aluria_dev.sales_status WHERE status_name = 'Draft' AND deleted_at IS NULL LIMIT 1);

UPDATE aluria_dev.sales_invoice
SET status_id = @draft_status_id
WHERE deleted_at IS NULL AND status_id IS NULL;
-- DEV RESULT: 200 rows backfilled to 'Draft', approved_by/approved_at left NULL.

-- ----------------------------------------------------------------------------
-- Step 3: enforce NOT NULL now that every row has a status_id (idempotent —
-- MODIFY COLUMN is safe to re-run; skip only matters for the guard style
-- consistency with Step 1/4, so guard it the same way).
-- ----------------------------------------------------------------------------
SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_dev' AND table_name = 'sales_invoice'
    AND column_name = 'status_id' AND is_nullable = 'NO'
);
SET @alter_sql := IF(@schema_check > 0,
  'SELECT ''sales_invoice.status_id already NOT NULL, skipping'' AS note',
  'ALTER TABLE aluria_dev.sales_invoice MODIFY COLUMN status_id VARCHAR(50) NOT NULL'
);
PREPARE modify_sales_invoice FROM @alter_sql;
EXECUTE modify_sales_invoice;
DEALLOCATE PREPARE modify_sales_invoice;

-- ----------------------------------------------------------------------------
-- Step 4: index + FK (idempotent guard on the FK's existence).
-- ----------------------------------------------------------------------------
SET @fk_check := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE table_schema = 'aluria_dev' AND table_name = 'sales_invoice'
    AND constraint_name = 'fk_si_status'
);
SET @alter_sql := IF(@fk_check > 0,
  'SELECT ''fk_si_status already exists, skipping'' AS note',
  'ALTER TABLE aluria_dev.sales_invoice
     ADD KEY idx_company_status (company_id, status_id),
     ADD CONSTRAINT fk_si_status FOREIGN KEY (status_id) REFERENCES aluria_dev.sales_status(id)'
);
PREPARE fk_sales_invoice FROM @alter_sql;
EXECUTE fk_sales_invoice;
DEALLOCATE PREPARE fk_sales_invoice;

-- ----------------------------------------------------------------------------
-- Step 5: verification
-- ----------------------------------------------------------------------------
SELECT ss.status_name, COUNT(*) AS c
FROM aluria_dev.sales_invoice si
LEFT JOIN aluria_dev.sales_status ss ON ss.id = si.status_id
WHERE si.deleted_at IS NULL GROUP BY ss.status_name;
-- Expect: 100% 'Draft' immediately after this migration (200 rows on dev).

SHOW COLUMNS FROM aluria_dev.sales_invoice WHERE Field IN ('status_id','approved_by','approved_at');
-- Expect: status_id Null=NO, approved_by/approved_at Null=YES.
```

**Actual dev result (verified this session):**

```
status_name | c
Draft       | 200

Field         Type          Null
status_id     varchar(50)   NO
approved_by   varchar(50)   YES
approved_at   datetime      YES
```

---

## PROD — NOT yet applied

**Do not run this against production without sign-off first** — same review gate as every other schema change in this project. This mirrors the DEV block above exactly (idempotent, safe to re-run), scoped to prod schema names.

```sql
-- ============================================================================
-- v27 PROD — sales_invoice approval columns
-- ============================================================================

-- Replace aluria_prod below with the real prod schema name from prod's .env
-- (APP_SCHEMA) before running.

USE `aluria_prod`;

SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_prod' AND table_name = 'sales_invoice'
    AND column_name = 'status_id'
);
SET @alter_sql := IF(@schema_check > 0,
  'SELECT ''sales_invoice approval columns already exist, skipping'' AS note',
  'ALTER TABLE aluria_prod.sales_invoice
     ADD COLUMN status_id   VARCHAR(50) NULL AFTER bill_to_address,
     ADD COLUMN approved_by VARCHAR(50) NULL AFTER status_id COMMENT ''app_user_id, enforced at app layer'',
     ADD COLUMN approved_at DATETIME    NULL AFTER approved_by'
);
PREPARE alter_sales_invoice FROM @alter_sql;
EXECUTE alter_sales_invoice;
DEALLOCATE PREPARE alter_sales_invoice;

-- Backfill: same explicit decision as DEV — existing prod invoices go to
-- 'Draft', not 'Approved'. Confirm this is still the desired behavior before
-- running against real prod data; if the answer for prod should differ from
-- dev, change 'Draft' below accordingly BEFORE running.
SET @draft_status_id := (SELECT id FROM aluria_prod.sales_status WHERE status_name = 'Draft' AND deleted_at IS NULL LIMIT 1);

UPDATE aluria_prod.sales_invoice
SET status_id = @draft_status_id
WHERE deleted_at IS NULL AND status_id IS NULL;

SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_prod' AND table_name = 'sales_invoice'
    AND column_name = 'status_id' AND is_nullable = 'NO'
);
SET @alter_sql := IF(@schema_check > 0,
  'SELECT ''sales_invoice.status_id already NOT NULL, skipping'' AS note',
  'ALTER TABLE aluria_prod.sales_invoice MODIFY COLUMN status_id VARCHAR(50) NOT NULL'
);
PREPARE modify_sales_invoice FROM @alter_sql;
EXECUTE modify_sales_invoice;
DEALLOCATE PREPARE modify_sales_invoice;

SET @fk_check := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE table_schema = 'aluria_prod' AND table_name = 'sales_invoice'
    AND constraint_name = 'fk_si_status'
);
SET @alter_sql := IF(@fk_check > 0,
  'SELECT ''fk_si_status already exists, skipping'' AS note',
  'ALTER TABLE aluria_prod.sales_invoice
     ADD KEY idx_company_status (company_id, status_id),
     ADD CONSTRAINT fk_si_status FOREIGN KEY (status_id) REFERENCES aluria_prod.sales_status(id)'
);
PREPARE fk_sales_invoice FROM @alter_sql;
EXECUTE fk_sales_invoice;
DEALLOCATE PREPARE fk_sales_invoice;

-- Verification — run before considering prod cutover complete:
SELECT ss.status_name, COUNT(*) AS c
FROM aluria_prod.sales_invoice si
LEFT JOIN aluria_prod.sales_status ss ON ss.id = si.status_id
WHERE si.deleted_at IS NULL GROUP BY ss.status_name;

SHOW COLUMNS FROM aluria_prod.sales_invoice WHERE Field IN ('status_id','approved_by','approved_at');
```

---

## Post-migration checklist (both environments)

- [ ] **Re-confirm the `'Draft'` backfill choice before running on prod.** It was an explicit answer to a direct question this session, scoped at the time to dev — if prod's existing invoices are considered "already finalized" by the business (unlike what was decided for dev), the backfill target should be `'Approved'` instead, matching v25's reasoning. Do not assume dev's answer silently carries over; ask again if there's any doubt.
- [ ] Confirm prod's `sales_status` table already has a `'Draft'` row with that exact `status_name` before running — the backfill `@draft_status_id` lookup silently resolves to `NULL` if the name doesn't match, which would null out every backfilled row's `status_id` and then make the Step 3 `MODIFY ... NOT NULL` fail loudly (which is the safe failure mode here — better than a silent bad backfill).
- [ ] The API code for this feature (`v2/sales/sales-invoice/index.php`) already shipped on dev ahead of this doc being written in the same session — ship this migration to prod before or in the same release as that code. Old code (no `status_id` references) is safe to run against the new schema; new code against the old (pre-migration) prod schema will 500 on every `sales-invoice` list/detail/approve/reject/revise/export call with `Unknown column 'si.status_id'`.
- [ ] No new permission keys were introduced by this migration — `approve`/`reject`/`revise` on `sales_invoice` use the same auth gate (`requireAuth()`) as every other sales endpoint, not a dedicated permission slot.
- [ ] If the frontend's "pending approval" list/badge counts invoices by `status_name = 'Draft'`, expect prod's count to jump by however many pre-existing invoices prod has once this backfill runs — same caveat as dev's 200, scaled to prod's real row count. Confirm this is expected before shipping, not discovered after.
