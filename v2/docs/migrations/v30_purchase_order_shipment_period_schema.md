# Aluria Schema Addendum — v30: purchase_order shipment_period

**Replaces `purchase_order.shipment_date` (a free date) with `purchase_order.shipment_period_id`, a reference to a new global `shipment_period` lookup table of 36 fixed "Early/Mid/End \<Month\>" values.** This matches the legacy pre-Aluria system, where the PO form's "SHIPMENT" field is a coarse ETA period the supplier commits to at order time (e.g. "Early July"), not an exact date — exact dates are already covered separately by `etd_date`/`eta_date` (Estimated Time of Departure/Arrival), which get filled in later as the shipment actually progresses. `shipment_method` (the Incoterm enum — FOB/CIF/etc.) is unrelated and untouched by this migration.

Permission keys `settings.shipment_period.view|create|update|delete` already exist in `movira_core_dev.app_permission` — seeded ahead of time by v24 (`v2/docs/migrations/v24_permission_catalog_seed.md`) but never wired to an actual table/module until now. No new permission keys are needed for this migration.

`shipment_period` is a **global** lookup table (no `company_id`), mirroring `ship_via` and `payment_term` — the 36 periods are calendar-fixed and shared by every company, confirmed with the user before implementing.

**Data impact:** `aluria_dev.purchase_order` has 162 rows total, 74 with `shipment_date` populated, at the time of this migration. The user explicitly chose to replace (not add alongside) `shipment_date`, accepting the precision loss. Step 3 below best-effort maps each existing `shipment_date` to the enclosing period (day 1–10 → Early, 11–20 → Mid, 21–31 → End, same month) before the column is dropped in Step 4 — this is a deterministic calendar mapping, not a guess at business meaning, so it does not fall under the "never auto-backfill with a guess" rule.

---

## DEV — already applied

Run against `aluria_dev` on **2026-07-25**, by Claude (this session), against the Tailscale dev host.

