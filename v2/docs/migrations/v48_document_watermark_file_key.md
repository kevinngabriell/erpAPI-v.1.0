# Aluria Schema Migration — v48: `document_watermark.file_key`

## Summary

Document watermarks can now live in the S3-compatible object storage
(`POST /api/v2/files`) instead of an inline `longblob`. A new nullable
`file_key varchar(512)` column stores the object key
(`{company_id}/{folder}/{uuid}.{ext}`); `watermark` is made nullable because
rows that use object storage have no inline image.

**Existing rows are untouched** — they keep their `watermark` blob and have
`file_key = NULL`, and the API keeps serving them exactly as before.

## Deploy order

Apply the column **before** deploying this code (the list query selects
`file_key`). Old code against the new schema is safe.

## DEV (`aluria_dev`) — NOT yet applied

```sql
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = 'aluria_dev' AND TABLE_NAME = 'document_watermark' AND COLUMN_NAME = 'file_key');
SET @ddl = IF(@col_exists = 0,
  'ALTER TABLE `aluria_dev`.`document_watermark` ADD COLUMN `file_key` varchar(512) COLLATE utf8mb4_general_ci DEFAULT NULL AFTER `watermark`',
  'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- Object-storage rows carry no inline image. Re-runnable (MODIFY is idempotent).
ALTER TABLE `aluria_dev`.`document_watermark` MODIFY COLUMN `watermark` longblob NULL;
```

## PROD (`aluria`) — NOT yet applied

```sql
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = 'aluria' AND TABLE_NAME = 'document_watermark' AND COLUMN_NAME = 'file_key');
SET @ddl = IF(@col_exists = 0,
  'ALTER TABLE `aluria`.`document_watermark` ADD COLUMN `file_key` varchar(512) COLLATE utf8mb4_general_ci DEFAULT NULL AFTER `watermark`',
  'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

ALTER TABLE `aluria`.`document_watermark` MODIFY COLUMN `watermark` longblob NULL;
```

## Verification

```sql
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
WHERE TABLE_NAME = 'document_watermark' AND COLUMN_NAME IN ('watermark', 'file_key');
-- expect watermark longblob YES, file_key varchar(512) YES
```

## Post-migration checklist

- [ ] Apply the DEV block, then the PROD block, before deploying this code.
- Orphaned objects: replacing or deleting a watermark does not delete its S3
  object; the frontend removes the old key on replace (best effort).
