# Aluria Schema Addendum — v28: purchase_type number_format columns

**Adds configurable auto-numbering to `purchase_order`.** `sales_order` already has a `GET .../generate-number` endpoint (`v2/sales/sales-order/index.php`) with a single hardcoded format. `purchase_order` had no equivalent — `po_display_number` was always typed in manually. Live data on dev shows the business already uses **two different formats depending on `purchase_type`**:

- `Import` (`purchase_type.id = 9ac6581b-842c-11f1-93b5-525400d7fdd0`): `VIK/26/VII/0030` → `{company_code}/{yy}/{month}/{seq}`, 4-digit sequence
- `Local` (`purchase_type.id = 9ac65bc3-842c-11f1-93b5-525400d7fdd0`): `VIK/L/VI/2026/015` → `{company_code}/L/{month}/{yyyy}/{seq}`, 3-digit sequence

(`{month}` = roman-numeral month, e.g. `VII`.)

Rather than hardcoding an `if (type_name === 'Import')` branch in PHP (which breaks the moment a third purchase type is added), the format is stored as data on `purchase_type` itself — a `number_format` template string (tokens: `{company_code}`, `{yy}`, `{yyyy}`, `{month}`, `{seq}`) plus a `sequence_digits` column controlling zero-padding. This mirrors the existing pattern of `purchase_type` being an admin-editable master table (`v2/master/purchase-type/index.php`) — new types get their own format by setting the field via that same API, no code change needed. Confirmed with the user this approach is correct before implementing.

`purchase_type` is a **global** lookup table (no `company_id` column — confirmed via `DESCRIBE`), shared by every company in the app. The two existing rows (`Local`, `Import`) are backfilled with their real historic formats so newly generated numbers stay consistent with existing manually-entered ones. No other `purchase_type` rows exist on dev at the time of this migration.

---

## DEV — already applied

Run against `aluria_dev` on **2026-07-24**, by Claude (this session), against the Tailscale dev host (`100.98.160.119`).

```sql
-- ============================================================================
-- v28 DEV — purchase_type.number_format / sequence_digits
-- ============================================================================

USE `aluria_dev`;

-- ----------------------------------------------------------------------------
-- Step 1: add columns (idempotent, information_schema guard).
-- ----------------------------------------------------------------------------
SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_dev' AND table_name = 'purchase_type'
    AND column_name = 'number_format'
);
SET @alter_sql := IF(@schema_check > 0,
  'SELECT ''purchase_type.number_format already exists, skipping'' AS note',
  'ALTER TABLE aluria_dev.purchase_type
     ADD COLUMN number_format   VARCHAR(255)     NULL COMMENT ''template for po_display_number, tokens: {company_code} {yy} {yyyy} {month} {seq}'' AFTER vat_applicable,
     ADD COLUMN sequence_digits TINYINT UNSIGNED NOT NULL DEFAULT 4 AFTER number_format'
);
PREPARE alter_purchase_type FROM @alter_sql;
EXECUTE alter_purchase_type;
DEALLOCATE PREPARE alter_purchase_type;

-- NOTE: COMMENT must precede the AFTER position clause in MySQL's column_definition
-- grammar — `... NULL AFTER x COMMENT '...'` is a syntax error, `... NULL COMMENT '...' AFTER x`
-- is correct. Hit and fixed this during the actual dev run below.

-- ----------------------------------------------------------------------------
-- Step 2: backfill the two existing rows with their real historic formats
-- (derived from actual po_display_number values already in purchase_order —
-- not guessed). Any other purchase_type row is left with number_format = NULL
-- on purpose — an admin must set it explicitly via the purchase-type API
-- before generate-number will work for that type.
-- ----------------------------------------------------------------------------
UPDATE aluria_dev.purchase_type
SET number_format = '{company_code}/{yy}/{month}/{seq}', sequence_digits = 4
WHERE type_name = 'Import' AND deleted_at IS NULL AND number_format IS NULL;

UPDATE aluria_dev.purchase_type
SET number_format = '{company_code}/L/{month}/{yyyy}/{seq}', sequence_digits = 3
WHERE type_name = 'Local' AND deleted_at IS NULL AND number_format IS NULL;

-- ----------------------------------------------------------------------------
-- Step 3: verification
-- ----------------------------------------------------------------------------
SELECT id, type_name, number_format, sequence_digits FROM aluria_dev.purchase_type WHERE deleted_at IS NULL;
-- Expect: Import  -> {company_code}/{yy}/{month}/{seq}   sequence_digits=4
--         Local   -> {company_code}/L/{month}/{yyyy}/{seq} sequence_digits=3

SHOW COLUMNS FROM aluria_dev.purchase_type WHERE Field IN ('number_format','sequence_digits');
```

