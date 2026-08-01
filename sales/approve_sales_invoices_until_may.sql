-- Bulk-approves all sales_invoice rows (v2/Aluria schema) with invoice_date
-- on or before 2026-05-31, moving them from 'Draft' to 'Approved'.
--
-- Scope: only status_name = 'Draft' rows are touched -- already-Rejected or
-- already-Approved invoices are left as-is (matches the sales module's
-- single-approval workflow; see v27_sales_invoice_approval_schema.md).
--
-- approved_by / updated_by are left NULL -- this is a bulk script, not a
-- real user action, so there is no app_user_id to stamp (neither column has
-- an FK constraint, but a fake sentinel string would still resolve to a
-- blank name wherever the API left-joins these to app_user). approved_at /
-- updated_at are set to NOW() to record when the bulk approval happened.
--
-- Schema: aluria_dev (v2). Replace with the real prod schema name before
-- running against prod -- do not assume it matches.
--
-- Usage:
--   1. Run the PREVIEW block first. Confirm the row count and date range
--      look right before approving anything.
--   2. If the preview looks correct, run the UPDATE block.
--   3. ROLLBACK is safe to run instead of COMMIT if anything looks wrong.

USE `aluria_dev`;

-- =========================================================
-- PREVIEW (read-only, run this first)
-- =========================================================
SELECT si.id, si.company_id, si.invoice_display_number, si.invoice_date, ss.status_name
FROM sales_invoice si
INNER JOIN sales_status ss ON ss.id = si.status_id
WHERE si.deleted_at IS NULL
  AND ss.status_name = 'Draft'
  AND si.invoice_date <= '2026-05-31'
ORDER BY si.invoice_date;

SELECT COUNT(*) AS to_approve_count
FROM sales_invoice si
INNER JOIN sales_status ss ON ss.id = si.status_id
WHERE si.deleted_at IS NULL
  AND ss.status_name = 'Draft'
  AND si.invoice_date <= '2026-05-31';

-- =========================================================
-- UPDATE (run after reviewing the preview above)
-- =========================================================
START TRANSACTION;

SET @approved_status_id := (SELECT id FROM sales_status WHERE status_name = 'Approved' AND deleted_at IS NULL LIMIT 1);
SET @now := NOW();

UPDATE sales_invoice si
INNER JOIN sales_status ss ON ss.id = si.status_id
SET si.status_id = @approved_status_id,
    si.approved_at = @now,
    si.updated_at = @now
WHERE si.deleted_at IS NULL
  AND ss.status_name = 'Draft'
  AND si.invoice_date <= '2026-05-31';

-- Verification -- check before COMMIT
SELECT ss.status_name, COUNT(*) AS c
FROM sales_invoice si
LEFT JOIN sales_status ss ON ss.id = si.status_id
WHERE si.deleted_at IS NULL AND si.invoice_date <= '2026-05-31'
GROUP BY ss.status_name;

COMMIT;
-- ROLLBACK;
