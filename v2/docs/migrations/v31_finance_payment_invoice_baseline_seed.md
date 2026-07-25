# Aluria Schema Addendum — v31: finance_payment invoice baseline seeding

**No new columns or tables.** This is a code-behavior + data-backfill migration: `finance_payment` (the v2 equivalent of legacy `financeItem`) was never seeded with a baseline row when a purchase/sales invoice was created — unlike legacy, which inserted a `paid_amount=0, due_amount=<invoice total>` row in `purchase/invoice/insertinvoice.php` and `sales/insertsalesinvoice.php` at invoice-creation time. Every outstanding-balance calculation in v2 (`ar-ap-report`, `dashboard`'s AR/AP widgets, `notification/digest.php`, and the new `has_outstanding`/`outstanding_amount` fields on `GET /api/v2/supplier` and `GET /api/v2/customer`) derives outstanding purely from `finance_payment` rows — so an approved invoice that had never received a manual payment was invisible to all of them. This was discovered while building `GET /api/v2/finance-payment/outstanding-invoices`, the v2 replacement for the legacy `showspurchaseinvoice.php`/`showssalesinvoice.php` per-partner outstanding-invoice list used by the A/P and A/R payment-entry screens.

## Code change (already applied, this session)

- New helper: `v2/helpers/finance_payment.php` — `seedFinancePaymentBaseline($conn, $company_id, $invoice_number, $due_amount, $customer_id, $supplier_id, $username)`. Idempotent: if a baseline row (`payment_date IS NULL`) already exists for that `invoice_number`, it refreshes `due_amount` in place instead of inserting a duplicate — covers the reject → revise → re-approve cycle, where the invoice total can change between approvals.
- `v2/purchase/purchase-invoice/index.php` — `approvePurchaseInvoice()` now calls `seedFinancePaymentBaseline()` right after the status flips to `Approved`, with `due_amount` = `SUM(purchase_invoice_item.total)` (new helper `calculatePurchaseInvoiceTotal()`).
- `v2/sales/sales-invoice/index.php` — `approveSalesInvoice()` does the same, reusing the existing `calculateSalesInvoiceTotal()` helper.
- Seeded **on approval, not creation** — deliberately different from legacy. v2 has a Draft/Approved/Rejected workflow legacy purchase invoices didn't; a still-editable Draft invoice shouldn't count as a payable/receivable yet.
- `transaction_status` is set directly to `'posted'` on the baseline row — it's a system-generated $0 marker, not a real disbursement/receipt, so it must never surface in the owner/treasury dual-approval queue (`notification/digest.php`'s `transaction_status IN ('draft', 'partially_approved')` check).

## DEV — backfill already applied

Run against `aluria_dev` on **2026-07-25**, via direct `mysqli` through `v2/connection/db.php` (no `mysql` CLI in this environment, same as prior migrations in this doc series).

```sql
-- ============================================================================
-- v31 DEV — backfill baseline finance_payment rows for approved invoices that
-- predate the seed-on-approve code change above. Idempotent via NOT EXISTS —
-- safe to re-run.
-- ============================================================================

USE `aluria_dev`;

INSERT INTO aluria_dev.finance_payment
    (id, company_id, invoice_number, paid_amount, due_amount, supplier_id, transaction_status, created_by, created_at)
  SELECT
    UUID(), pi.company_id, pi.invoice_display_number, 0,
    (SELECT COALESCE(SUM(total), 0) FROM aluria_dev.purchase_invoice_item WHERE purchase_invoice_id = pi.id AND deleted_at IS NULL),
    pi.supplier_id, 'posted', COALESCE(pi.approved_by, pi.created_by), COALESCE(pi.approved_at, pi.created_at)
  FROM aluria_dev.purchase_invoice pi
  JOIN aluria_dev.purchase_status ps ON ps.id = pi.status_id
  WHERE pi.deleted_at IS NULL AND ps.status_name = 'Approved'
    AND NOT EXISTS (
      SELECT 1 FROM aluria_dev.finance_payment fp
      WHERE fp.company_id = pi.company_id AND fp.invoice_number = pi.invoice_display_number AND fp.deleted_at IS NULL
    );
-- DEV RESULT: 4 rows inserted. 2 of the 4 have no purchase_invoice_item rows at
-- all (SUM = 0), so they backfilled to due_amount = 0 — correct, there is
-- nothing to pay on those.

INSERT INTO aluria_dev.finance_payment
    (id, company_id, invoice_number, paid_amount, due_amount, customer_id, transaction_status, created_by, created_at)
  SELECT
    UUID(), si.company_id, si.invoice_display_number, 0,
    (SELECT COALESCE(SUM(quantity * unit_price * (1 + tax / 100)), 0) FROM aluria_dev.sales_invoice_item WHERE sales_invoice_id = si.id AND deleted_at IS NULL),
    si.customer_id, 'posted', COALESCE(si.approved_by, si.created_by), COALESCE(si.approved_at, si.created_at)
  FROM aluria_dev.sales_invoice si
  JOIN aluria_dev.sales_status ss ON ss.id = si.status_id
  WHERE si.deleted_at IS NULL AND ss.status_name = 'Approved'
    AND NOT EXISTS (
      SELECT 1 FROM aluria_dev.finance_payment fp
      WHERE fp.company_id = si.company_id AND fp.invoice_number = si.invoice_display_number AND fp.deleted_at IS NULL
    );
-- DEV RESULT: 1 row inserted.

-- Verification — expect 0 for both after the inserts above:
SELECT COUNT(*) AS purchase_gap FROM aluria_dev.purchase_invoice pi
  JOIN aluria_dev.purchase_status ps ON ps.id = pi.status_id
  WHERE pi.deleted_at IS NULL AND ps.status_name = 'Approved'
    AND NOT EXISTS (SELECT 1 FROM aluria_dev.finance_payment fp WHERE fp.company_id = pi.company_id AND fp.invoice_number = pi.invoice_display_number AND fp.deleted_at IS NULL);

SELECT COUNT(*) AS sales_gap FROM aluria_dev.sales_invoice si
  JOIN aluria_dev.sales_status ss ON ss.id = si.status_id
  WHERE si.deleted_at IS NULL AND ss.status_name = 'Approved'
    AND NOT EXISTS (SELECT 1 FROM aluria_dev.finance_payment fp WHERE fp.company_id = si.company_id AND fp.invoice_number = si.invoice_display_number AND fp.deleted_at IS NULL);
```