```sql
-- ============================================================================
-- v30 DEV — shipment_period table + purchase_order.shipment_period_id
-- ============================================================================

USE `aluria_dev`;

-- ----------------------------------------------------------------------------
-- Step 1: create shipment_period (idempotent — CREATE TABLE IF NOT EXISTS is
-- safe here, unlike ALTER TABLE ADD COLUMN, since it doesn't error on re-run).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS aluria_dev.shipment_period (
  id          VARCHAR(50)  NOT NULL,
  period_name VARCHAR(50)  NOT NULL COMMENT 'e.g. "Early January"',
  sort_order  TINYINT UNSIGNED NOT NULL COMMENT '1-36, chronological (Jan Early=1 .. Dec End=36)',
  created_by  VARCHAR(50)  NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by  VARCHAR(50)  DEFAULT NULL,
  updated_at  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at  DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_shipment_period_name (period_name),
  KEY idx_sort_order (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- Step 2: seed the 36 fixed rows (guarded — safe to re-run).
-- ----------------------------------------------------------------------------
INSERT INTO aluria_dev.shipment_period (id, period_name, sort_order, created_by, created_at)
SELECT * FROM (
  SELECT 'shp_early_jan' id, 'Early January' period_name, 1 sort_order, 'system' created_by, NOW() created_at UNION ALL
  SELECT 'shp_mid_jan',   'Mid January',    2, 'system', NOW() UNION ALL
  SELECT 'shp_end_jan',   'End January',    3, 'system', NOW() UNION ALL
  SELECT 'shp_early_feb', 'Early February', 4, 'system', NOW() UNION ALL
  SELECT 'shp_mid_feb',   'Mid February',   5, 'system', NOW() UNION ALL
  SELECT 'shp_end_feb',   'End February',   6, 'system', NOW() UNION ALL
  SELECT 'shp_early_mar', 'Early March',    7, 'system', NOW() UNION ALL
  SELECT 'shp_mid_mar',   'Mid March',      8, 'system', NOW() UNION ALL
  SELECT 'shp_end_mar',   'End March',      9, 'system', NOW() UNION ALL
  SELECT 'shp_early_apr', 'Early April',   10, 'system', NOW() UNION ALL
  SELECT 'shp_mid_apr',   'Mid April',     11, 'system', NOW() UNION ALL
  SELECT 'shp_end_apr',   'End April',     12, 'system', NOW() UNION ALL
  SELECT 'shp_early_may', 'Early May',     13, 'system', NOW() UNION ALL
  SELECT 'shp_mid_may',   'Mid May',       14, 'system', NOW() UNION ALL
  SELECT 'shp_end_may',   'End May',       15, 'system', NOW() UNION ALL
  SELECT 'shp_early_jun', 'Early June',    16, 'system', NOW() UNION ALL
  SELECT 'shp_mid_jun',   'Mid June',      17, 'system', NOW() UNION ALL
  SELECT 'shp_end_jun',   'End June',      18, 'system', NOW() UNION ALL
  SELECT 'shp_early_jul', 'Early July',    19, 'system', NOW() UNION ALL
  SELECT 'shp_mid_jul',   'Mid July',      20, 'system', NOW() UNION ALL
  SELECT 'shp_end_jul',   'End July',      21, 'system', NOW() UNION ALL
  SELECT 'shp_early_aug', 'Early August',  22, 'system', NOW() UNION ALL
  SELECT 'shp_mid_aug',   'Mid August',    23, 'system', NOW() UNION ALL
  SELECT 'shp_end_aug',   'End August',    24, 'system', NOW() UNION ALL
  SELECT 'shp_early_sep', 'Early September', 25, 'system', NOW() UNION ALL
  SELECT 'shp_mid_sep',   'Mid September',   26, 'system', NOW() UNION ALL
  SELECT 'shp_end_sep',   'End September',   27, 'system', NOW() UNION ALL
  SELECT 'shp_early_oct', 'Early October', 28, 'system', NOW() UNION ALL
  SELECT 'shp_mid_oct',   'Mid October',   29, 'system', NOW() UNION ALL
  SELECT 'shp_end_oct',   'End October',   30, 'system', NOW() UNION ALL
  SELECT 'shp_early_nov', 'Early November', 31, 'system', NOW() UNION ALL
  SELECT 'shp_mid_nov',   'Mid November',   32, 'system', NOW() UNION ALL
  SELECT 'shp_end_nov',   'End November',   33, 'system', NOW() UNION ALL
  SELECT 'shp_early_dec', 'Early December', 34, 'system', NOW() UNION ALL
  SELECT 'shp_mid_dec',   'Mid December',   35, 'system', NOW() UNION ALL
  SELECT 'shp_end_dec',   'End December',   36, 'system', NOW()
) AS seed
WHERE NOT EXISTS (
  SELECT 1 FROM aluria_dev.shipment_period sp WHERE sp.id = seed.id
);

-- ----------------------------------------------------------------------------
-- Step 3: add purchase_order.shipment_period_id + backfill from shipment_date
-- BEFORE dropping shipment_date (idempotent, information_schema guard).
-- ----------------------------------------------------------------------------
SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_dev' AND table_name = 'purchase_order'
    AND column_name = 'shipment_period_id'
);
SET @alter_sql := IF(@schema_check > 0,
  'SELECT ''purchase_order.shipment_period_id already exists, skipping'' AS note',
  'ALTER TABLE aluria_dev.purchase_order
     ADD COLUMN shipment_period_id VARCHAR(50) NULL AFTER shipment_date,
     ADD CONSTRAINT fk_po_shipment_period FOREIGN KEY (shipment_period_id) REFERENCES aluria_dev.shipment_period (id)'
);
PREPARE alter_po_shipment_period FROM @alter_sql;
EXECUTE alter_po_shipment_period;
DEALLOCATE PREPARE alter_po_shipment_period;

-- Backfill: day 1-10 -> Early, 11-20 -> Mid, 21-31 -> End, same month as shipment_date.
-- Only touches rows where shipment_period_id is still NULL, so safe to re-run.
UPDATE aluria_dev.purchase_order po
JOIN aluria_dev.shipment_period sp
  ON sp.sort_order = (MONTH(po.shipment_date) - 1) * 3
     + (CASE WHEN DAY(po.shipment_date) <= 10 THEN 1
             WHEN DAY(po.shipment_date) <= 20 THEN 2
             ELSE 3 END)
SET po.shipment_period_id = sp.id
WHERE po.shipment_date IS NOT NULL AND po.shipment_period_id IS NULL;

-- ----------------------------------------------------------------------------
-- Step 4: drop shipment_date now that every non-NULL value has been mapped
-- (idempotent — only drops if the column still exists).
-- ----------------------------------------------------------------------------
SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_dev' AND table_name = 'purchase_order'
    AND column_name = 'shipment_date'
);
SET @alter_sql := IF(@schema_check = 0,
  'SELECT ''purchase_order.shipment_date already dropped, skipping'' AS note',
  'ALTER TABLE aluria_dev.purchase_order DROP COLUMN shipment_date'
);
PREPARE drop_po_shipment_date FROM @alter_sql;
EXECUTE drop_po_shipment_date;
DEALLOCATE PREPARE drop_po_shipment_date;

-- ----------------------------------------------------------------------------
-- Step 5: verification
-- ----------------------------------------------------------------------------
SELECT COUNT(*) AS total_rows, COUNT(shipment_period_id) AS with_period
FROM aluria_dev.purchase_order;
-- Expect: with_period = 74 (matches the pre-migration shipment_date count)

SELECT * FROM aluria_dev.shipment_period ORDER BY sort_order;
-- Expect: 36 rows, Early January .. End December

SHOW COLUMNS FROM aluria_dev.purchase_order WHERE Field IN ('shipment_date', 'shipment_period_id');
-- Expect: shipment_date absent, shipment_period_id present (varchar(50), nullable)
```

