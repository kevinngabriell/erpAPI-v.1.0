# Aluria Schema Addendum — v32: account_code classification fix + equity account seed

**No schema changes.** Pure data fix in `account_code`, scoped to a single company (`cmp0fcd1b1c010aa508`) — found while investigating why `GET /api/v2/reports/balance-sheet` (`v2/reports/balance-sheet/index.php`) showed `Total Liabilities = Rp 0` and, separately, `Total Equity = Rp 0` regardless of `as_of_date`. The report itself (`fetchBalanceSheetAccounts()`) is correct — it groups strictly by whatever `account_type` is stored on each `account_code` row. The zeros were bad master data, not a code bug.

## What was found

1. **`205-006-000 HUTANG USAHA`** (Accounts Payable) was typed `equity` instead of `liability`. **Already corrected before this migration** — confirmed via direct query, its `account_type` is `liability` as of this session; no action needed here.
2. **`105-014-000 POS SEMENTARA`** was typed `liability`, but every other account in the `105-xxx` block (PIUTANG KARYAWAN, PPN MASUKAN, PPH PASAL 21/22/23/25/29, sewa/asuransi dibayar dimuka, AYAT SILANG KAS & BANK) is `asset`. All 148 `finance_transaction_detail` rows posted against it are seeded test data (`amount = 0.00`, "Test memo"/"Test Payee"), so there was no real usage to confirm intent from — reclassified to `asset` for consistency with its sibling group, per explicit sign-off from the company owner (kevin florentino, `usr_6a4a366caf628`) in this session.
3. **Zero `equity` accounts existed** for this company — `Total Modal` was structurally always `Rp 0`, not a bug per as_of_date. Seeded a standard Indonesian COA equity set at `301-xxx` (the next unused leading digit — `1xx` = asset, `2xx` = liability, `4xx` = revenue, `5xx` = expense were already in use).

## DEV — already applied

Run against `aluria_dev` on **2026-07-26**, via direct `mysqli` through `v2/connection/db.php` (no `mysql` CLI in this environment, same as `v31`). Scoped to `company_id = 'cmp0fcd1b1c010aa508'` only — does not touch any other company's chart of accounts. `created_by`/`updated_by` set to `usr_6a4a366caf628` (kevin florentino, the company's Business Owner who requested and approved this change), not a system placeholder.

```sql
-- ============================================================================
-- v32 DEV — account_code classification fix (POS SEMENTARA) + equity seed
-- Idempotent: the UPDATE is a no-op if already 'asset'; the INSERTs use
-- ON DUPLICATE KEY UPDATE against uq_account_code (company_id, account_code).
-- ============================================================================

USE `aluria_dev`;

-- 1. POS SEMENTARA: liability -> asset (matches its 105-xxx sibling accounts)
UPDATE aluria_dev.account_code
SET account_type = 'asset', updated_by = 'usr_6a4a366caf628', updated_at = NOW()
WHERE company_id = 'cmp0fcd1b1c010aa508' AND account_code = '105-014-000' AND deleted_at IS NULL;

-- 2. Standard equity accounts (none existed for this company before this migration)
INSERT INTO aluria_dev.account_code
    (id, company_id, account_code, account_code_name, account_type, is_active, created_by, created_at)
VALUES
    ('5dcbb6ac-8b79-467d-bd91-8fee83cc4740', 'cmp0fcd1b1c010aa508', '301-001-000', 'MODAL DISETOR',      'equity', 1, 'usr_6a4a366caf628', NOW()),
    ('8df769d6-9fd7-4446-b1a1-ee9ce66ae904', 'cmp0fcd1b1c010aa508', '301-002-000', 'LABA DITAHAN',        'equity', 1, 'usr_6a4a366caf628', NOW()),
    ('02a4d87a-eb48-4db3-91e8-ea0919f57c63', 'cmp0fcd1b1c010aa508', '301-003-000', 'LABA/RUGI BERJALAN',  'equity', 1, 'usr_6a4a366caf628', NOW()),
    ('e7dcf38e-85f0-4c9b-8379-686952d8edcf', 'cmp0fcd1b1c010aa508', '301-004-000', 'PRIVE',               'equity', 1, 'usr_6a4a366caf628', NOW())
ON DUPLICATE KEY UPDATE account_code_name = VALUES(account_code_name), account_type = VALUES(account_type);

-- Verification
SELECT account_code, account_code_name, account_type, is_active
FROM aluria_dev.account_code
WHERE company_id = 'cmp0fcd1b1c010aa508' AND (account_code = '105-014-000' OR account_type = 'equity')
ORDER BY account_code;

SELECT account_type, COUNT(*) AS c
FROM aluria_dev.account_code
WHERE company_id = 'cmp0fcd1b1c010aa508' AND deleted_at IS NULL
GROUP BY account_type;
```

