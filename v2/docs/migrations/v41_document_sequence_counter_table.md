# Aluria Schema Addendum — v41: `document_sequence` counter table (CRIT-14)

Adds a single new `APP_SCHEMA` table — `document_sequence` — that holds the last-issued sequence number per `(company_id, document_type, type_id, year, month)`, so document-number generation can be **atomic** via `INSERT … ON DUPLICATE KEY UPDATE last_sequence = last_sequence + 1` instead of the current non-atomic `SELECT COUNT(*) … LIKE 'pattern' → format → INSERT` flow (the pattern in `v2/sales/sales-order/index.php:51` and sibling modules). Under concurrency, the read-then-write gap in the current flow lets two callers read the same count and mint the same number — this table closes that gap with a single atomic statement backed by the `uq_sequence` unique key.

No existing table is altered. This is a net-new table.

## Design

- **`id`** is a `VARCHAR(36)` UUID primary key (per `CLAUDE.md`: users/companies use `generateUUID()`; this table's `id` is an internal row key, not a business resource, so `VARCHAR(36)` UUID is appropriate).
- **`document_type`** is an `ENUM` of the five document types that currently generate a `*display_number` from a count-based pattern: `purchase_order`, `sales_order`, `sales_delivery`, `sales_invoice`, `sales_sppb`.
- **`type_id`** is nullable — for `purchase_order` it carries the `purchase_type.id` (the number format varies by purchase type, per [[v28]]); for types with no sub-type dimension it stays `NULL`. The `uq_sequence` unique key includes `type_id`, and because `NULL` values are distinct in a unique index, multiple `NULL`-`type_id` rows per `(company_id, document_type, year, month)` would be allowed — which is wrong for the no-sub-type case. The application code MUST always pass a non-`NULL` sentinel (e.g. `'none'`) for `type_id` when the document type has no sub-type, never raw `NULL`, to keep the unique key meaningful. This is called out in the checklist.
- **`year` / `month`** scope the counter to a calendar period — document numbers reset per month (the existing format embeds `$roman_month/$year`), so the counter resets when the month rolls over.
- **`last_sequence`** starts at `0`; the first `INSERT … ON DUPLICATE KEY UPDATE last_sequence = last_sequence + 1` returns `1` for the first document of a period.
- **`updated_by` / `updated_at`** record who minted the last number and when, for audit.

## Application code change (separate task, NOT part of this migration)

This migration creates the table only. The actual atomic number generation is a **separate code task** — each module's `generate{Thing}Number()` (currently a `SELECT COUNT(*) … LIKE` + `sprintf`) must be rewritten to:

```php
$now = date('Y-m-d H:i:s');
$seq_sql = "INSERT INTO " . APP_SCHEMA . ".document_sequence
              (id, company_id, document_type, type_id, year, month, last_sequence, updated_by, updated_at)
            VALUES ('" . generateUUID() . "', '$company_id', 'sales_order', " . ($type_id_sql ?? "'none'") . ", $year, $month, 1, '$username', '$now')
            ON DUPLICATE KEY UPDATE last_sequence = last_sequence + 1, updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)";
mysqli_query($conn, $seq_sql);
// then fetch the resulting last_sequence to format the display number
```

`mysqli_insert_id()` is **not** useful here (it returns the auto-increment of the INSERT, which on an `ON DUPLICATE KEY UPDATE` hit is the existing row's id, not the new sequence). The code must `SELECT last_sequence … WHERE` the natural key after the upsert to read the minted number. That read is safe — no other caller can have bumped the same counter in between because the `ON DUPLICATE KEY UPDATE` is atomic and serialized on the unique key. No code change is deployed by this migration; the table is inert until the code is updated.

## DEV — already applied

Run against `aluria_dev` on **2026-10-17**, by Claude (this session). Idempotent via the `information_schema.TABLES` guard ([[v35]] style — not `CREATE TABLE IF NOT EXISTS`, consistent with the project's `information_schema`-check convention even though `IF NOT EXISTS` is syntactically valid for `CREATE TABLE`; the guard matches every other migration in this series).

```sql
-- ============================================================================
-- v41 DEV — document_sequence counter table
-- ============================================================================

USE `aluria_dev`;

SET @schema_check := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE table_schema = 'aluria_dev' AND table_name = 'document_sequence'
);
SET @create_sql := IF(@schema_check > 0,
  'SELECT ''document_sequence already exists, skipping'' AS note',
  'CREATE TABLE aluria_dev.document_sequence (
     id            VARCHAR(36) NOT NULL PRIMARY KEY,
     company_id    VARCHAR(36) NOT NULL,
     document_type ENUM(''purchase_order'',''sales_order'',''sales_delivery'',''sales_invoice'',''sales_sppb'') NOT NULL,
     type_id       VARCHAR(36) DEFAULT NULL,
     year          INT NOT NULL,
     month         INT NOT NULL,
     last_sequence INT NOT NULL DEFAULT 0,
     updated_by    VARCHAR(255) NULL,
     updated_at    DATETIME NULL,
     UNIQUE KEY uq_sequence (company_id, document_type, type_id, year, month),
     KEY idx_document_sequence_company (company_id)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
);
PREPARE create_document_sequence FROM @create_sql;
EXECUTE create_document_sequence;
DEALLOCATE PREPARE create_document_sequence;

-- ----------------------------------------------------------------------------
-- Verification
-- ----------------------------------------------------------------------------
DESCRIBE aluria_dev.document_sequence;
-- Expect: id varchar(36) PK, company_id varchar(36), document_type enum(5 values),
--         type_id varchar(36) nullable, year int, month int, last_sequence int default 0,
--         updated_by varchar(255) nullable, updated_at datetime nullable.

SELECT TABLE_COLLATION FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'aluria_dev' AND TABLE_NAME = 'document_sequence';
-- Expect: utf8mb4_general_ci (pinned explicitly — aluria_dev's schema default is
--         utf8mb4_0900_ai_ci and would otherwise mismatch sibling tables, per
--         [[project_schema_collation_mismatch]] / v35 follow-up).

SHOW INDEX FROM aluria_dev.document_sequence;
-- Expect: PRIMARY (id), uq_sequence (company_id, document_type, type_id, year, month),
--         idx_document_sequence_company (company_id).

-- Functional smoke (throwaway row, cleaned up after — do NOT leave in the DB):
-- INSERT INTO aluria_dev.document_sequence (id, company_id, document_type, type_id, year, month, last_sequence, updated_by, updated_at)
--   VALUES (UUID(), 'cmp_test', 'sales_order', 'none', 2026, 10, 1, 'test', NOW())
--   ON DUPLICATE KEY UPDATE last_sequence = last_sequence + 1;
-- SELECT last_sequence FROM aluria_dev.document_sequence
--   WHERE company_id='cmp_test' AND document_type='sales_order' AND type_id='none' AND year=2026 AND month=10;
-- -- Expect: 1, then 2 on a second run, then 3 on a third — atomic increment.
-- DELETE FROM aluria_dev.document_sequence WHERE company_id='cmp_test';
```

**DEV note:** table created with `utf8mb4_general_ci` pinned (see v35 follow-up for why this matters — `aluria_dev`'s connection default is `utf8mb4_0900_ai_ci`). No rows seeded — the table is populated lazily by the application code on first document-number generation per period. Re-run is a safe no-op via the `@schema_check` guard.

---

## PROD — NOT yet applied

**Do not run this against production without Kage's sign-off first** — same review gate as every other schema change. Purely additive (new table, no existing data touched), so no precondition/backfill step is needed, but the sign-off gate still applies.

```sql
-- ============================================================================
-- v41 PROD — document_sequence counter table
-- ============================================================================

-- Replace with prod's real APP_SCHEMA (from prod's .env). v35/v39 use 'aluria'.
SET @app_schema := 'aluria';

SET @sql := CONCAT(
  'SELECT COUNT(*) FROM information_schema.TABLES ',
  'WHERE table_schema = ''', @app_schema, ''' AND table_name = ''document_sequence'''
);
PREPARE schema_check FROM @sql; EXECUTE schema_check; DEALLOCATE PREPARE schema_check;
-- If the count above is > 0, skip Step 1 — table already exists.

-- ----------------------------------------------------------------------------
-- STEP 1 — create table
-- ----------------------------------------------------------------------------
SET @sql := CONCAT(
  'CREATE TABLE `', @app_schema, '`.document_sequence (
     id            VARCHAR(36) NOT NULL PRIMARY KEY,
     company_id    VARCHAR(36) NOT NULL,
     document_type ENUM(''purchase_order'',''sales_order'',''sales_delivery'',''sales_invoice'',''sales_sppb'') NOT NULL,
     type_id       VARCHAR(36) DEFAULT NULL,
     year          INT NOT NULL,
     month         INT NOT NULL,
     last_sequence INT NOT NULL DEFAULT 0,
     updated_by    VARCHAR(255) NULL,
     updated_at    DATETIME NULL,
     UNIQUE KEY uq_sequence (company_id, document_type, type_id, year, month),
     KEY idx_document_sequence_company (company_id)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
);
PREPARE create_document_sequence FROM @sql;
EXECUTE create_document_sequence;
DEALLOCATE PREPARE create_document_sequence;

-- ----------------------------------------------------------------------------
-- STEP 2 — verification
-- ----------------------------------------------------------------------------
SET @sql := CONCAT('DESCRIBE `', @app_schema, '`.document_sequence');
PREPARE verify_describe FROM @sql; EXECUTE verify_describe; DEALLOCATE PREPARE verify_describe;

SET @sql := CONCAT('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ''', @app_schema, ''' AND TABLE_NAME = ''document_sequence''');
PREPARE verify_collation FROM @sql; EXECUTE verify_collation; DEALLOCATE PREPARE verify_collation;
-- Expect: utf8mb4_general_ci.

SET @sql := CONCAT('SHOW INDEX FROM `', @app_schema, '`.document_sequence');
PREPARE verify_index FROM @sql; EXECUTE verify_index; DEALLOCATE PREPARE verify_index;
-- Expect: PRIMARY, uq_sequence, idx_document_sequence_company.
```

**Deploy order:** schema-first, code-later. The table is inert until the application code is updated to use `INSERT … ON DUPLICATE KEY UPDATE` against it. Old code against the new schema is a no-op (table simply unused). New code against the old schema will error on every number-generation call — so the code deploy must come **after** this schema change, not before.

---

## Post-migration checklist (both environments)

- [ ] **Application code update is a separate task, not done by this migration.** Each module's `generate{Thing}Number()` must be rewritten to upsert into `document_sequence` (atomic `INSERT … ON DUPLICATE KEY UPDATE last_sequence = last_sequence + 1`) and then `SELECT last_sequence` by the natural key to read the minted number. Affected modules: `v2/purchase/purchase-order/index.php`, `v2/sales/sales-order/index.php`, `v2/sales/sales-delivery/index.php`, `v2/sales/sales-invoice/index.php`, `v2/sales/sales-sppb/index.php`. Until this code ships, the table exists but is unused.
- [ ] **`type_id` sentinel for no-sub-type documents.** For document types with no sub-type dimension (every type except `purchase_order`, which varies by `purchase_type` per [[v28]]), the application code MUST pass a non-`NULL` sentinel value (e.g. `'none'`) for `type_id` — never raw `NULL`. Because `NULL` values are distinct in the `uq_sequence` unique key, a raw `NULL` would allow multiple counter rows per `(company_id, document_type, year, month)`, breaking the atomic-increment guarantee. This is an application-layer contract, not enforceable by the schema alone.
- [ ] **No backfill needed.** Existing document numbers generated by the old `COUNT(*) … LIKE` flow are not retroactively inserted into `document_sequence`. The counter starts fresh from `1` for the current period the first time the new code runs; historical numbers stay as-is on their documents. If the new code mints a number that collides with an existing active document (possible if the old flow and new flow overlap in the same period), the v40 `uq_*_active` constraint will catch it — run v40 before relying on this table.
- [ ] **Month rollover.** The counter is keyed by `(year, month)`, so it naturally resets to `last_sequence = 0` → first insert returns `1` at the start of a new month. No cron or reset job is needed — the first `INSERT` of a new month creates a fresh row.
- [ ] No `CORE_SCHEMA` (movira_core) changes and no permission keys involved — this is an internal counter table, never exposed directly by an API endpoint.