**Actual dev result (verified this session):** see smoke test output in the session log — 36 `shipment_period` rows inserted, 74/162 `purchase_order` rows backfilled to `shipment_period_id`, `shipment_date` column dropped, `fk_po_shipment_period` constraint in place.

---

## PROD — NOT yet applied

**Do not run this against production without sign-off first.** Mirrors the DEV block exactly, scoped to prod schema names.

```sql
-- ============================================================================
-- v30 PROD — shipment_period table + purchase_order.shipment_period_id
-- ============================================================================

-- Replace aluria_prod below with the real prod schema name from prod's .env
-- (APP_SCHEMA) before running.

USE `aluria_prod`;

CREATE TABLE IF NOT EXISTS aluria_prod.shipment_period (
  id          VARCHAR(50)  NOT NULL,
  period_name VARCHAR(50)  NOT NULL COMMENT 'e.g. "Early January"',
  sort_order  TINYINT UNSIGNED NOT NULL COMMENT '1-36, chronological (Jan Early=1 .. Dec End=36)',
  created_by  VARCHAR(50)  NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by  VARCHAR(50)  DEFAULT NULL,
  updated_at  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at  DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_shipment_period_name (period_name),
  KEY idx_sort_order (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO aluria_prod.shipment_period (id, period_name, sort_order, created_by, created_at)
SELECT * FROM (
  SELECT 'shp_early_jan' id, 'Early January' period_name, 1 sort_order, 'system' created_by, NOW() created_at UNION ALL
  SELECT 'shp_mid_jan',   'Mid January',    2, 'system', NOW() UNION ALL
  SELECT 'shp_end_jan',   'End January',    3, 'system', NOW() UNION ALL
  SELECT 'shp_early_feb', 'Early February', 4, 'system', NOW() UNION ALL
  SELECT 'shp_mid_feb',   'Mid February',   5, 'system', NOW() UNION ALL
  SELECT 'shp_end_feb',   'End February',   6, 'system', NOW() UNION ALL
  SELECT 'shp_early_mar', 'Early March',    7, 'system', NOW() UNION ALL
  SELECT 'shp_mid_mar',   'Mid March',      8, 'system', NOW() UNION ALL
  SELECT 'shp_end_mar',   'End March',      9, 'system', NOW() UNION ALL
  SELECT 'shp_early_apr', 'Early April',   10, 'system', NOW() UNION ALL
  SELECT 'shp_mid_apr',   'Mid April',     11, 'system', NOW() UNION ALL
  SELECT 'shp_end_apr',   'End April',     12, 'system', NOW() UNION ALL
  SELECT 'shp_early_may', 'Early May',     13, 'system', NOW() UNION ALL
  SELECT 'shp_mid_may',   'Mid May',       14, 'system', NOW() UNION ALL
  SELECT 'shp_end_may',   'End May',       15, 'system', NOW() UNION ALL
  SELECT 'shp_early_jun', 'Early June',    16, 'system', NOW() UNION ALL
  SELECT 'shp_mid_jun',   'Mid June',      17, 'system', NOW() UNION ALL
  SELECT 'shp_end_jun',   'End June',      18, 'system', NOW() UNION ALL
  SELECT 'shp_early_jul', 'Early July',    19, 'system', NOW() UNION ALL
  SELECT 'shp_mid_jul',   'Mid July',      20, 'system', NOW() UNION ALL
  SELECT 'shp_end_jul',   'End July',      21, 'system', NOW() UNION ALL
  SELECT 'shp_early_aug', 'Early August',  22, 'system', NOW() UNION ALL
  SELECT 'shp_mid_aug',   'Mid August',    23, 'system', NOW() UNION ALL
  SELECT 'shp_end_aug',   'End August',    24, 'system', NOW() UNION ALL
  SELECT 'shp_early_sep', 'Early September', 25, 'system', NOW() UNION ALL
  SELECT 'shp_mid_sep',   'Mid September',   26, 'system', NOW() UNION ALL
  SELECT 'shp_end_sep',   'End September',   27, 'system', NOW() UNION ALL
  SELECT 'shp_early_oct', 'Early October', 28, 'system', NOW() UNION ALL
  SELECT 'shp_mid_oct',   'Mid October',   29, 'system', NOW() UNION ALL
  SELECT 'shp_end_oct',   'End October',   30, 'system', NOW() UNION ALL
  SELECT 'shp_early_nov', 'Early November', 31, 'system', NOW() UNION ALL
  SELECT 'shp_mid_nov',   'Mid November',   32, 'system', NOW() UNION ALL
  SELECT 'shp_end_nov',   'End November',   33, 'system', NOW() UNION ALL
  SELECT 'shp_early_dec', 'Early December', 34, 'system', NOW() UNION ALL
  SELECT 'shp_mid_dec',   'Mid December',   35, 'system', NOW() UNION ALL
  SELECT 'shp_end_dec',   'End December',   36, 'system', NOW()
) AS seed
WHERE NOT EXISTS (
  SELECT 1 FROM aluria_prod.shipment_period sp WHERE sp.id = seed.id
);

SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_prod' AND table_name = 'purchase_order'
    AND column_name = 'shipment_period_id'
);
SET @alter_sql := IF(@schema_check > 0,
  'SELECT ''purchase_order.shipment_period_id already exists, skipping'' AS note',
  'ALTER TABLE aluria_prod.purchase_order
     ADD COLUMN shipment_period_id VARCHAR(50) NULL AFTER shipment_date,
     ADD CONSTRAINT fk_po_shipment_period FOREIGN KEY (shipment_period_id) REFERENCES aluria_prod.shipment_period (id)'
);
PREPARE alter_po_shipment_period FROM @alter_sql;
EXECUTE alter_po_shipment_period;
DEALLOCATE PREPARE alter_po_shipment_period;

UPDATE aluria_prod.purchase_order po
JOIN aluria_prod.shipment_period sp
  ON sp.sort_order = (MONTH(po.shipment_date) - 1) * 3
     + (CASE WHEN DAY(po.shipment_date) <= 10 THEN 1
             WHEN DAY(po.shipment_date) <= 20 THEN 2
             ELSE 3 END)
SET po.shipment_period_id = sp.id
WHERE po.shipment_date IS NOT NULL AND po.shipment_period_id IS NULL;

SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_prod' AND table_name = 'purchase_order'
    AND column_name = 'shipment_date'
);
SET @alter_sql := IF(@schema_check = 0,
  'SELECT ''purchase_order.shipment_date already dropped, skipping'' AS note',
  'ALTER TABLE aluria_prod.purchase_order DROP COLUMN shipment_date'
);
PREPARE drop_po_shipment_date FROM @alter_sql;
EXECUTE drop_po_shipment_date;
DEALLOCATE PREPARE drop_po_shipment_date;

-- Verification — run before considering prod cutover complete:
SELECT COUNT(*) AS total_rows, COUNT(shipment_period_id) AS with_period FROM aluria_prod.purchase_order;
SELECT * FROM aluria_prod.shipment_period ORDER BY sort_order;
SHOW COLUMNS FROM aluria_prod.purchase_order WHERE Field IN ('shipment_date', 'shipment_period_id');
```

