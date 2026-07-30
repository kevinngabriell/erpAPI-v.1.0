-- One-time correction for 4 migrated sales_invoice rows discovered while
-- reconciling the P&L against the company's real Rekap Pajak 2026: their
-- invoice_display_number encodes a different month/year than their
-- invoice_date column ended up with after migration (e.g. "VC/004/I/2025"
-- -- January 2025 -- was stored with invoice_date = 2026-04-21). Three of
-- these landed in April 2026 and accounted for nearly all of that month's
-- Rp 4.29B overage vs. the Rekap Pajak figure (their combined DPP of
-- Rp 4,350,196,000 almost exactly matches the Rp 4,285,319,580 gap).
--
-- Also fixes a duplicate sales_invoice_item row on VC/004/I/2025 (the same
-- "DHA POWDER 11%" line, 6000 x 334181, appears twice) found in the same
-- investigation -- unrelated bug, same invoice.
--
-- Per explicit user decision: invoice_date is corrected to the 1st of the
-- month implied by invoice_display_number (exact day unknown -- no better
-- source available right now); the duplicate item is soft-deleted, not
-- hard-deleted, matching how deletes work everywhere else in this app.
--
-- IMPORTANT: sales/backfill_sales_invoice_gl_postings.sql already ran and
-- posted general_journal entries for 3 of these 4 invoices using the WRONG
-- date and (for VC/004) the WRONG duplicated amount. This script soft-deletes
-- those stale entries so the backfill script can be re-run afterward to
-- repost them correctly -- run backfill_sales_invoice_gl_postings.sql again
-- immediately after this script.
--
-- Schema: aluria (prod). Run the PREVIEW first.

USE `aluria`;

SET @fix_user := 'usr_6a4bb9daa62aa' COLLATE utf8mb4_general_ci;
SET @company_id := 'cmp7738323d94503bbc' COLLATE utf8mb4_general_ci;

-- the 4 misdated invoices, and the duplicate item on VC/004
SET @inv_vc004 := '0273df41-8b6b-11f1-93b5-525400d7fdd0' COLLATE utf8mb4_general_ci; -- VC/004/I/2025 -> 2025-01-01
SET @inv_vc001 := '027694b5-8b6b-11f1-93b5-525400d7fdd0' COLLATE utf8mb4_general_ci; -- VC/001/I/2025 -> 2025-01-01
SET @inv_vc046 := '0274b053-8b6b-11f1-93b5-525400d7fdd0' COLLATE utf8mb4_general_ci; -- VC/046/II/2025 -> 2025-02-01
SET @inv_vc016 := '0275228b-8b6b-11f1-93b5-525400d7fdd0' COLLATE utf8mb4_general_ci; -- VC/016/I/2025 -> 2025-01-01 (was 2024-01-09)
SET @dup_item  := '02ada209-8b6b-11f1-93b5-525400d7fdd0' COLLATE utf8mb4_general_ci; -- duplicate DHA POWDER line on VC/004

-- =========================================================
-- PREVIEW (read-only, run this first)
-- =========================================================
SELECT id, invoice_display_number, invoice_date AS current_invoice_date
FROM sales_invoice
WHERE id IN (@inv_vc004, @inv_vc001, @inv_vc046, @inv_vc016);

SELECT id, sales_invoice_id, product_name, quantity, unit_price, tax, deleted_at
FROM sales_invoice_item
WHERE id = @dup_item;

SELECT gj.id, gj.source_document_id, gj.transaction_date, gj.memo
FROM general_journal gj
WHERE gj.source_module = 'sales_invoice'
  AND gj.source_document_id IN (@inv_vc004, @inv_vc001, @inv_vc046, @inv_vc016)
  AND gj.deleted_at IS NULL;

-- =========================================================
-- FIX (run after reviewing the preview above)
-- =========================================================
START TRANSACTION;

-- 1. soft-delete the duplicate line item
INSERT INTO audit_log (id, company_id, module, reference_id, action, action_by, action_at, notes)
VALUES (UUID(), @company_id, 'sales_invoice_item', @dup_item, 'duplicate_item_removed', @fix_user, NOW(),
        'Migration duplicate: identical DHA POWDER 11% line (6000 x 334181) appeared twice on VC/004/I/2025. See sales/fix_misdated_duplicate_invoices.sql');

