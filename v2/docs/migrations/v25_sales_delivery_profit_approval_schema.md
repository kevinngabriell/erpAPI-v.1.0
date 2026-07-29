# Aluria Schema Addendum — v25: sales_delivery + sales_profit single-approval columns

**Extends the sales module's approval stage to sales_delivery and sales_profit.** `sales_order` and `sales_sppb` already have `status_id` / `approved_by` / `approved_at` (FK `fk_so_status`/`fk_sppb_status` → `sales_status.id`, composite index `idx_company_status` on `(company_id, status_id)`), used by the single-approval flow (Draft → Approved / Rejected → back to Draft on revise). `sales_delivery` and `sales_profit` had none of these columns — the approve/reject/revise code shipped in the "Add approval workflows across purchase/sales/finance modules" commit assumed they existed, causing every list/detail/approve/reject/revise call on these two modules to 500 with `Unknown column 'sd.approved_by' in 'on clause'` / `Unknown column 'sp.approved_by' in 'on clause'`.

This is a **single-approval** workflow (one `approved_by`/`approved_at` pair), matching how `sales_order`/`sales_sppb` already work. It is unrelated to the 2-signer Owner+Treasury dual-approval flow added in v21 for `finance_transaction`/`finance_payment`.

Both tables reuse the existing shared `sales_status` lookup table (same one `sales_order`/`sales_sppb` already point to) — no new status table, just three new columns per table, mirroring `sales_order`'s column types, FK, and index pattern (exact precedent: v22 did the same thing for `purchase_invoice`/`purchase_receive`).

---

## DEV — already applied

Run against `aluria_dev` on **2026-07-23**, by Claude (this session), against the Tailscale dev host (`100.98.160.119`).

```sql
-- ============================================================================
-- v25 DEV — sales_delivery + sales_profit approval columns
-- (status_id / approved_by / approved_at, mirrors sales_order/sales_sppb)
-- ============================================================================

USE `aluria_dev`;

-- ----------------------------------------------------------------------------
-- Step 1: sales_delivery approval columns (idempotent, information_schema
-- guard — not "ADD COLUMN IF NOT EXISTS", that syntax needs MySQL 8.0.29+ and
-- errors on older servers).
-- ----------------------------------------------------------------------------
SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_dev' AND table_name = 'sales_delivery'
    AND column_name = 'status_id'
);
SET @alter_sql := IF(@schema_check > 0,
  'SELECT ''sales_delivery approval columns already exist, skipping'' AS note',
  'ALTER TABLE aluria_dev.sales_delivery
     ADD COLUMN status_id   VARCHAR(50) NULL AFTER eta_date,
     ADD COLUMN approved_by VARCHAR(50) NULL AFTER status_id,
     ADD COLUMN approved_at DATETIME    NULL AFTER approved_by,
     ADD CONSTRAINT fk_sd_status FOREIGN KEY (status_id) REFERENCES aluria_dev.sales_status(id),
     ADD INDEX idx_company_status (company_id, status_id)'
);
PREPARE alter_sales_delivery FROM @alter_sql;
EXECUTE alter_sales_delivery;
DEALLOCATE PREPARE alter_sales_delivery;

-- ----------------------------------------------------------------------------
-- Step 2: sales_profit approval columns (same pattern as Step 1).
-- ----------------------------------------------------------------------------
SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_dev' AND table_name = 'sales_profit'
    AND column_name = 'status_id'
);
SET @alter_sql := IF(@schema_check > 0,
  'SELECT ''sales_profit approval columns already exist, skipping'' AS note',
  'ALTER TABLE aluria_dev.sales_profit
     ADD COLUMN status_id   VARCHAR(50) NULL AFTER customer_id,
     ADD COLUMN approved_by VARCHAR(50) NULL AFTER status_id,
     ADD COLUMN approved_at DATETIME    NULL AFTER approved_by,
     ADD CONSTRAINT fk_sp_status FOREIGN KEY (status_id) REFERENCES aluria_dev.sales_status(id),
     ADD INDEX idx_company_status (company_id, status_id)'
);
PREPARE alter_sales_profit FROM @alter_sql;
EXECUTE alter_sales_profit;
DEALLOCATE PREPARE alter_sales_profit;

-- ----------------------------------------------------------------------------
-- Step 3: backfill existing rows. Every sales_delivery/sales_profit row that
-- existed before this addendum predates the approval workflow entirely — same
-- "don't guess a real approver identity" policy used throughout this
-- project's data migrations (v20, v21, v22). status_id is backfilled to the
-- 'Approved' status (these documents were already in active use before any
-- approval gate existed, so treating them as already-approved is the correct
-- historical state) — approved_by/approved_at stay NULL since no real
-- approver was ever recorded for them.
-- ----------------------------------------------------------------------------
SET @approved_status_id := (SELECT id FROM aluria_dev.sales_status WHERE status_name = 'Approved' AND deleted_at IS NULL LIMIT 1);

UPDATE aluria_dev.sales_delivery
SET status_id = @approved_status_id
WHERE deleted_at IS NULL AND status_id IS NULL;
-- DEV RESULT: 440 rows backfilled to 'Approved', approved_by/approved_at left NULL.

UPDATE aluria_dev.sales_profit
SET status_id = @approved_status_id
WHERE deleted_at IS NULL AND status_id IS NULL;
-- DEV RESULT: 467 rows backfilled to 'Approved', approved_by/approved_at left NULL.

-- ----------------------------------------------------------------------------
-- Step 4: verification
-- ----------------------------------------------------------------------------
SELECT ss.status_name, COUNT(*) AS c
FROM aluria_dev.sales_delivery sd
LEFT JOIN aluria_dev.sales_status ss ON ss.id = sd.status_id
WHERE sd.deleted_at IS NULL GROUP BY ss.status_name;
-- Expect: 100% 'Approved' immediately after this migration.

SELECT ss.status_name, COUNT(*) AS c
FROM aluria_dev.sales_profit sp
LEFT JOIN aluria_dev.sales_status ss ON ss.id = sp.status_id
WHERE sp.deleted_at IS NULL GROUP BY ss.status_name;
-- Expect: 100% 'Approved' immediately after this migration.

SHOW COLUMNS FROM aluria_dev.sales_delivery WHERE Field IN ('status_id','approved_by','approved_at');
SHOW COLUMNS FROM aluria_dev.sales_profit WHERE Field IN ('status_id','approved_by','approved_at');
```

