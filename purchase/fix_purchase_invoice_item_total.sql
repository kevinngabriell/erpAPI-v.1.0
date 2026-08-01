-- One-time data fix: purchase_invoice_item.total is 0 for all 173 migrated
-- rows (created_by matches the same 2026-07-29 23:31:18 bulk-load batch as
-- sales_invoice_item). quantity/unit_price are correctly populated (real,
-- sane values); vat is genuinely 0 for every row with no source to recover
-- the true value from (unlike sales_invoice_item.tax, which was present but
-- mislabeled as an amount instead of a percentage -- see
-- sales/fix_migrated_sales_invoice_item_tax.sql for that one).
--
-- calculatePurchaseInvoiceTotal() in purchase-invoice/index.php sums this
-- `total` column directly (it does not derive it from quantity/unit_price/vat
-- the way the sales side computes on the fly), so leaving it at 0 means
-- every purchase invoice's calculated total is Rp 0, which would hit the
-- `if ($total_invoice <= 0) return;` guard in postPurchaseInvoiceRecognition()
-- and never post to the GL regardless of default-account configuration.
--
-- Per explicit user decision: total = quantity * unit_price, vat left at 0
-- (not guessed at 11% or any other rate) -- understates true expense by
-- whatever VAT was actually due, but doesn't fabricate a number the data
-- doesn't support. Revisit if/when a PPN Masukan recap becomes available.
--
-- Schema: aluria (prod). Run the PREVIEW first.

USE `aluria`;

SET @fix_user := 'usr_6a4bb9daa62aa' COLLATE utf8mb4_general_ci;

-- =========================================================
-- PREVIEW (read-only, run this first)
-- =========================================================
SELECT id, purchase_invoice_id, product_name, quantity, unit_price, vat, total AS current_total,
       (quantity * unit_price) + vat AS new_total
FROM purchase_invoice_item
WHERE deleted_at IS NULL AND total <= 0
ORDER BY purchase_invoice_id;

SELECT COUNT(*) AS rows_to_fix, SUM((quantity * unit_price) + vat) AS total_new_expense_value
FROM purchase_invoice_item
WHERE deleted_at IS NULL AND total <= 0;

-- =========================================================
-- FIX (run after reviewing the preview above)
-- =========================================================
START TRANSACTION;

INSERT INTO audit_log (id, company_id, module, reference_id, action, action_by, action_at, notes)
SELECT UUID(), pi.company_id, 'purchase_invoice_item', pii.id, 'total_field_corrected', @fix_user, NOW(),
       CONCAT('Migration data fix: total was 0 (never computed), corrected to ', (pii.quantity * pii.unit_price) + pii.vat,
              ' (quantity * unit_price + vat). See purchase/fix_purchase_invoice_item_total.sql')
FROM purchase_invoice_item pii
JOIN purchase_invoice pi ON pi.id = pii.purchase_invoice_id
WHERE pii.deleted_at IS NULL AND pii.total <= 0;

UPDATE purchase_invoice_item
SET total = (quantity * unit_price) + vat,
    updated_by = @fix_user,
    updated_at = NOW()
WHERE deleted_at IS NULL AND total <= 0;

-- Verification -- check before COMMIT
SELECT COUNT(*) AS rows_fixed FROM purchase_invoice_item
WHERE deleted_at IS NULL AND updated_by = @fix_user;

SELECT MIN(total), MAX(total), SUM(total) FROM purchase_invoice_item
WHERE deleted_at IS NULL AND updated_by = @fix_user;

SELECT COUNT(*) AS still_zero_or_negative FROM purchase_invoice_item WHERE deleted_at IS NULL AND total <= 0;
-- Expect 0.

COMMIT;
-- ROLLBACK;
