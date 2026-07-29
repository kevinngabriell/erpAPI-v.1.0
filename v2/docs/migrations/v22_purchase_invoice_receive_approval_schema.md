# Aluria Schema Addendum — v22: purchase_invoice + purchase_receive single-approval columns

**Extends the purchase module's approval stage to purchase_invoice and purchase_receive.** `purchase_order` already has `status_id` / `approved_by` / `approved_at` (FK `fk_po_status` → `purchase_status.id`, composite index `idx_company_status` on `(company_id, status_id)`), used by the sales-style single-approval flow (Draft → Approved / Rejected → back to Draft on revise). `purchase_invoice` and `purchase_receive` had none of these columns — no approval concept existed on either table before this change.

This is a **single-approval** workflow (one `approved_by`/`approved_at` pair), matching how sales and `purchase_order` already work. It is unrelated to the 2-signer Owner+Treasury dual-approval flow added in v21 for `finance_transaction`/`finance_payment` — that pattern is intentionally scoped to Finance only and is not being extended here.

Both new tables reuse the existing shared `purchase_status` lookup table (same one `purchase_order` already points to) — no new status table, just three new columns per table, mirroring `purchase_order`'s column types, FK, and index exactly.

---

## DEV — already applied

Run against `aluria_dev` on **2026-07-20**, by Claude (this session), against the Tailscale dev host (`100.98.160.119`). Verified with a smoke test: created a draft purchase invoice/receive, approved each (status flipped to `Approved`, `approved_by`/`approved_at` set), rejected a second pair (`Rejected`), revised the rejected pair back to `Draft` — all temp test rows created for this smoke test were hard-deleted afterward; nothing test-related was left in the DB.

```sql
-- ============================================================================
-- v22 DEV — purchase_invoice + purchase_receive approval columns
-- (status_id / approved_by / approved_at, mirrors purchase_order exactly)
-- ============================================================================

USE `aluria_dev`;

-- ----------------------------------------------------------------------------
-- Step 1: purchase_invoice approval columns (idempotent, information_schema
-- guard — not "ADD COLUMN IF NOT EXISTS", that syntax needs MySQL 8.0.29+ and
-- errors on older servers).
-- ----------------------------------------------------------------------------
SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_dev' AND table_name = 'purchase_invoice'
    AND column_name = 'status_id'
);
SET @alter_sql := IF(@schema_check > 0,
  'SELECT ''purchase_invoice approval columns already exist, skipping'' AS note',
  'ALTER TABLE aluria_dev.purchase_invoice
     ADD COLUMN status_id   VARCHAR(50) NULL AFTER term_id,
     ADD COLUMN approved_by VARCHAR(50) NULL AFTER status_id,
     ADD COLUMN approved_at DATETIME    NULL AFTER approved_by,
     ADD CONSTRAINT fk_pi_status FOREIGN KEY (status_id) REFERENCES aluria_dev.purchase_status(id),
     ADD INDEX idx_company_status (company_id, status_id)'
);
PREPARE alter_purchase_invoice FROM @alter_sql;
EXECUTE alter_purchase_invoice;
DEALLOCATE PREPARE alter_purchase_invoice;

-- ----------------------------------------------------------------------------
-- Step 2: purchase_receive approval columns (same pattern as Step 1).
-- ----------------------------------------------------------------------------
SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_dev' AND table_name = 'purchase_receive'
    AND column_name = 'status_id'
);
SET @alter_sql := IF(@schema_check > 0,
  'SELECT ''purchase_receive approval columns already exist, skipping'' AS note',
  'ALTER TABLE aluria_dev.purchase_receive
     ADD COLUMN status_id   VARCHAR(50) NULL AFTER ship_via_id,
     ADD COLUMN approved_by VARCHAR(50) NULL AFTER status_id,
     ADD COLUMN approved_at DATETIME    NULL AFTER approved_by,
     ADD CONSTRAINT fk_pr_status FOREIGN KEY (status_id) REFERENCES aluria_dev.purchase_status(id),
     ADD INDEX idx_company_status (company_id, status_id)'
);
PREPARE alter_purchase_receive FROM @alter_sql;
EXECUTE alter_purchase_receive;
DEALLOCATE PREPARE alter_purchase_receive;

-- ----------------------------------------------------------------------------
-- Step 3: backfill existing rows. Every purchase_invoice/purchase_receive row
-- that existed before this addendum predates the approval workflow entirely —
-- same "don't guess a real approver identity" policy used throughout this
-- project's data migrations (v20, v21). status_id is backfilled to the
-- 'Approved' status (these documents were already in active use before any
-- approval gate existed, so treating them as already-approved is the correct
-- historical state) — approved_by/approved_at stay NULL since no real
-- approver was ever recorded for them.
-- ----------------------------------------------------------------------------
SET @approved_status_id := (SELECT id FROM aluria_dev.purchase_status WHERE status_name = 'Approved' AND deleted_at IS NULL LIMIT 1);

UPDATE aluria_dev.purchase_invoice
SET status_id = @approved_status_id
WHERE deleted_at IS NULL;
-- DEV RESULT: 136 rows backfilled to 'Approved', approved_by/approved_at left NULL.

UPDATE aluria_dev.purchase_receive
SET status_id = @approved_status_id
WHERE deleted_at IS NULL;
-- DEV RESULT: 136 rows backfilled to 'Approved', approved_by/approved_at left NULL.

-- ----------------------------------------------------------------------------
-- Step 4: verification
-- ----------------------------------------------------------------------------
SELECT ps.status_name, COUNT(*) AS c
FROM aluria_dev.purchase_invoice pi
LEFT JOIN aluria_dev.purchase_status ps ON ps.id = pi.status_id
WHERE pi.deleted_at IS NULL GROUP BY ps.status_name;
-- Expect: 100% 'Approved' immediately after this migration.

SELECT ps.status_name, COUNT(*) AS c
FROM aluria_dev.purchase_receive pr
LEFT JOIN aluria_dev.purchase_status ps ON ps.id = pr.status_id
WHERE pr.deleted_at IS NULL GROUP BY ps.status_name;
-- Expect: 100% 'Approved' immediately after this migration.

SHOW COLUMNS FROM aluria_dev.purchase_invoice WHERE Field IN ('status_id','approved_by','approved_at');
SHOW COLUMNS FROM aluria_dev.purchase_receive WHERE Field IN ('status_id','approved_by','approved_at');
```

