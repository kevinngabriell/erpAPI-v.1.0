-- One-time backfill: posts the missing revenue-recognition GL entries (Piutang
-- Usaha debit / Pendapatan Penjualan credit) for every already-Approved
-- sales_invoice that has no corresponding general_journal row.
--
-- Why this is needed: general_journal has 0 rows for this company. The 290
-- sales_invoice rows dated <= 2026-05-31 were bulk-migrated directly into
-- 'Approved' status back on 2026-06-02 (created_by = system_migration-style),
-- never going through the app's PATCH .../approve endpoint, so
-- postSalesInvoiceRecognition() in sales-invoice/index.php never ran for
-- them. Setting the default GL accounts (is_default_receivable on PIUTANG
-- USAHA, is_default_sales_revenue on the newly-created PENDAPATAN PENJUALAN)
-- only affects future approvals -- it does not retroactively post anything.
-- There is no public API for hand-keying a general_journal entry (see the
-- comment at the top of finance/general-journal/index.php -- it's read-only
-- by design), so this one-time SQL backfill is the only path, mirroring
-- exactly what postGeneralJournalEntry() would have produced per invoice.
--
-- Scope: ALL Approved sales_invoice rows regardless of date (not just <=
-- May) -- explicit user decision, since the GL gap is company-wide, not
-- May-specific.
--
-- Sign convention matches helpers/general_journal.php: positive amount
-- increases the account's own natural balance. Both lines get +total_invoice
-- (receivable is debit-normal asset, revenue is credit-normal) -- this is
-- what makes the debit-equivalent sum balance to 0, same as every live
-- approval already does.
--
-- Invoices with total_invoice <= 0 (no items / zero amount) are skipped,
-- matching postSalesInvoiceRecognition()'s own `if ($total_invoice <= 0)
-- return;` guard -- they would never have posted even through the live
-- endpoint.
--
-- Schema: aluria (prod). Run the PREVIEW first.

USE `aluria`;

-- COLLATE pinned on every variable below -- a plain `SET @var := 'literal'`
-- takes the connection's default collation (utf8mb4_0900_ai_ci on this
-- MySQL 8 server), not the literal's coercible collation, which throws
-- "Illegal mix of collations" the moment it's compared against any
-- utf8mb4_general_ci column (every table in this schema, confirmed via
-- information_schema.columns -- see project_schema_collation_mismatch memory).
SET @company_id          := 'cmp7738323d94503bbc' COLLATE utf8mb4_general_ci;
SET @receivable_account  := 'f8210bd5-8b6a-11f1-93b5-525400d7fdd0' COLLATE utf8mb4_general_ci; -- 105-013-000 PIUTANG USAHA
SET @revenue_account     := '6e13c24e-7e36-4558-b88d-3753e8d4c7f8' COLLATE utf8mb4_general_ci; -- 401-002-000 PENDAPATAN PENJUALAN
SET @backfill_user       := 'usr_6a4bb9daa62aa' COLLATE utf8mb4_general_ci; -- the account running this backfill

-- =========================================================
-- PREVIEW (read-only, run this first)
-- =========================================================
SELECT si.id, si.invoice_display_number, si.invoice_date,
       COALESCE(t.total, 0) AS total_invoice
FROM sales_invoice si
JOIN sales_status ss ON ss.id = si.status_id
LEFT JOIN (
    SELECT sales_invoice_id, SUM(quantity * unit_price * (1 + tax / 100)) AS total
    FROM sales_invoice_item WHERE deleted_at IS NULL GROUP BY sales_invoice_id
) t ON t.sales_invoice_id = si.id
LEFT JOIN general_journal gj ON gj.source_module = 'sales_invoice' AND gj.source_document_id = si.id AND gj.deleted_at IS NULL
WHERE si.company_id = @company_id
  AND si.deleted_at IS NULL
  AND ss.status_name = 'Approved'
  AND gj.id IS NULL
ORDER BY si.invoice_date;

SELECT
    COUNT(*)                                        AS missing_gl_count,
    SUM(CASE WHEN COALESCE(t.total, 0) > 0 THEN 1 ELSE 0 END) AS will_post_count,
    SUM(CASE WHEN COALESCE(t.total, 0) <= 0 THEN 1 ELSE 0 END) AS will_skip_zero_amount_count,
    SUM(COALESCE(t.total, 0))                       AS total_revenue_to_post
FROM sales_invoice si
JOIN sales_status ss ON ss.id = si.status_id
LEFT JOIN (
    SELECT sales_invoice_id, SUM(quantity * unit_price * (1 + tax / 100)) AS total
    FROM sales_invoice_item WHERE deleted_at IS NULL GROUP BY sales_invoice_id
) t ON t.sales_invoice_id = si.id
LEFT JOIN general_journal gj ON gj.source_module = 'sales_invoice' AND gj.source_document_id = si.id AND gj.deleted_at IS NULL
WHERE si.company_id = @company_id
  AND si.deleted_at IS NULL
  AND ss.status_name = 'Approved'
  AND gj.id IS NULL;

-- =========================================================
-- BACKFILL (run after reviewing the preview above)
-- =========================================================
START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_gl_backfill; -- leftover guard, in case a prior attempt errored out before reaching the DROP at the end of this script

CREATE TEMPORARY TABLE tmp_gl_backfill (
    sales_invoice_id   VARCHAR(50) COLLATE utf8mb4_general_ci PRIMARY KEY,
    general_journal_id VARCHAR(50) COLLATE utf8mb4_general_ci,
    invoice_date       DATE,
    total_invoice      DECIMAL(18,2)
);

INSERT INTO tmp_gl_backfill (sales_invoice_id, general_journal_id, invoice_date, total_invoice)
SELECT si.id, UUID(), si.invoice_date, t.total
FROM sales_invoice si
JOIN sales_status ss ON ss.id = si.status_id
JOIN (
    SELECT sales_invoice_id, SUM(quantity * unit_price * (1 + tax / 100)) AS total
    FROM sales_invoice_item WHERE deleted_at IS NULL GROUP BY sales_invoice_id
) t ON t.sales_invoice_id = si.id AND t.total > 0
LEFT JOIN general_journal gj ON gj.source_module = 'sales_invoice' AND gj.source_document_id = si.id AND gj.deleted_at IS NULL
WHERE si.company_id = @company_id
  AND si.deleted_at IS NULL
  AND ss.status_name = 'Approved'
  AND gj.id IS NULL;

INSERT INTO general_journal (id, company_id, transaction_date, memo, source_module, source_document_id, transaction_status, created_by, created_at)
SELECT general_journal_id, @company_id, invoice_date,
       CONCAT('Pengakuan piutang - sales_invoice ', sales_invoice_id, ' (backfill)'),
       'sales_invoice', sales_invoice_id, 'posted', @backfill_user, NOW()
FROM tmp_gl_backfill;

INSERT INTO general_journal_detail (id, general_journal_id, account_code_id, amount, memo, created_by, created_at)
SELECT UUID(), general_journal_id, @receivable_account, total_invoice, NULL, @backfill_user, NOW()
FROM tmp_gl_backfill;

INSERT INTO general_journal_detail (id, general_journal_id, account_code_id, amount, memo, created_by, created_at)
SELECT UUID(), general_journal_id, @revenue_account, total_invoice, NULL, @backfill_user, NOW()
FROM tmp_gl_backfill;

INSERT INTO audit_log (id, company_id, module, reference_id, action, action_by, action_at, notes)
SELECT UUID(), @company_id, 'general_journal', sales_invoice_id, 'gl_backfill', @backfill_user, NOW(),
       'One-time backfill of revenue recognition for pre-existing Approved invoice (see sales/backfill_sales_invoice_gl_postings.sql)'
FROM tmp_gl_backfill;

-- Verification -- check before COMMIT
SELECT COUNT(*) AS journal_entries_created FROM tmp_gl_backfill;
SELECT SUM(total_invoice) AS total_revenue_posted FROM tmp_gl_backfill;

SELECT COUNT(*) AS still_missing_gl_after_backfill
FROM sales_invoice si
JOIN sales_status ss ON ss.id = si.status_id
LEFT JOIN general_journal gj ON gj.source_module = 'sales_invoice' AND gj.source_document_id = si.id AND gj.deleted_at IS NULL
WHERE si.company_id = @company_id AND si.deleted_at IS NULL AND ss.status_name = 'Approved' AND gj.id IS NULL;
-- Expect this to equal will_skip_zero_amount_count from the preview above.

DROP TEMPORARY TABLE tmp_gl_backfill;

COMMIT;
-- ROLLBACK;
