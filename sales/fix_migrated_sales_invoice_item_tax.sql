-- One-time data fix: sales_invoice_item.tax is documented and coded
-- (calculateSalesInvoiceTotal() in sales-invoice/index.php, and the export
-- label "Pajak (%)") as a PERCENTAGE RATE, but every row migrated from the
-- old v1 system (created_by = 'system_migration', all 345 rows in the whole
-- table -- there are zero live, app-created items yet) actually holds the
-- already-computed tax AMOUNT in Rupiah instead. Confirmed by reversing the
-- math: tax / (quantity * unit_price) lands almost exactly on 11% (Indonesia's
-- PPN rate) for the vast majority of rows, with at least one row at 2.2%.
--
-- This was invisible until now because GL posting (the only thing that reads
-- calculate-total's output) was itself broken for an unrelated reason
-- (missing default accounts, fixed earlier this session) and always
-- short-circuited before the bad number could do anything -- see
-- sales/backfill_sales_invoice_gl_postings.sql, which is what surfaced this
-- via a DECIMAL(18,2) overflow.
--
-- Fix: convert each row from amount to rate --
-- tax_percentage = ROUND(tax_amount / (quantity * unit_price) * 100, 2)
-- Rows where quantity * unit_price = 0 are skipped (can't back out a rate
-- from a zero base) and flagged in the preview for manual review instead.
--
-- Run this BEFORE sales/backfill_sales_invoice_gl_postings.sql -- the
-- backfill script reads sales_invoice_item.tax as-is and will reproduce the
-- same overflow again if run against the uncorrected data.
--
-- Schema: aluria (prod). Run the PREVIEW first.

USE `aluria`;

-- =========================================================
-- PREVIEW (read-only, run this first)
-- =========================================================
SELECT id, sales_invoice_id, quantity, unit_price, tax AS old_tax_amount,
       CASE WHEN quantity * unit_price = 0 THEN NULL
            ELSE ROUND(tax / (quantity * unit_price) * 100, 2) END AS new_tax_percentage
FROM sales_invoice_item
WHERE deleted_at IS NULL AND created_by = 'system_migration' AND tax > 100
ORDER BY sales_invoice_id;

SELECT
    COUNT(*)                                                              AS total_rows,
    SUM(CASE WHEN quantity * unit_price > 0 THEN 1 ELSE 0 END)            AS will_fix_count,
    SUM(CASE WHEN quantity * unit_price = 0 THEN 1 ELSE 0 END)            AS will_skip_zero_base_count
FROM sales_invoice_item
WHERE deleted_at IS NULL AND created_by = 'system_migration' AND tax > 100;

-- distribution of the resulting rate, sanity-check it clusters near 11%
SELECT ROUND(tax / (quantity * unit_price) * 100, 2) AS implied_pct, COUNT(*) AS c
FROM sales_invoice_item
WHERE deleted_at IS NULL AND created_by = 'system_migration' AND tax > 100 AND quantity * unit_price > 0
GROUP BY implied_pct
ORDER BY c DESC;

-- =========================================================
-- FIX (run after reviewing the preview above)
-- =========================================================
START TRANSACTION;

SET @fix_user := 'usr_6a4bb9daa62aa' COLLATE utf8mb4_general_ci;

INSERT INTO audit_log (id, company_id, module, reference_id, action, action_by, action_at, notes)
SELECT UUID(), si.company_id, 'sales_invoice_item', sii.id, 'tax_field_corrected', @fix_user, NOW(),
       CONCAT('Migration data fix: tax was ', sii.tax, ' (amount), corrected to ',
              ROUND(sii.tax / (sii.quantity * sii.unit_price) * 100, 2), ' (percentage). See sales/fix_migrated_sales_invoice_item_tax.sql')
FROM sales_invoice_item sii
JOIN sales_invoice si ON si.id = sii.sales_invoice_id
WHERE sii.deleted_at IS NULL AND sii.created_by = 'system_migration' AND sii.tax > 100
  AND sii.quantity * sii.unit_price > 0;

UPDATE sales_invoice_item
SET tax = ROUND(tax / (quantity * unit_price) * 100, 2),
    updated_by = @fix_user,
    updated_at = NOW()
WHERE deleted_at IS NULL AND created_by = 'system_migration' AND tax > 100
  AND quantity * unit_price > 0;

-- Verification -- check before COMMIT
SELECT COUNT(*) AS rows_fixed FROM sales_invoice_item
WHERE deleted_at IS NULL AND created_by = 'system_migration' AND updated_by = @fix_user;

SELECT MIN(tax), MAX(tax), AVG(tax) FROM sales_invoice_item
WHERE deleted_at IS NULL AND created_by = 'system_migration' AND updated_by = @fix_user;
-- Expect MIN/MAX now in a sane 0-100 range, clustered near 11.

SELECT COUNT(*) AS still_over_100 FROM sales_invoice_item
WHERE deleted_at IS NULL AND created_by = 'system_migration' AND tax > 100;
-- Expect this to equal will_skip_zero_base_count from the preview above.

COMMIT;
-- ROLLBACK;