---

## Post-migration checklist (both environments)

- [ ] **Ship the code change (`v2/purchase/purchase-order/index.php`, new `v2/master/shipment-period/index.php`, `v2/index.php` router entry) in the same release as this migration.** Direction of safety: old code (still reading/writing `shipment_date`) will `500` against the new prod schema the moment `shipment_date` is dropped (Step 4) — every `purchase-order` create/update touching that field breaks. New code (reading/writing `shipment_period_id`) will `500` against the old prod schema (column doesn't exist yet). **There is no safe order to split these across releases** — schema and code must land together, or take prod through a maintenance window.
- [ ] **Re-verify prod's actual `shipment_date` distribution before assuming dev's day-of-month backfill mapping is acceptable** — the Early/Mid/End split is a fixed calendar rule so it applies identically everywhere, but confirm prod's row count and spot-check a few mapped values after running Step 3, same as this doc's dev verification.
- [ ] **No new permission keys needed** — `settings.shipment_period.view|create|update|delete` were already seeded by v24. No role currently has them assigned (unchanged by this migration); assigning them to a role is a business decision for the user, not inferred here. Also note: as with every other master module in this codebase (`ship-via`, `payment-term`, etc.), the new `shipment-period` module only enforces `requireAuth()` — it does not check these permission keys in PHP. Enforcement of `settings.shipment_period.*` (if desired) happens wherever permission gating already happens for the rest of `master/` today (confirm with the user/frontend team where that is, since it isn't in this PHP layer).
- [ ] The 36 `shipment_period` rows are seeded with fixed, predictable IDs (`shp_early_jan` … `shp_end_dec`) rather than `uniqid()`, specifically so this same INSERT is trivially portable and idempotent across dev/prod/any future environment without needing to look up generated IDs first. This is a deliberate one-off exception to the `'prefix_' . uniqid()` ID convention, appropriate here because these are fixed reference/lookup rows, not user-created records.
