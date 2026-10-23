# Aluria Schema Migration — v45: `document_watermark.watermark_name`

## Summary

`GET /api/v2/document-watermark` has selected `dw.watermark_name` since the
module was written, but the column never existed in either `aluria_dev` or
`aluria` (verified via `information_schema.COLUMNS` on 2026-10-19). The list
query therefore failed on every call, and the old code turned that failure
into an empty `data: []` — the list always looked empty even though rows
existed (2 in `aluria_dev`). This migration adds the column so watermarks can
be named, and the module now:

- requires `watermark_name` (1–255 chars, trimmed) on `POST`,
- accepts `watermark_name` and/or `watermark` on `PUT` (rename without
  re-uploading the image),
- returns `500` with an `error_id` if the list query fails, instead of an
  empty list.

Column: `watermark_name varchar(255) NULL`, matching the other `*_name`
columns in `APP_SCHEMA` (`salary_category.category_name`,
`finance_category.category_name`, `account_code.account_code_name`) and the
table's `utf8mb4_general_ci` collation.

**Existing rows are left `NULL`.** There is no source data to derive a name
from, so none is invented; the frontend shows a generic "Watermark" label
until someone renames them.

## Deploy order

Apply the column **before** deploying this code. New code against the old
schema fails every list/create/update call (unknown column). Old code
against the new schema is safe — and in fact fixes the old list query, which
only ever failed because the column was missing.

## DEV (`aluria_dev`) — applied 2026-10-19

```sql
-- WHY guarded: re-runnable, and works on servers older than MySQL 8.0.29
-- (no ADD COLUMN IF NOT EXISTS).
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = 'aluria_dev' AND TABLE_NAME = 'document_watermark' AND COLUMN_NAME = 'watermark_name');
SET @ddl = IF(@col_exists = 0,
  'ALTER TABLE `aluria_dev`.`document_watermark` ADD COLUMN `watermark_name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL AFTER `company_id`',
  'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
```

## PROD (`aluria`) — NOT yet applied

```sql
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = 'aluria' AND TABLE_NAME = 'document_watermark' AND COLUMN_NAME = 'watermark_name');
SET @ddl = IF(@col_exists = 0,
  'ALTER TABLE `aluria`.`document_watermark` ADD COLUMN `watermark_name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL AFTER `company_id`',
  'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
```

## Verification

```sql
SELECT TABLE_SCHEMA, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_NAME = 'document_watermark' AND COLUMN_NAME = 'watermark_name';
-- expect one row per applied schema: varchar(255), YES, utf8mb4_general_ci

SELECT COUNT(*) AS total, SUM(watermark_name IS NULL) AS unnamed
FROM aluria_dev.document_watermark WHERE deleted_at IS NULL;
-- pre-existing rows stay unnamed (NULL) by design
```

## Post-migration checklist

- [ ] Apply the PROD block to `aluria` before deploying this code to production.
- [ ] Pre-existing watermarks have `watermark_name = NULL` — rename them from
      Settings → Document Watermark if you want them labeled.
- No permission changes: writes still require `master.document_watermark.manage`.
