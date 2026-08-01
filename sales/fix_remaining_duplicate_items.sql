-- One-time de-dup for the remaining 14 sales_invoice_item duplicate groups
-- (VC/004/I/2025's duplicate was already fixed separately in
-- fix_misdated_duplicate_invoices.sql). All 14 are unrelated to the Jan-May
-- 2026 Rekap Pajak reconciliation (they're dated 2024-11 through 2025-03),
-- but were already posted to the GL by backfill_sales_invoice_gl_postings.sql
-- (scope was "all dates"), so their inflated amounts need reversing too.
--
-- Root cause confirmed contained, not recurring: every duplicated row shares
-- the exact same created_at (2026-07-29 23:31:18) as virtually the entire
-- sales_invoice_item table -- this is the timestamp of the one bulk data
-- load that populated all 345 item rows (separate from the 2026-06-02 schema
-- migration), and the duplicate-insert bug happened once, within that single
-- load, for these 14 invoices (12 with 1 duplicate line, 2 with extra
-- duplicates -- VC/006/I/2025 had 4 copies, VC/005/I/2025 had 3).
--
-- Per user decision: only de-duplicating here, NOT correcting invoice_date
-- (that's being handled separately with real source documents).
--
-- Schema: aluria (prod). Run the PREVIEW first.

USE `aluria`;

SET @fix_user := 'usr_6a4bb9daa62aa' COLLATE utf8mb4_general_ci;
SET @company_id := 'cmp7738323d94503bbc' COLLATE utf8mb4_general_ci;

-- one row to KEEP per duplicate group; every other id below gets soft-deleted
CREATE TEMPORARY TABLE tmp_dup_items_to_delete (id VARCHAR(50) COLLATE utf8mb4_general_ci PRIMARY KEY);
INSERT INTO tmp_dup_items_to_delete (id) VALUES
  ('02af44e7-8b6b-11f1-93b5-525400d7fdd0'), -- VC/018/XI/2024 (kept 02af4438)
  ('02affcd7-8b6b-11f1-93b5-525400d7fdd0'), -- VC/022/XI/2024 (kept 02affbdc)
  ('02add4a8-8b6b-11f1-93b5-525400d7fdd0'), -- VC/007/XII/2024 (kept 02add3da)
  ('02b0bc10-8b6b-11f1-93b5-525400d7fdd0'), -- VC/038/XII/2024 (kept 02b0bb48)
  ('02adbbb9-8b6b-11f1-93b5-525400d7fdd0'), -- VC/006/I/2025, copy 2 of 4 (kept 02adbb0f)
  ('02adbc5f-8b6b-11f1-93b5-525400d7fdd0'), -- VC/006/I/2025, copy 3 of 4
  ('02adbd04-8b6b-11f1-93b5-525400d7fdd0'), -- VC/006/I/2025, copy 4 of 4
  ('02add655-8b6b-11f1-93b5-525400d7fdd0'), -- VC/008/I/2025 (kept 02add5a6)
  ('02ae5861-8b6b-11f1-93b5-525400d7fdd0'), -- VC/010/I/2025 (kept 02ae57b2)
  ('02adb0e5-8b6b-11f1-93b5-525400d7fdd0'), -- VC/005/I/2025, copy 2 of 3 (kept 02adafbf)
  ('02adb1c0-8b6b-11f1-93b5-525400d7fdd0'), -- VC/005/I/2025, copy 3 of 3
  ('02ae7088-8b6b-11f1-93b5-525400d7fdd0'), -- VC/012/I/2025 (kept 02ae6f06)
  ('02b06ad5-8b6b-11f1-93b5-525400d7fdd0'), -- VC/025/I/2025 (kept 02b06a2d)
  ('02b07284-8b6b-11f1-93b5-525400d7fdd0'), -- VC/026/I/2025 (kept 02b071d6)
  ('02ae74a1-8b6b-11f1-93b5-525400d7fdd0'), -- VC/012/II/2025 (kept 02ae73a1)
  ('02b0b633-8b6b-11f1-93b5-525400d7fdd0'), -- VC/037/II/2025 (kept 02b0b589)
  ('02ad88ee-8b6b-11f1-93b5-525400d7fdd0'); -- VC/002/III/2025 (kept 02ad87db)

-- =========================================================
-- PREVIEW (read-only, run this first)
-- =========================================================
SELECT sii.id, sii.sales_invoice_id, si.invoice_display_number, sii.product_name,
       sii.quantity, sii.unit_price, sii.tax, sii.deleted_at
FROM sales_invoice_item sii
JOIN sales_invoice si ON si.id = sii.sales_invoice_id
JOIN tmp_dup_items_to_delete t ON t.id = sii.id;

SELECT COUNT(*) AS rows_to_delete FROM tmp_dup_items_to_delete;

-- affected invoices' existing (stale, inflated) GL entries
SELECT gj.id, gj.source_document_id, gj.transaction_date, gj.memo
FROM general_journal gj
WHERE gj.source_module = 'sales_invoice'
  AND gj.source_document_id IN (SELECT DISTINCT sales_invoice_id FROM sales_invoice_item WHERE id IN (SELECT id FROM tmp_dup_items_to_delete))
  AND gj.deleted_at IS NULL;

-- =========================================================
-- FIX (run after reviewing the preview above)
-- =========================================================
START TRANSACTION;

INSERT INTO audit_log (id, company_id, module, reference_id, action, action_by, action_at, notes)
SELECT UUID(), @company_id, 'sales_invoice_item', id, 'duplicate_item_removed', @fix_user, NOW(),
       'Bulk-load duplicate (created_at 2026-07-29 23:31:18 batch): identical line item inserted more than once. See sales/fix_remaining_duplicate_items.sql'
FROM tmp_dup_items_to_delete;

UPDATE sales_invoice_item sii
JOIN tmp_dup_items_to_delete t ON t.id = sii.id
SET sii.deleted_at = NOW(), sii.updated_by = @fix_user, sii.updated_at = NOW();

-- reverse the stale GL entries for the affected invoices so the backfill
-- script can repost them correctly afterward
INSERT INTO audit_log (id, company_id, module, reference_id, action, action_by, action_at, notes)
SELECT UUID(), @company_id, 'general_journal', gj.id, 'gl_backfill_reversed', @fix_user, NOW(),
       'Reversed: originally posted with duplicate-inflated amount before fix_remaining_duplicate_items.sql corrected the source invoice. Re-run backfill_sales_invoice_gl_postings.sql to repost correctly.'
FROM general_journal gj
WHERE gj.source_module = 'sales_invoice'
  AND gj.source_document_id IN (SELECT DISTINCT sales_invoice_id FROM sales_invoice_item WHERE id IN (SELECT id FROM tmp_dup_items_to_delete))
  AND gj.deleted_at IS NULL;

UPDATE general_journal_detail gjd
JOIN general_journal gj ON gj.id = gjd.general_journal_id
SET gjd.deleted_at = NOW(), gjd.updated_by = @fix_user, gjd.updated_at = NOW()
WHERE gj.source_module = 'sales_invoice'
  AND gj.source_document_id IN (SELECT DISTINCT sales_invoice_id FROM sales_invoice_item WHERE id IN (SELECT id FROM tmp_dup_items_to_delete))
  AND gj.deleted_at IS NULL;

UPDATE general_journal
SET deleted_at = NOW(), updated_by = @fix_user, updated_at = NOW()
WHERE source_module = 'sales_invoice'
  AND source_document_id IN (SELECT DISTINCT sales_invoice_id FROM sales_invoice_item WHERE id IN (SELECT id FROM tmp_dup_items_to_delete))
  AND deleted_at IS NULL;

-- Verification -- check before COMMIT
SELECT COUNT(*) AS remaining_active_duplicates FROM (
  SELECT sii.sales_invoice_id, sii.product_name, sii.quantity, sii.unit_price, sii.tax, COUNT(*) AS c
  FROM sales_invoice_item sii
  JOIN sales_invoice si ON si.id = sii.sales_invoice_id
  WHERE sii.deleted_at IS NULL AND si.deleted_at IS NULL AND si.company_id = @company_id
  GROUP BY sii.sales_invoice_id, sii.product_name, sii.quantity, sii.unit_price, sii.tax
  HAVING c > 1
) d;
-- Expect 0.

DROP TEMPORARY TABLE tmp_dup_items_to_delete;

COMMIT;
-- ROLLBACK;

-- After COMMIT: re-run sales/backfill_sales_invoice_gl_postings.sql from the
-- top to repost correct GL entries for these 14 invoices.