UPDATE sales_invoice_item
SET deleted_at = NOW(), updated_by = @fix_user, updated_at = NOW()
WHERE id = @dup_item;

-- 2. correct invoice_date on all 4 misdated invoices
INSERT INTO audit_log (id, company_id, module, reference_id, action, action_by, action_at, notes)
SELECT UUID(), @company_id, 'sales_invoice', id, 'invoice_date_corrected', @fix_user, NOW(),
       CONCAT('Migration date bug: invoice_display_number (', invoice_display_number, ') implies a different month/year than invoice_date (', invoice_date,
              '). Corrected to 1st of the implied month -- exact day unknown. See sales/fix_misdated_duplicate_invoices.sql')
FROM sales_invoice
WHERE id IN (@inv_vc004, @inv_vc001, @inv_vc046, @inv_vc016);

UPDATE sales_invoice SET invoice_date = '2025-01-01', updated_by = @fix_user, updated_at = NOW() WHERE id IN (@inv_vc004, @inv_vc001);
UPDATE sales_invoice SET invoice_date = '2025-02-01', updated_by = @fix_user, updated_at = NOW() WHERE id = @inv_vc046;
UPDATE sales_invoice SET invoice_date = '2025-01-01', updated_by = @fix_user, updated_at = NOW() WHERE id = @inv_vc016;

-- 3. soft-delete the stale (wrong date/amount) general_journal entries already
-- posted for these 4 by backfill_sales_invoice_gl_postings.sql, so that
-- script can be re-run afterward to repost them correctly
INSERT INTO audit_log (id, company_id, module, reference_id, action, action_by, action_at, notes)
SELECT UUID(), @company_id, 'general_journal', gj.id, 'gl_backfill_reversed', @fix_user, NOW(),
       'Reversed: originally posted with wrong invoice_date/amount before fix_misdated_duplicate_invoices.sql corrected the source invoice. Re-run backfill_sales_invoice_gl_postings.sql to repost correctly.'
FROM general_journal gj
WHERE gj.source_module = 'sales_invoice'
  AND gj.source_document_id IN (@inv_vc004, @inv_vc001, @inv_vc046, @inv_vc016)
  AND gj.deleted_at IS NULL;

UPDATE general_journal_detail gjd
JOIN general_journal gj ON gj.id = gjd.general_journal_id
SET gjd.deleted_at = NOW(), gjd.updated_by = @fix_user, gjd.updated_at = NOW()
WHERE gj.source_module = 'sales_invoice'
  AND gj.source_document_id IN (@inv_vc004, @inv_vc001, @inv_vc046, @inv_vc016)
  AND gj.deleted_at IS NULL;

UPDATE general_journal
SET deleted_at = NOW(), updated_by = @fix_user, updated_at = NOW()
WHERE source_module = 'sales_invoice'
  AND source_document_id IN (@inv_vc004, @inv_vc001, @inv_vc046, @inv_vc016)
  AND deleted_at IS NULL;

-- Verification -- check before COMMIT
SELECT id, invoice_display_number, invoice_date AS corrected_invoice_date FROM sales_invoice
WHERE id IN (@inv_vc004, @inv_vc001, @inv_vc046, @inv_vc016);

SELECT id, deleted_at FROM sales_invoice_item WHERE id = @dup_item;

SELECT COUNT(*) AS remaining_active_gl_entries FROM general_journal
WHERE source_module = 'sales_invoice'
  AND source_document_id IN (@inv_vc004, @inv_vc001, @inv_vc046, @inv_vc016)
  AND deleted_at IS NULL;
-- Expect 0 -- confirms the stale entries are cleared and ready for repost.

COMMIT;
-- ROLLBACK;

-- After COMMIT: re-run sales/backfill_sales_invoice_gl_postings.sql from the
-- top to repost correct GL entries for these 4 invoices with their fixed
-- dates/amounts.