**DEV RESULT:** ran 2026-07-26. `UPDATE` affected 1 row (`105-014-000` now `asset`). All 4 `INSERT`s affected 1 row each (no pre-existing `301-xxx` codes, so plain inserts — the `ON DUPLICATE KEY UPDATE` branch was not exercised, but is there for re-run safety). Post-migration type counts for this company: `asset => 21, liability => 6, equity => 4, revenue => 1, expense => 45` (previously `asset => 20, liability => 7, equity => 0`).

---

## PROD — NOT yet applied

**Do not run this against production without Kage's sign-off first** — same review gate as `v21`/`v22`/`v23`/`v29`/`v31`. Before running, re-derive the `company_id` for the equivalent prod company (do not assume `cmp0fcd1b1c010aa508` is the same in prod) and re-check whether prod's `105-014-000`/equity situation actually matches DEV's — this migration was written against one specific company's data, not inferred from schema/code, so it is not safe to run blind against a different company_id.

```sql
-- Replace aluria_prod and the company_id/UUIDs below with real prod values
-- before running. Generate fresh UUIDs for the INSERT rows (v4 format,
-- matching generateUUID() in v2/general.php) — do not reuse the DEV UUIDs
-- above, since PROD's account_code.id space is independent of DEV's.

USE `aluria_prod`;

UPDATE aluria_prod.account_code
SET account_type = 'asset', updated_by = '<prod_owner_user_id>', updated_at = NOW()
WHERE company_id = '<prod_company_id>' AND account_code = '105-014-000' AND deleted_at IS NULL;

INSERT INTO aluria_prod.account_code
    (id, company_id, account_code, account_code_name, account_type, is_active, created_by, created_at)
VALUES
    ('<uuid1>', '<prod_company_id>', '301-001-000', 'MODAL DISETOR',      'equity', 1, '<prod_owner_user_id>', NOW()),
    ('<uuid2>', '<prod_company_id>', '301-002-000', 'LABA DITAHAN',        'equity', 1, '<prod_owner_user_id>', NOW()),
    ('<uuid3>', '<prod_company_id>', '301-003-000', 'LABA/RUGI BERJALAN',  'equity', 1, '<prod_owner_user_id>', NOW()),
    ('<uuid4>', '<prod_company_id>', '301-004-000', 'PRIVE',               'equity', 1, '<prod_owner_user_id>', NOW())
ON DUPLICATE KEY UPDATE account_code_name = VALUES(account_code_name), account_type = VALUES(account_type);

-- Verification
SELECT account_code, account_code_name, account_type, is_active
FROM aluria_prod.account_code
WHERE company_id = '<prod_company_id>' AND (account_code = '105-014-000' OR account_type = 'equity')
ORDER BY account_code;
```

---

## Post-migration checklist

- [ ] `GET /api/v2/reports/balance-sheet` for `cmp0fcd1b1c010aa508` now returns non-zero `liability.total` (POS SEMENTARA no longer silently miscounted as liability — though its balance was `0.00` from test data anyway, so this alone didn't move `Total Liabilities`) and an `equity` block with 4 accounts, all `total = 0` until real transactions post against them.
- [ ] **New equity accounts have `Rp 0` balances and no opening-balance entries.** `Total Modal` will still show `Rp 0` until either (a) transactions are posted against `301-xxx` going forward, or (b) an opening-balance entry is created via the "Set Saldo Awal" flow on the Neraca screen. This migration only makes equity *representable* — it does not seed a balancing entry against the existing -Rp 9.56B asset figure, since inventing that number would be fabricating financial data no one confirmed.
- [ ] Balance sheet will **not balance** (Assets = Liabilities + Equity) immediately after this migration — the pre-existing -Rp 9.562.307.697,30 asset total has no corresponding equity/liability entry recorded anywhere. That gap predates this migration (it's a real bookkeeping gap in this company's transaction history, not something this migration was scoped to fix) and needs an opening-balance or correcting entry from the business side.
- [ ] Get Kage's sign-off, then adapt and run the PROD block above — including confirming whether prod's chart of accounts has the same POS SEMENTARA/equity gaps before assuming this migration even applies there.
- [ ] No permission keys introduced by this migration.
