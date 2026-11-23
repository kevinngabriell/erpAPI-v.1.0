# Aluria Schema Addendum — v50: default GL accounts (Pendapatan Penjualan / Pembelian) + sales invoice GL backfill

Revenue stayed Rp 0 on Laba Rugi because `postSalesInvoiceRecognition()` / `postPurchaseInvoiceRecognition()` skip
posting (audit action `gl_posting_skipped`) when a company has no default accounts. No code change — data only.

For **every company that has an `account_code` chart** this migration:
1. Adds `401-002-000 PENDAPATAN PENJUALAN` (revenue) and `503-001-000 PEMBELIAN` (expense) if missing.
2. Fixes `HUTANG USAHA` typed as `equity` → `liability` (default payable must be a liability; purchase posting needs it).
3. Makes the two new accounts the default sales revenue / purchase expense (clears the flag elsewhere).
4. Sets `PIUTANG USAHA` / `HUTANG USAHA` as default receivable / payable **only where the company has none**.
5. Backfills a General Journal entry (Dr Piutang / Cr Pendapatan, `source_module='sales_invoice'`, posted) for every
   **Approved** sales invoice that has none. Invoices that are still Draft are not touched — approving them posts automatically.

Idempotent: re-running inserts/backfills nothing new. Invoices already posted to an older revenue account (e.g.
`PENDAPATAN LAIN-LAIN`) are NOT re-pointed on prod; use the repoint query at the bottom if wanted.
Account codes `401-002-000` / `503-001-000` are a naming choice — change them before running if prod's chart differs.

## DEV (`aluria_dev`) — applied 2026-10-21

Same SQL as below with `aluria` → `aluria_dev`. Also re-pointed the existing sales invoice entry to the new revenue account.

## PROD (`aluria`) — NOT yet applied

Run top to bottom in one session (the temp table is session-scoped). Take a backup / run the preview first.

