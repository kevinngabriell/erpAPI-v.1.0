# Aluria Schema Addendum — v21: finance_payment dual approval + shared approve_owner/approve_treasury permissions

**Resolves the v20 open question.** `02_data_migration_dev.md` (v20) added the Owner + Treasury 2-signer columns to `finance_transaction` but scoped them to cash-out only, and left this open:

> OPEN QUESTION for Kage: does `finance_payment` (AR/AP installment settlement, separate from `finance_transaction` journal entries) also need the 2-approver rule, or is this scoped to KAS KELUAR journal entries only?

Answer received: **yes to both**, and also **remove the credit-only restriction** — every `finance_transaction` row (Pembayaran/cash-out *and* Penerimaan/cash-in) requires both signatures, not just cash-out. This addendum:

1. Adds the same 5 approval columns to `finance_payment` (it had none).
2. Adds two new shared permission keys — `keuangan.approve_owner`, `keuangan.approve_treasury` — reused by both `finance_transaction` and `finance_payment` since it's one 2-signer control, not four separate ones.
3. Backfills existing `finance_payment` rows to `posted`/NULL approvers, same "don't guess a real approver identity" policy used throughout v20 for historical rows.

`finance_transaction` itself needs **no schema change** here — it already has all 5 columns from v20. The app-layer restriction to `category_type='credit'` is being dropped in the API code, not the schema, so there is nothing to alter on that table.

---

## DEV — already applied

Run against `aluria_dev` / `movira_core_dev` on **2026-07-20**, by Claude (this session), against the Tailscale dev host (`100.98.160.119`). Verified with a full approve/reject smoke test (owner signs → `partially_approved`, second user signs treasury → `posted`, same-user-twice blocked with `409`, reject flow, already-final blocked) — all temp test rows and temp permission grants used for that test were cleaned up afterward; nothing test-related was left in the DB.