**Actual dev result (verified this session):**

```
id                                    type_name  number_format                          sequence_digits
9ac6581b-842c-11f1-93b5-525400d7fdd0  Import     {company_code}/{yy}/{month}/{seq}      4
9ac65bc3-842c-11f1-93b5-525400d7fdd0  Local      {company_code}/L/{month}/{yyyy}/{seq}  3

Field             Type                Null
number_format     varchar(255)        YES
sequence_digits   tinyint unsigned    NO
```

---

## PROD — NOT yet applied

**Do not run this against production without sign-off first.** Mirrors the DEV block exactly, scoped to prod schema names.

```sql
-- ============================================================================
-- v28 PROD — purchase_type.number_format / sequence_digits
-- ============================================================================

-- Replace aluria_prod below with the real prod schema name from prod's .env
-- (APP_SCHEMA) before running.

USE `aluria_prod`;

SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = 'aluria_prod' AND table_name = 'purchase_type'
    AND column_name = 'number_format'
);
SET @alter_sql := IF(@schema_check > 0,
  'SELECT ''purchase_type.number_format already exists, skipping'' AS note',
  'ALTER TABLE aluria_prod.purchase_type
     ADD COLUMN number_format   VARCHAR(255)     NULL COMMENT ''template for po_display_number, tokens: {company_code} {yy} {yyyy} {month} {seq}'' AFTER vat_applicable,
     ADD COLUMN sequence_digits TINYINT UNSIGNED NOT NULL DEFAULT 4 AFTER number_format'
);
PREPARE alter_purchase_type FROM @alter_sql;
EXECUTE alter_purchase_type;
DEALLOCATE PREPARE alter_purchase_type;

-- Backfill: confirm prod's purchase_type rows are named exactly 'Local' /
-- 'Import' before running — if prod has renamed or additional rows, this
-- WHERE clause silently backfills zero rows for anything that doesn't match
-- 'Import'/'Local' exactly, leaving number_format NULL (safe, but means
-- generate-number will 500 for that type until an admin sets it manually).
UPDATE aluria_prod.purchase_type
SET number_format = '{company_code}/{yy}/{month}/{seq}', sequence_digits = 4
WHERE type_name = 'Import' AND deleted_at IS NULL AND number_format IS NULL;

UPDATE aluria_prod.purchase_type
SET number_format = '{company_code}/L/{month}/{yyyy}/{seq}', sequence_digits = 3
WHERE type_name = 'Local' AND deleted_at IS NULL AND number_format IS NULL;

-- Verification — run before considering prod cutover complete:
SELECT id, type_name, number_format, sequence_digits FROM aluria_prod.purchase_type WHERE deleted_at IS NULL;

SHOW COLUMNS FROM aluria_prod.purchase_type WHERE Field IN ('number_format','sequence_digits');
```

---

## Post-migration checklist (both environments)

- [ ] **Re-verify prod's actual historic `po_display_number` values before assuming the dev formats apply.** This migration derived the two formats from dev's live `purchase_order` rows; if prod has its own independent history with a different format (unlikely since both point at the same business, but not verified here), check a sample before/after this migration lands.
- [ ] Any `purchase_type` row beyond `Local`/`Import` (on prod, or created after this migration on either environment) needs its `number_format` set explicitly via `PUT /api/v1/master/purchase-type/{id}` before `GET /api/v1/purchase-order/generate-number?type_id=...` will work for it — it returns `500` with a clear message otherwise, by design (no guessed default format).
- [ ] The API code for this feature (`v2/purchase/purchase-order/index.php`, `v2/master/purchase-type/index.php`) ships in the same session as this doc — ship this migration to prod before or alongside that code. Old code (no `number_format` references) is safe to run against the new schema; new code against the old (pre-migration) prod schema will `500` on every `purchase-order/generate-number` call and on `purchase-type` create/update calls that pass `number_format`/`sequence_digits`.
- [ ] No new permission keys introduced — `generate-number` uses the same `requireAuth()` gate as every other purchase-order endpoint.