---

## PROD — NOT yet applied

**Do not run this against production without Kage's sign-off first** — same review gate as every other schema change in this project. This mirrors the DEV block above exactly (idempotent, safe to re-run), scoped to prod schema names.

```sql
-- ============================================================================
-- v22 PROD — purchase_invoice + purchase_receive approval columns
-- ============================================================================

-- Replace aluria_prod below with the real prod schema name from prod's .env
-- (APP_SCHEMA) before running.

USE `aluria_prod`;

SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_prod' AND table_name = 'purchase_invoice'
    AND column_name = 'status_id'
);
SET @alter_sql := IF(@schema_check > 0,
  'SELECT ''purchase_invoice approval columns already exist, skipping'' AS note',
  'ALTER TABLE aluria_prod.purchase_invoice
     ADD COLUMN status_id   VARCHAR(50) NULL AFTER term_id,
     ADD COLUMN approved_by VARCHAR(50) NULL AFTER status_id,
     ADD COLUMN approved_at DATETIME    NULL AFTER approved_by,
     ADD CONSTRAINT fk_pi_status FOREIGN KEY (status_id) REFERENCES aluria_prod.purchase_status(id),
     ADD INDEX idx_company_status (company_id, status_id)'
);
PREPARE alter_purchase_invoice FROM @alter_sql;
EXECUTE alter_purchase_invoice;
DEALLOCATE PREPARE alter_purchase_invoice;

SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_prod' AND table_name = 'purchase_receive'
    AND column_name = 'status_id'
);
SET @alter_sql := IF(@schema_check > 0,
  'SELECT ''purchase_receive approval columns already exist, skipping'' AS note',
  'ALTER TABLE aluria_prod.purchase_receive
     ADD COLUMN status_id   VARCHAR(50) NULL AFTER ship_via_id,
     ADD COLUMN approved_by VARCHAR(50) NULL AFTER status_id,
     ADD COLUMN approved_at DATETIME    NULL AFTER approved_by,
     ADD CONSTRAINT fk_pr_status FOREIGN KEY (status_id) REFERENCES aluria_prod.purchase_status(id),
     ADD INDEX idx_company_status (company_id, status_id)'
);
PREPARE alter_purchase_receive FROM @alter_sql;
EXECUTE alter_purchase_receive;
DEALLOCATE PREPARE alter_purchase_receive;

-- Backfill: every purchase_invoice/purchase_receive row that predates this
-- migration is historical and was already in active use before any approval
-- gate existed — same policy as DEV Step 3 above. VERIFY the resulting row
-- count against prod's real totals before treating this as done (dev
-- backfilled 136/136; prod will differ).
SET @approved_status_id := (SELECT id FROM aluria_prod.purchase_status WHERE status_name = 'Approved' AND deleted_at IS NULL LIMIT 1);

UPDATE aluria_prod.purchase_invoice
SET status_id = @approved_status_id
WHERE deleted_at IS NULL;

UPDATE aluria_prod.purchase_receive
SET status_id = @approved_status_id
WHERE deleted_at IS NULL;

-- Verification — run before considering prod cutover complete:
SELECT ps.status_name, COUNT(*) AS c
FROM aluria_prod.purchase_invoice pi
LEFT JOIN aluria_prod.purchase_status ps ON ps.id = pi.status_id
WHERE pi.deleted_at IS NULL GROUP BY ps.status_name;

SELECT ps.status_name, COUNT(*) AS c
FROM aluria_prod.purchase_receive pr
LEFT JOIN aluria_prod.purchase_status ps ON ps.id = pr.status_id
WHERE pr.deleted_at IS NULL GROUP BY ps.status_name;
```

---

## Post-migration checklist (both environments)

- [ ] Confirm the prod `purchase_invoice`/`purchase_receive` backfill row counts match prod's actual non-deleted totals (dev was 136/136 for both — prod will be different numbers, do not assume they match).
- [ ] Confirm prod's `purchase_status` table already has an `'Approved'` row with that exact `status_name` before running — the backfill `@approved_status_id` lookup silently resolves to `NULL` if the name doesn't match, which would null out every backfilled row's `status_id` instead of erroring loudly. Verify with a `SELECT` first.
- [ ] API code for this feature (`v2/purchase/purchase-invoice/index.php`, `v2/purchase/purchase-receive/index.php`, and the accompanying `purchase-order/index.php` refactor in this same change) must ship to prod in the same release as this schema change — new code against old schema will 500 on every list/detail/approve/reject/revise call for these two modules; old code against new schema is harmless (the new columns are simply unused).
- [ ] No new permission keys were introduced by this migration — approve/reject/revise on these two modules use the same auth gate (`requireAuth()`) as every other purchase endpoint, not a dedicated permission like Finance's dual-approval slots. If a stricter permission gate is wanted later, that's a separate decision, not implied by this migration.