```sql
-- ============================================================================
-- v21 DEV — finance_payment dual-approval columns + shared approve_owner /
-- approve_treasury permission keys
-- ============================================================================

USE `aluria_dev`;

-- ----------------------------------------------------------------------------
-- Step 1: finance_payment dual-approval columns (idempotent, same
-- information_schema-gated pattern as v20's Step 4.5 — not "ADD COLUMN IF
-- NOT EXISTS", that syntax needs MySQL 8.0.29+ and errors on older servers).
-- ----------------------------------------------------------------------------
SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_dev' AND table_name = 'finance_payment'
    AND column_name = 'transaction_status'
);
SET @alter_sql := IF(@schema_check > 0,
  'SELECT ''finance_payment dual-approval columns already exist, skipping'' AS note',
  'ALTER TABLE aluria_dev.finance_payment
     ADD COLUMN transaction_status ENUM(''draft'',''submitted'',''partially_approved'',''posted'',''rejected'')
       NOT NULL DEFAULT ''draft'' AFTER discount_amount,
     ADD COLUMN approved_by_owner_id    VARCHAR(50) NULL AFTER transaction_status,
     ADD COLUMN approved_by_owner_at    DATETIME    NULL AFTER approved_by_owner_id,
     ADD COLUMN approved_by_treasury_id VARCHAR(50) NULL AFTER approved_by_owner_at,
     ADD COLUMN approved_by_treasury_at DATETIME    NULL AFTER approved_by_treasury_id'
);
PREPARE alter_finance_payment FROM @alter_sql;
EXECUTE alter_finance_payment;
DEALLOCATE PREPARE alter_finance_payment;

-- ----------------------------------------------------------------------------
-- Step 2: backfill existing rows. Every finance_payment row that existed
-- before this addendum predates the approval workflow entirely — same
-- "don't guess a real approver identity" policy as every other historical
-- backfill in v20 (sales_order, purchase_order, sales_sppb, finance_transaction).
-- Approver columns stay NULL; the 2-approval gate only binds new payments
-- going forward through the app-layer workflow.
-- ----------------------------------------------------------------------------
UPDATE aluria_dev.finance_payment
SET transaction_status = 'posted'
WHERE deleted_at IS NULL;
-- DEV RESULT: 582 rows backfilled.

-- ----------------------------------------------------------------------------
-- Step 3: shared permission keys. Idempotent on permission_key + app_id.
-- app_id resolved dynamically from an existing Keuangan permission row
-- rather than hardcoded, so this block is portable across environments.
-- ----------------------------------------------------------------------------
SET @app_id := (SELECT app_id FROM movira_core_dev.app_permission WHERE permission_key = 'keuangan.ledger.view' LIMIT 1);

INSERT INTO movira_core_dev.app_permission (permission_id, app_id, permission_key, module, label, description)
SELECT CONCAT('perm', LOWER(HEX(RANDOM_BYTES(8)))), @app_id, 'keuangan.approve_owner', 'Keuangan',
       'Approve as Business Owner',
       'Signs the Business Owner slot on the 2-approver finance workflow (Pembayaran, Penerimaan, A/P, A/R)'
WHERE NOT EXISTS (SELECT 1 FROM movira_core_dev.app_permission WHERE permission_key = 'keuangan.approve_owner' AND app_id = @app_id);

INSERT INTO movira_core_dev.app_permission (permission_id, app_id, permission_key, module, label, description)
SELECT CONCAT('perm', LOWER(HEX(RANDOM_BYTES(8)))), @app_id, 'keuangan.approve_treasury', 'Keuangan',
       'Approve as Treasury/Controller',
       'Signs the Treasury/Controller slot on the 2-approver finance workflow (Pembayaran, Penerimaan, A/P, A/R)'
WHERE NOT EXISTS (SELECT 1 FROM movira_core_dev.app_permission WHERE permission_key = 'keuangan.approve_treasury' AND app_id = @app_id);
-- DEV RESULT: both inserted — permission_id perm5c3dc4c0fc51be0c (approve_owner),
-- perm7307f7610e126c34 (approve_treasury). (This run used app_id
-- appe16592dc7b88eab9, looked up the same way, before RANDOM_BYTES()-based
-- IDs were written up for this doc — functionally identical, just generated
-- inline in a PHP script rather than pure SQL. Re-running this exact SQL
-- block against dev now is a safe no-op thanks to the NOT EXISTS guards.)

-- ----------------------------------------------------------------------------
-- Step 4: verification
-- ----------------------------------------------------------------------------
SELECT transaction_status, COUNT(*) AS c FROM aluria_dev.finance_payment GROUP BY transaction_status;
-- Expect: 100% 'posted' immediately after this migration (no 'draft' rows exist
-- yet since nothing has gone through the new create-flow at migration time).

SELECT permission_key, module, label FROM movira_core_dev.app_permission
WHERE permission_key IN ('keuangan.approve_owner', 'keuangan.approve_treasury');
-- Expect: exactly 2 rows.
```

---

## PROD — NOT yet applied

**Do not run this against production without Kage's sign-off first** — same review gate as every other schema change in this project. This mirrors the DEV block above exactly (idempotent, safe to re-run), scoped to prod schema names.