```sql
INSERT INTO `aluria`.`account_code` (id, company_id, account_code, account_code_name, account_code_name_alias, account_type, is_active, created_by, created_at)
SELECT UUID(), c.company_id, '401-002-000', 'PENDAPATAN PENJUALAN', 'PENDAPATAN PENJUALAN', 'revenue', 1, 'migration', NOW()
FROM (SELECT DISTINCT company_id FROM `aluria`.`account_code` WHERE deleted_at IS NULL) c
WHERE NOT EXISTS (SELECT 1 FROM `aluria`.`account_code` a WHERE a.company_id = c.company_id AND a.account_code = '401-002-000');

INSERT INTO `aluria`.`account_code` (id, company_id, account_code, account_code_name, account_code_name_alias, account_type, is_active, created_by, created_at)
SELECT UUID(), c.company_id, '503-001-000', 'PEMBELIAN', 'PEMBELIAN', 'expense', 1, 'migration', NOW()
FROM (SELECT DISTINCT company_id FROM `aluria`.`account_code` WHERE deleted_at IS NULL) c
WHERE NOT EXISTS (SELECT 1 FROM `aluria`.`account_code` a WHERE a.company_id = c.company_id AND a.account_code = '503-001-000');

UPDATE `aluria`.`account_code` SET account_type = 'liability'
WHERE account_code_name = 'HUTANG USAHA' AND account_type = 'equity' AND deleted_at IS NULL;

UPDATE `aluria`.`account_code` SET is_default_sales_revenue = 0 WHERE is_default_sales_revenue = 1 AND account_code <> '401-002-000';
UPDATE `aluria`.`account_code` SET is_default_sales_revenue = 1 WHERE account_code = '401-002-000' AND deleted_at IS NULL;
UPDATE `aluria`.`account_code` SET is_default_purchase_expense = 0 WHERE is_default_purchase_expense = 1 AND account_code <> '503-001-000';
UPDATE `aluria`.`account_code` SET is_default_purchase_expense = 1 WHERE account_code = '503-001-000' AND deleted_at IS NULL;

UPDATE `aluria`.`account_code` a SET a.is_default_receivable = 1
WHERE a.account_code_name = 'PIUTANG USAHA' AND a.account_type = 'asset' AND a.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM (SELECT company_id FROM `aluria`.`account_code` WHERE is_default_receivable = 1 AND deleted_at IS NULL) d WHERE d.company_id = a.company_id);
UPDATE `aluria`.`account_code` a SET a.is_default_payable = 1
WHERE a.account_code_name = 'HUTANG USAHA' AND a.account_type = 'liability' AND a.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM (SELECT company_id FROM `aluria`.`account_code` WHERE is_default_payable = 1 AND deleted_at IS NULL) d WHERE d.company_id = a.company_id);

CREATE TEMPORARY TABLE `aluria`.`tmp_si_gl` AS
SELECT UUID() AS journal_id, si.id AS sales_invoice_id, si.company_id, DATE(si.approved_at) AS transaction_date,
       COALESCE(si.approved_by, 'migration') AS posted_by,
       (SELECT SUM(i.quantity * i.unit_price * (1 + i.tax / 100)) FROM `aluria`.`sales_invoice_item` i WHERE i.sales_invoice_id = si.id AND i.deleted_at IS NULL) AS total,
       (SELECT a.id FROM `aluria`.`account_code` a WHERE a.company_id = si.company_id AND a.is_default_receivable = 1 AND a.is_active = 1 AND a.deleted_at IS NULL LIMIT 1) AS receivable_id,
       (SELECT a.id FROM `aluria`.`account_code` a WHERE a.company_id = si.company_id AND a.is_default_sales_revenue = 1 AND a.is_active = 1 AND a.deleted_at IS NULL LIMIT 1) AS revenue_id
FROM `aluria`.`sales_invoice` si
JOIN `aluria`.`sales_status` ss ON ss.id = si.status_id AND ss.status_name = 'Approved'
WHERE si.deleted_at IS NULL AND si.approved_at IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `aluria`.`general_journal` gj WHERE gj.source_module = 'sales_invoice' AND gj.source_document_id = si.id AND gj.deleted_at IS NULL);
DELETE FROM `aluria`.`tmp_si_gl` WHERE total IS NULL OR total <= 0 OR receivable_id IS NULL OR revenue_id IS NULL;

INSERT INTO `aluria`.`general_journal` (id, company_id, transaction_date, memo, source_module, source_document_id, transaction_status, created_by, created_at)
SELECT journal_id, company_id, transaction_date, CONCAT('Pengakuan piutang - sales_invoice ', sales_invoice_id), 'sales_invoice', sales_invoice_id, 'posted', posted_by, NOW() FROM `aluria`.`tmp_si_gl`;
INSERT INTO `aluria`.`general_journal_detail` (id, general_journal_id, account_code_id, amount, created_by, created_at)
SELECT UUID(), journal_id, receivable_id, total, posted_by, NOW() FROM `aluria`.`tmp_si_gl`;
INSERT INTO `aluria`.`general_journal_detail` (id, general_journal_id, account_code_id, amount, created_by, created_at)
SELECT UUID(), journal_id, revenue_id, total, posted_by, NOW() FROM `aluria`.`tmp_si_gl`;
DROP TEMPORARY TABLE `aluria`.`tmp_si_gl`;
```

### Preview (run before, read-only)

```sql
-- invoices that will be backfilled
SELECT si.company_id, si.invoice_display_number, si.approved_at
FROM `aluria`.`sales_invoice` si JOIN `aluria`.`sales_status` ss ON ss.id = si.status_id AND ss.status_name = 'Approved'
WHERE si.deleted_at IS NULL AND si.approved_at IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `aluria`.`general_journal` gj WHERE gj.source_module = 'sales_invoice' AND gj.source_document_id = si.id AND gj.deleted_at IS NULL);
-- companies whose HUTANG USAHA is mistyped
SELECT company_id, account_code, account_type FROM `aluria`.`account_code` WHERE account_code_name = 'HUTANG USAHA' AND account_type <> 'liability';
```

## Verification

```sql
SELECT company_id, account_code, account_code_name, is_default_receivable, is_default_payable, is_default_sales_revenue, is_default_purchase_expense
FROM `aluria`.`account_code` WHERE deleted_at IS NULL AND (is_default_receivable + is_default_payable + is_default_sales_revenue + is_default_purchase_expense) > 0
ORDER BY company_id, account_code;  -- expect 4 rows per company
```

## Optional: re-point earlier sales postings from another revenue account

```sql
UPDATE `aluria`.`general_journal_detail` gjd
JOIN `aluria`.`general_journal` gj ON gj.id = gjd.general_journal_id AND gj.source_module = 'sales_invoice'
JOIN `aluria`.`account_code` old_a ON old_a.id = gjd.account_code_id AND old_a.account_type = 'revenue' AND old_a.account_code <> '401-002-000'
JOIN `aluria`.`account_code` new_a ON new_a.company_id = gj.company_id AND new_a.account_code = '401-002-000'
SET gjd.account_code_id = new_a.id;
```