---

## PROD — NOT yet applied

**Do not run this against production without Kage's sign-off first** — same review gate as every other schema change in this project. This mirrors the DEV block above exactly (idempotent, safe to re-run), scoped to prod schema names.

```sql
-- ============================================================================
-- v25 PROD — sales_delivery + sales_profit approval columns
-- ============================================================================

-- Replace aluria_prod below with the real prod schema name from prod's .env
-- (APP_SCHEMA) before running.

USE `aluria_prod`;

SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_prod' AND table_name = 'sales_delivery'
    AND column_name = 'status_id'
);
SET @alter_sql := IF(@schema_check > 0,
  'SELECT ''sales_delivery approval columns already exist, skipping'' AS note',
  'ALTER TABLE aluria_prod.sales_delivery
     ADD COLUMN status_id   VARCHAR(50) NULL AFTER eta_date,
     ADD COLUMN approved_by VARCHAR(50) NULL AFTER status_id,
     ADD COLUMN approved_at DATETIME    NULL AFTER approved_by,
     ADD CONSTRAINT fk_sd_status FOREIGN KEY (status_id) REFERENCES aluria_prod.sales_status(id),
     ADD INDEX idx_company_status (company_id, status_id)'
);
PREPARE alter_sales_delivery FROM @alter_sql;
EXECUTE alter_sales_delivery;
DEALLOCATE PREPARE alter_sales_delivery;

SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_prod' AND table_name = 'sales_profit'
    AND column_name = 'status_id'
);
SET @alter_sql := IF(@schema_check > 0,
  'SELECT ''sales_profit approval columns already exist, skipping'' AS note',
  'ALTER TABLE aluria_prod.sales_profit
     ADD COLUMN status_id   VARCHAR(50) NULL AFTER customer_id,
     ADD COLUMN approved_by VARCHAR(50) NULL AFTER status_id,
     ADD COLUMN approved_at DATETIME    NULL AFTER approved_by,
     ADD CONSTRAINT fk_sp_status FOREIGN KEY (status_id) REFERENCES aluria_prod.sales_status(id),
     ADD INDEX idx_company_status (company_id, status_id)'
);
PREPARE alter_sales_profit FROM @alter_sql;
EXECUTE alter_sales_profit;
DEALLOCATE PREPARE alter_sales_profit;

-- Backfill: every sales_delivery/sales_profit row that predates this
-- migration is historical and was already in active use before any approval
-- gate existed — same policy as DEV Step 3 above. VERIFY the resulting row
-- count against prod's real totals before treating this as done (dev
-- backfilled 440/467; prod will differ).
SET @approved_status_id := (SELECT id FROM aluria_prod.sales_status WHERE status_name = 'Approved' AND deleted_at IS NULL LIMIT 1);

UPDATE aluria_prod.sales_delivery
SET status_id = @approved_status_id
WHERE deleted_at IS NULL AND status_id IS NULL;

UPDATE aluria_prod.sales_profit
SET status_id = @approved_status_id
WHERE deleted_at IS NULL AND status_id IS NULL;

-- Verification — run before considering prod cutover complete:
SELECT ss.status_name, COUNT(*) AS c
FROM aluria_prod.sales_delivery sd
LEFT JOIN aluria_prod.sales_status ss ON ss.id = sd.status_id
WHERE sd.deleted_at IS NULL GROUP BY ss.status_name;

SELECT ss.status_name, COUNT(*) AS c
FROM aluria_prod.sales_profit sp
LEFT JOIN aluria_prod.sales_status ss ON ss.id = sp.status_id
WHERE sp.deleted_at IS NULL GROUP BY ss.status_name;
```

---

## Post-migration checklist (both environments)

- [ ] Confirm the prod `sales_delivery`/`sales_profit` backfill row counts match prod's actual non-deleted totals (dev was 440/467 respectively — prod will be different numbers, do not assume they match).
- [ ] Confirm prod's `sales_status` table already has an `'Approved'` row with that exact `status_name` before running — the backfill `@approved_status_id` lookup silently resolves to `NULL` if the name doesn't match, which would null out every backfilled row's `status_id` instead of erroring loudly. Verify with a `SELECT` first.
- [ ] The API code for this feature (`v2/sales/sales-delivery/index.php`, `v2/sales/sales-profit/index.php`) already shipped ahead of this schema change on dev — this migration should have gone out in the same release. If the same order-of-operations gap exists for prod (code deployed before schema), every list/detail/approve/reject/revise call on these two modules will 500 in prod until this migration runs. Ship this migration to prod before or in the same release as that code, not after.
- [ ] No new permission keys were introduced by this migration — approve/reject/revise on these two modules use the same auth gate (`requireAuth()`) as every other sales endpoint, not a dedicated permission slot.
