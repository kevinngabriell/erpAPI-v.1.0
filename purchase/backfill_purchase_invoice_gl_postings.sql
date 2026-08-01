-- One-time backfill: posts the missing expense-recognition GL entries
-- (Harga Pokok Penjualan debit / Hutang Usaha credit) for every already-
-- Approved purchase_invoice that has no corresponding general_journal row.
-- Mirrors sales/backfill_sales_invoice_gl_postings.sql exactly, same reasons:
-- general_journal has 0 rows for purchase_invoice, all 139 purchase invoices
-- were bulk-migrated directly into 'Approved' status, never through the
-- app's approve endpoint, so postPurchaseInvoiceRecognition() never ran.
--
-- Run purchase/fix_purchase_invoice_item_total.sql BEFORE this script --
-- purchase_invoice_item.total was 0 for every row until that fix ran, and
-- calculatePurchaseInvoiceTotal() (mirrored below) sums that column directly.
--
-- Default accounts (set via API before this script ran):
--   is_default_payable          -> 205-006-000 HUTANG USAHA
--   is_default_purchase_expense -> 502-008-000 HARGA POKOK PENJUALAN (new)
--
-- Sign convention matches helpers/general_journal.php: positive amount
-- increases the account's own natural balance. Both lines get +total_invoice
-- (expense is debit-normal, payable is credit-normal) -- balances to 0 in
-- the debit-equivalent sum, same as every live approval already does.
--
-- Schema: aluria (prod). Run the PREVIEW first.

USE `aluria`;

SET @company_id       := 'cmp7738323d94503bbc' COLLATE utf8mb4_general_ci;
SET @payable_account  := 'f8211907-8b6a-11f1-93b5-525400d7fdd0' COLLATE utf8mb4_general_ci; -- 205-006-000 HUTANG USAHA
SET @expense_account  := '685dc658-9570-4c7f-9116-754bd0e6c3b7' COLLATE utf8mb4_general_ci; -- 502-008-000 HARGA POKOK PENJUALAN
SET @backfill_user    := 'usr_6a4bb9daa62aa' COLLATE utf8mb4_general_ci;

-- =========================================================
-- PREVIEW (read-only, run this first)
-- =========================================================
SELECT pi.id, pi.invoice_display_number, pi.invoice_date,
       COALESCE(t.total, 0) AS total_invoice
FROM purchase_invoice pi
JOIN purchase_status ps ON ps.id = pi.status_id
LEFT JOIN (
    SELECT purchase_invoice_id, SUM(total) AS total
    FROM purchase_invoice_item WHERE deleted_at IS NULL GROUP BY purchase_invoice_id
) t ON t.purchase_invoice_id = pi.id
LEFT JOIN general_journal gj ON gj.source_module = 'purchase_invoice' AND gj.source_document_id = pi.id AND gj.deleted_at IS NULL
WHERE pi.company_id = @company_id
  AND pi.deleted_at IS NULL
  AND ps.status_name = 'Approved'
  AND gj.id IS NULL
ORDER BY pi.invoice_date;

SELECT
    COUNT(*)                                                    AS missing_gl_count,
    SUM(CASE WHEN COALESCE(t.total, 0) > 0 THEN 1 ELSE 0 END)   AS will_post_count,
    SUM(CASE WHEN COALESCE(t.total, 0) <= 0 THEN 1 ELSE 0 END)  AS will_skip_zero_amount_count,
    SUM(COALESCE(t.total, 0))                                   AS total_expense_to_post
FROM purchase_invoice pi
JOIN purchase_status ps ON ps.id = pi.status_id
LEFT JOIN (
    SELECT purchase_invoice_id, SUM(total) AS total
    FROM purchase_invoice_item WHERE deleted_at IS NULL GROUP BY purchase_invoice_id
) t ON t.purchase_invoice_id = pi.id
LEFT JOIN general_journal gj ON gj.source_module = 'purchase_invoice' AND gj.source_document_id = pi.id AND gj.deleted_at IS NULL
WHERE pi.company_id = @company_id
  AND pi.deleted_at IS NULL
  AND ps.status_name = 'Approved'
  AND gj.id IS NULL;