**DEV RESULT:** ran 2026-07-25. Purchase backfill inserted 4 rows, sales backfill inserted 1 row. Both verification counts returned `0` (no approved invoice left without a `finance_payment` row). Re-ran both `INSERT ... SELECT` statements a second time immediately after — `0` rows affected either time, confirming the `NOT EXISTS` guard is correctly idempotent. `finance_payment` total row count: 582 → 587.

---

## PROD — NOT yet applied

**Do not run this against production without Kage's sign-off first** — same review gate as every other schema/data change in this project. Mirrors the DEV block above exactly, scoped to prod schema names.

**Deploy order:** the code change (seed-on-approve) must land in production *before* this backfill matters going forward, but the backfill itself is independent and can run before or after the code deploy — it only touches historical rows for invoices already `Approved` at backfill time; the code change handles everything approved after.

```sql
-- Replace aluria_prod below with the real prod schema name from prod's .env (APP_SCHEMA) before running.

USE `aluria_prod`;

INSERT INTO aluria_prod.finance_payment
    (id, company_id, invoice_number, paid_amount, due_amount, supplier_id, transaction_status, created_by, created_at)
  SELECT
    UUID(), pi.company_id, pi.invoice_display_number, 0,
    (SELECT COALESCE(SUM(total), 0) FROM aluria_prod.purchase_invoice_item WHERE purchase_invoice_id = pi.id AND deleted_at IS NULL),
    pi.supplier_id, 'posted', COALESCE(pi.approved_by, pi.created_by), COALESCE(pi.approved_at, pi.created_at)
  FROM aluria_prod.purchase_invoice pi
  JOIN aluria_prod.purchase_status ps ON ps.id = pi.status_id
  WHERE pi.deleted_at IS NULL AND ps.status_name = 'Approved'
    AND NOT EXISTS (
      SELECT 1 FROM aluria_prod.finance_payment fp
      WHERE fp.company_id = pi.company_id AND fp.invoice_number = pi.invoice_display_number AND fp.deleted_at IS NULL
    );

INSERT INTO aluria_prod.finance_payment
    (id, company_id, invoice_number, paid_amount, due_amount, customer_id, transaction_status, created_by, created_at)
  SELECT
    UUID(), si.company_id, si.invoice_display_number, 0,
    (SELECT COALESCE(SUM(quantity * unit_price * (1 + tax / 100)), 0) FROM aluria_prod.sales_invoice_item WHERE sales_invoice_id = si.id AND deleted_at IS NULL),
    si.customer_id, 'posted', COALESCE(si.approved_by, si.created_by), COALESCE(si.approved_at, si.created_at)
  FROM aluria_prod.sales_invoice si
  JOIN aluria_prod.sales_status ss ON ss.id = si.status_id
  WHERE si.deleted_at IS NULL AND ss.status_name = 'Approved'
    AND NOT EXISTS (
      SELECT 1 FROM aluria_prod.finance_payment fp
      WHERE fp.company_id = si.company_id AND fp.invoice_number = si.invoice_display_number AND fp.deleted_at IS NULL
    );

-- Verification — expect 0 for both:
SELECT COUNT(*) AS purchase_gap FROM aluria_prod.purchase_invoice pi
  JOIN aluria_prod.purchase_status ps ON ps.id = pi.status_id
  WHERE pi.deleted_at IS NULL AND ps.status_name = 'Approved'
    AND NOT EXISTS (SELECT 1 FROM aluria_prod.finance_payment fp WHERE fp.company_id = pi.company_id AND fp.invoice_number = pi.invoice_display_number AND fp.deleted_at IS NULL);

SELECT COUNT(*) AS sales_gap FROM aluria_prod.sales_invoice si
  JOIN aluria_prod.sales_status ss ON ss.id = si.status_id
  WHERE si.deleted_at IS NULL AND ss.status_name = 'Approved'
    AND NOT EXISTS (SELECT 1 FROM aluria_prod.finance_payment fp WHERE fp.company_id = si.company_id AND fp.invoice_number = si.invoice_display_number AND fp.deleted_at IS NULL);
```

---

## Post-migration checklist

- [ ] Get Kage's sign-off, then run the PROD block above.
- [ ] No historical row was backfilled with a guessed value — `due_amount` is always derived from the invoice's own item totals (real source data), never invented. `created_by`/`created_at` are carried over from the invoice's own `approved_by`/`approved_at` (falling back to `created_by`/`created_at` only if the invoice was approved before those approval-tracking columns existed) — not "today" or a system user.
- [ ] Baseline rows are indistinguishable from a real `$0` payment in `GET /api/v2/finance-payment` (list/history view) — they have `payment_date = NULL`, `paid_amount = 0`, no bank/cheque info, and `transaction_status = 'posted'` with no approver names. This mirrors legacy's own behavior (legacy's `showspurchaseinvoice.php` mixed the same kind of baseline row into its payment history) — flagged here so it isn't mistaken for a bug if a frontend developer notices a "payment" row with nothing filled in.
- [ ] No permission keys introduced by this migration.