```sql
-- ============================================================================
-- v21 PROD — finance_payment dual-approval columns + shared approve_owner /
-- approve_treasury permission keys
--
-- Prerequisite: v20 (finance_transaction dual-approval columns) must already
-- be live on prod. If aluria_prod.finance_transaction doesn't yet have
-- transaction_status, run 02_data_migration_prod.md's Step 4.5 block first —
-- this addendum assumes that column already exists.
-- ============================================================================

-- Replace aluria_prod / movira_core_prod below with the real prod schema
-- names from prod's .env (CORE_SCHEMA / APP_SCHEMA) before running.

USE `aluria_prod`;

SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_prod' AND table_name = 'finance_payment'
    AND column_name = 'transaction_status'
);
SET @alter_sql := IF(@schema_check > 0,
  'SELECT ''finance_payment dual-approval columns already exist, skipping'' AS note',
  'ALTER TABLE aluria_prod.finance_payment
     ADD COLUMN transaction_status ENUM(''draft'',''submitted'',''partially_approved'',''posted'',''rejected'')
       NOT NULL DEFAULT ''draft'' AFTER discount_amount,
     ADD COLUMN approved_by_owner_id    VARCHAR(50) NULL AFTER transaction_status,
     ADD COLUMN approved_by_owner_at    DATETIME    NULL AFTER approved_by_owner_id,
     ADD COLUMN approved_by_treasury_id VARCHAR(50) NULL AFTER approved_by_owner_at,
     ADD COLUMN approved_by_treasury_at DATETIME    NULL AFTER approved_by_treasury_id'
);
PREPARE alter_finance_payment FROM @alter_sql;
EXECUTE alter_finance_payment;
DEALLOCATE PREPARE alter_finance_payment;

-- Backfill: every finance_payment row that predates this migration is
-- historical and already-settled — same policy as DEV Step 2 above.
-- VERIFY the resulting row count against prod's real finance_payment total
-- before treating this as done (dev backfilled 582; prod will differ).
UPDATE aluria_prod.finance_payment
SET transaction_status = 'posted'
WHERE deleted_at IS NULL;

SET @app_id := (SELECT app_id FROM movira_core_prod.app_permission WHERE permission_key = 'keuangan.ledger.view' LIMIT 1);

INSERT INTO movira_core_prod.app_permission (permission_id, app_id, permission_key, module, label, description)
SELECT CONCAT('perm', LOWER(HEX(RANDOM_BYTES(8)))), @app_id, 'keuangan.approve_owner', 'Keuangan',
       'Approve as Business Owner',
       'Signs the Business Owner slot on the 2-approver finance workflow (Pembayaran, Penerimaan, A/P, A/R)'
WHERE NOT EXISTS (SELECT 1 FROM movira_core_prod.app_permission WHERE permission_key = 'keuangan.approve_owner' AND app_id = @app_id);

INSERT INTO movira_core_prod.app_permission (permission_id, app_id, permission_key, module, label, description)
SELECT CONCAT('perm', LOWER(HEX(RANDOM_BYTES(8)))), @app_id, 'keuangan.approve_treasury', 'Keuangan',
       'Approve as Treasury/Controller',
       'Signs the Treasury/Controller slot on the 2-approver finance workflow (Pembayaran, Penerimaan, A/P, A/R)'
WHERE NOT EXISTS (SELECT 1 FROM movira_core_prod.app_permission WHERE permission_key = 'keuangan.approve_treasury' AND app_id = @app_id);

-- Verification — run before considering prod cutover complete:
SELECT transaction_status, COUNT(*) AS c FROM aluria_prod.finance_payment GROUP BY transaction_status;
SELECT permission_key, module, label FROM movira_core_prod.app_permission
WHERE permission_key IN ('keuangan.approve_owner', 'keuangan.approve_treasury');
```

---

## Post-migration checklist (both environments)

- [ ] **No role holds `keuangan.approve_owner` or `keuangan.approve_treasury` yet, in dev or prod.** These endpoints are permanently unusable until an admin assigns them via the roles/permissions screen. Do NOT auto-assign these as part of a migration script — same "don't guess a business decision" policy as v20's Treasury-role gap. Confirmed candidates by name only: `keuangan.approve_owner` → whichever role represents "Business Owner" is the obvious mapping; `keuangan.approve_treasury` has no equally obvious existing role (no "Treasury" or "Controller" role exists yet in `movira_core` in either dev or prod as of this writing) — create one or repurpose an existing Finance-adjacent role.
- [ ] Confirm the prod `finance_payment` backfill row count matches prod's actual total non-deleted row count (dev was 582 — prod will be a different number, do not assume it matches).
- [ ] Confirm prod already has v20's `finance_transaction` columns before running this addendum (see prerequisite note in the PROD block).
- [ ] API code for this feature (`v2/finance/finance-transaction/index.php`, `v2/finance/finance-payment/index.php`, `v2/helpers/permission.php`, `v2/helpers/dual_approval.php`) must be deployed to prod in the same release as this schema change — the new columns are inert without it, but the reverse (code deployed, schema missing) will 500 on every list/detail/approve call for these two modules.