-- =========================================================
-- BACKFILL (run after reviewing the preview above)
-- =========================================================
START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_pi_gl_backfill;

CREATE TEMPORARY TABLE tmp_pi_gl_backfill (
    purchase_invoice_id VARCHAR(50) COLLATE utf8mb4_general_ci PRIMARY KEY,
    general_journal_id  VARCHAR(50) COLLATE utf8mb4_general_ci,
    invoice_date        DATE,
    total_invoice        DECIMAL(18,2)
);

INSERT INTO tmp_pi_gl_backfill (purchase_invoice_id, general_journal_id, invoice_date, total_invoice)
SELECT pi.id, UUID(), pi.invoice_date, t.total
FROM purchase_invoice pi
JOIN purchase_status ps ON ps.id = pi.status_id
JOIN (
    SELECT purchase_invoice_id, SUM(total) AS total
    FROM purchase_invoice_item WHERE deleted_at IS NULL GROUP BY purchase_invoice_id
) t ON t.purchase_invoice_id = pi.id AND t.total > 0
LEFT JOIN general_journal gj ON gj.source_module = 'purchase_invoice' AND gj.source_document_id = pi.id AND gj.deleted_at IS NULL
WHERE pi.company_id = @company_id
  AND pi.deleted_at IS NULL
  AND ps.status_name = 'Approved'
  AND gj.id IS NULL;

INSERT INTO general_journal (id, company_id, transaction_date, memo, source_module, source_document_id, transaction_status, created_by, created_at)
SELECT general_journal_id, @company_id, invoice_date,
       CONCAT('Pengakuan hutang - purchase_invoice ', purchase_invoice_id, ' (backfill)'),
       'purchase_invoice', purchase_invoice_id, 'posted', @backfill_user, NOW()
FROM tmp_pi_gl_backfill;

INSERT INTO general_journal_detail (id, general_journal_id, account_code_id, amount, memo, created_by, created_at)
SELECT UUID(), general_journal_id, @expense_account, total_invoice, NULL, @backfill_user, NOW()
FROM tmp_pi_gl_backfill;

INSERT INTO general_journal_detail (id, general_journal_id, account_code_id, amount, memo, created_by, created_at)
SELECT UUID(), general_journal_id, @payable_account, total_invoice, NULL, @backfill_user, NOW()
FROM tmp_pi_gl_backfill;

INSERT INTO audit_log (id, company_id, module, reference_id, action, action_by, action_at, notes)
SELECT UUID(), @company_id, 'general_journal', purchase_invoice_id, 'gl_backfill', @backfill_user, NOW(),
       'One-time backfill of expense recognition for pre-existing Approved purchase invoice (see purchase/backfill_purchase_invoice_gl_postings.sql)'
FROM tmp_pi_gl_backfill;

-- Verification -- check before COMMIT
SELECT COUNT(*) AS journal_entries_created FROM tmp_pi_gl_backfill;
SELECT SUM(total_invoice) AS total_expense_posted FROM tmp_pi_gl_backfill;

SELECT COUNT(*) AS still_missing_gl_after_backfill
FROM purchase_invoice pi
JOIN purchase_status ps ON ps.id = pi.status_id
LEFT JOIN general_journal gj ON gj.source_module = 'purchase_invoice' AND gj.source_document_id = pi.id AND gj.deleted_at IS NULL
WHERE pi.company_id = @company_id AND pi.deleted_at IS NULL AND ps.status_name = 'Approved' AND gj.id IS NULL;
-- Expect this to equal will_skip_zero_amount_count from the preview above.

DROP TEMPORARY TABLE tmp_pi_gl_backfill;

COMMIT;
-- ROLLBACK;
