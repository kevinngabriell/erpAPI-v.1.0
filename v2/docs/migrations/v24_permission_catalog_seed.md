# Aluria Schema Addendum — v24: permission catalog seed (app_permission + app_role_permission)

**Purpose.** Closes the role-grant gap for the Aluria permission spec (dashboard/sales/purchase/keuangan/warehouse/settings — 273 keys) in `movira_core_dev`/`movira_core_prod`.

**Verified against `movira_core_dev` on 2026-07-23, before this addendum was written to run:**
- All 273 permission keys **already exist** in `app_permission` for Aluria's `app_id` — a prior session had already seeded the catalog. Step 2 below is a confirmed no-op on dev; it stays in for portability to prod (and re-runs safely everywhere via its anti-join guard).
- `app_role` is itself a shared, multi-app catalog (own `app_id` column) — dev has two rows named "Business Owner" and two named "Finance", one pair per app. Every `app_role` lookup in this addendum is scoped by `app_id`, never by `role_name` alone.
- Role grants already exist and roughly match the spec for **Business Owner** (273 — broader than spec's 233, i.e. BO already has blanket access), **Admin Sales** (62), **Admin Purchase** (72), **Finance** (63), **Accounting** (97), and **Manager** (164 of 167). **Gudang, Kepala Gudang, and the role the spec calls "Logistics / Import Coordinator" — which already exists under the name "Logistic" — currently have ZERO grants.** That gap is what Step 3 actually fills.

**Not seeded (Coming Soon / backlog), per the source spec:** `purchase.pr.*`, RFQ keys, `warehouse.qc.*`, `purchase.landed_cost_variance.view`, `dashboard.supplier_scorecard.view`, e-Faktur keys, email-change.

**No `aluria_dev`/`aluria_prod` (APP_SCHEMA) changes in this addendum** — `app_permission`, `app_role`, and `app_role_permission` all live in `CORE_SCHEMA` only.

---

## DEV — already applied

Run against `movira_core_dev` on 2026-07-23, by Claude (this session). Result, from Step 4's verification queries:

| role_name | before | after |
|---|---|---|
| Business Owner | 273 | 273 (no-op — already had everything) |
| Manager | 164 | 168 |
| Accounting | 97 | 97 (no-op) |
| Kepala Gudang | 0 | 72 |
| Admin Purchase | 72 | 72 (no-op) |
| Finance | 63 | 65 |
| Admin Sales | 62 | 62 (no-op) |
| Logistic | 0 | 51 |
| Gudang | 0 | 43 |

`total_permissions` stayed at 278 (Step 2 confirmed a no-op, as expected). Manager and Finance picked up a few grants beyond the round numbers estimated in the header note above — matching counts before this run didn't guarantee identical key *sets*, so the anti-join still found a handful of legitimately missing keys for those two roles. Nothing was removed; this insert is purely additive.

```sql
-- ============================================================================
-- v24 DEV — permission catalog seed (movira_core_dev)
--
-- VERIFIED AGAINST movira_core_dev on 2026-07-23 before writing this block:
-- `app_role` is itself a shared, multi-app catalog (has its own `app_id`
-- column) — every app_role lookup below is scoped by `app_id = @app_id`,
-- never by role_name alone, to avoid touching another product's roles that
-- happen to share a name (dev has two "Business Owner" rows and two
-- "Finance" rows, one pair per app_id).
--
-- Also verified: for Aluria's app_id, "Accounting" already exists, and the
-- role the source spec calls "Logistics / Import Coordinator" already
-- exists under the name "Logistic" — Step 1 targets that existing name
-- rather than creating a differently-named duplicate. app_role_id format
-- for Aluria's own roles is confirmed 'role' + 16 lowercase hex chars.
-- ============================================================================

USE `movira_core_dev`;

SET @app_id := (SELECT app_id FROM movira_core_dev.app_permission WHERE permission_key = 'keuangan.ledger.view' LIMIT 1);

-- ----------------------------------------------------------------------------
-- Step 1: create Accounting / Logistic only if genuinely missing for this
-- app_id. No-op on dev (both already exist there) — kept for portability to
-- prod, where role_name for LG should be verified against
-- `SELECT role_name FROM movira_core_dev.app_role WHERE app_id = @app_id;` first,
-- same as dev, before assuming "Logistic" is the right existing name there too.
-- ----------------------------------------------------------------------------
INSERT INTO movira_core_dev.app_role (app_role_id, role_name, app_id)
SELECT CONCAT('role', LOWER(HEX(RANDOM_BYTES(8)))), 'Accounting', @app_id
WHERE NOT EXISTS (SELECT 1 FROM movira_core_dev.app_role WHERE role_name = 'Accounting' AND app_id = @app_id);

INSERT INTO movira_core_dev.app_role (app_role_id, role_name, app_id)
SELECT CONCAT('role', LOWER(HEX(RANDOM_BYTES(8)))), 'Logistic', @app_id
WHERE NOT EXISTS (SELECT 1 FROM movira_core_dev.app_role WHERE role_name = 'Logistic' AND app_id = @app_id);

-- ----------------------------------------------------------------------------
-- Step 2: permission catalog. 273 keys across Dashboard, Sales,
-- Purchase, Keuangan, Warehouse, Settings. Idempotent via anti-join on
-- (app_id, permission_key) — does not assume a UNIQUE constraint exists.
-- Coming-Soon keys (purchase.pr.*, RFQ, warehouse.qc.*,
-- purchase.landed_cost_variance.view, dashboard.supplier_scorecard.view,
-- e-Faktur, email-change) are deliberately NOT included.
--
-- VERIFIED on dev: all 273 keys already exist (this step is a
-- confirmed no-op there) — a prior session appears to have already run an
-- equivalent seed. Only the role grants in Step 3 have a real gap.
-- ----------------------------------------------------------------------------

INSERT INTO movira_core_dev.app_permission (permission_id, app_id, permission_key, module, label, description)
SELECT CONCAT('perm', LOWER(HEX(RANDOM_BYTES(8)))), @app_id, t.permission_key, t.module, t.label, t.description
FROM (
  SELECT 'dashboard.revenue_trend.view' AS permission_key, 'Dashboard' AS module, 'Revenue Trend Widget' AS label, 'Revenue Trend Widget (dashboard.revenue_trend.view)' AS description
  UNION ALL SELECT 'dashboard.profit_summary.view' AS permission_key, 'Dashboard' AS module, 'Profit Summary Widget' AS label, 'Profit Summary Widget (dashboard.profit_summary.view)' AS description
  UNION ALL SELECT 'dashboard.cash_position.view' AS permission_key, 'Dashboard' AS module, 'Cash Position Widget' AS label, 'Cash Position Widget (dashboard.cash_position.view)' AS description
  UNION ALL SELECT 'dashboard.ar_ap_summary.view' AS permission_key, 'Dashboard' AS module, 'AR/AP Summary Widget' AS label, 'AR/AP Summary Widget (dashboard.ar_ap_summary.view)' AS description
  UNION ALL SELECT 'dashboard.pending_approvals.view' AS permission_key, 'Dashboard' AS module, 'Pending Approvals Widget' AS label, 'Pending Approvals Widget (dashboard.pending_approvals.view)' AS description
  UNION ALL SELECT 'dashboard.top_partners.view' AS permission_key, 'Dashboard' AS module, 'Top Partners Widget' AS label, 'Top Partners Widget (dashboard.top_partners.view)' AS description
  UNION ALL SELECT 'dashboard.subscription_status.view' AS permission_key, 'Dashboard' AS module, 'Subscription Status Widget' AS label, 'Subscription Status Widget (dashboard.subscription_status.view)' AS description
  UNION ALL SELECT 'dashboard.pending_users.view' AS permission_key, 'Dashboard' AS module, 'Pending Users Widget' AS label, 'Pending Users Widget (dashboard.pending_users.view)' AS description
  UNION ALL SELECT 'dashboard.exceptions.view' AS permission_key, 'Dashboard' AS module, 'Exceptions Widget' AS label, 'Exceptions Widget (dashboard.exceptions.view)' AS description
  UNION ALL SELECT 'dashboard.approval_queue.view' AS permission_key, 'Dashboard' AS module, 'Approval Queue Widget' AS label, 'Approval Queue Widget (dashboard.approval_queue.view)' AS description
  UNION ALL SELECT 'dashboard.dept_comparison.view' AS permission_key, 'Dashboard' AS module, 'Department Comparison Widget' AS label, 'Department Comparison Widget (dashboard.dept_comparison.view)' AS description
  UNION ALL SELECT 'dashboard.activity_log.view' AS permission_key, 'Dashboard' AS module, 'Activity Log Widget' AS label, 'Activity Log Widget (dashboard.activity_log.view)' AS description
  UNION ALL SELECT 'dashboard.buku_kas_snapshot.view' AS permission_key, 'Dashboard' AS module, 'Buku Kas Snapshot Widget' AS label, 'Buku Kas Snapshot Widget (dashboard.buku_kas_snapshot.view)' AS description
  UNION ALL SELECT 'dashboard.bank_balances.view' AS permission_key, 'Dashboard' AS module, 'Bank Balances Widget' AS label, 'Bank Balances Widget (dashboard.bank_balances.view)' AS description
  UNION ALL SELECT 'dashboard.payment_verification.view' AS permission_key, 'Dashboard' AS module, 'Payment Verification Widget' AS label, 'Payment Verification Widget (dashboard.payment_verification.view)' AS description
  UNION ALL SELECT 'dashboard.finance_entries_today.view' AS permission_key, 'Dashboard' AS module, 'Today''s Finance Entries Widget' AS label, 'Today''s Finance Entries Widget (dashboard.finance_entries_today.view)' AS description
  UNION ALL SELECT 'dashboard.ar_aging.view' AS permission_key, 'Dashboard' AS module, 'AR Aging Widget' AS label, 'AR Aging Widget (dashboard.ar_aging.view)' AS description
  UNION ALL SELECT 'dashboard.ap_aging.view' AS permission_key, 'Dashboard' AS module, 'AP Aging Widget' AS label, 'AP Aging Widget (dashboard.ap_aging.view)' AS description
  UNION ALL SELECT 'dashboard.tax_due.view' AS permission_key, 'Dashboard' AS module, 'Tax Due Widget' AS label, 'Tax Due Widget (dashboard.tax_due.view)' AS description
  UNION ALL SELECT 'dashboard.pnl_snapshot.view' AS permission_key, 'Dashboard' AS module, 'P&L Snapshot Widget' AS label, 'P&L Snapshot Widget (dashboard.pnl_snapshot.view)' AS description
  UNION ALL SELECT 'dashboard.neraca_snapshot.view' AS permission_key, 'Dashboard' AS module, 'Neraca Snapshot Widget' AS label, 'Neraca Snapshot Widget (dashboard.neraca_snapshot.view)' AS description
  UNION ALL SELECT 'dashboard.buku_besar_summary.view' AS permission_key, 'Dashboard' AS module, 'Buku Besar Summary Widget' AS label, 'Buku Besar Summary Widget (dashboard.buku_besar_summary.view)' AS description
  UNION ALL SELECT 'dashboard.open_pos.view' AS permission_key, 'Dashboard' AS module, 'Open POs Widget' AS label, 'Open POs Widget (dashboard.open_pos.view)' AS description
  UNION ALL SELECT 'dashboard.po_pending_approval.view' AS permission_key, 'Dashboard' AS module, 'PO Pending Approval Widget' AS label, 'PO Pending Approval Widget (dashboard.po_pending_approval.view)' AS description
  UNION ALL SELECT 'dashboard.incoming_eta.view' AS permission_key, 'Dashboard' AS module, 'Incoming ETA Widget' AS label, 'Incoming ETA Widget (dashboard.incoming_eta.view)' AS description
  UNION ALL SELECT 'dashboard.invoice_matching.view' AS permission_key, 'Dashboard' AS module, 'Invoice Matching Widget' AS label, 'Invoice Matching Widget (dashboard.invoice_matching.view)' AS description
  UNION ALL SELECT 'dashboard.open_sos.view' AS permission_key, 'Dashboard' AS module, 'Open SOs Widget' AS label, 'Open SOs Widget (dashboard.open_sos.view)' AS description
  UNION ALL SELECT 'dashboard.so_pending_approval.view' AS permission_key, 'Dashboard' AS module, 'SO Pending Approval Widget' AS label, 'SO Pending Approval Widget (dashboard.so_pending_approval.view)' AS description
  UNION ALL SELECT 'dashboard.delivery_status.view' AS permission_key, 'Dashboard' AS module, 'Delivery Status Widget' AS label, 'Delivery Status Widget (dashboard.delivery_status.view)' AS description
  UNION ALL SELECT 'dashboard.sppb_pending.view' AS permission_key, 'Dashboard' AS module, 'Pending SPPB Widget' AS label, 'Pending SPPB Widget (dashboard.sppb_pending.view)' AS description
  UNION ALL SELECT 'dashboard.order_backlog.view' AS permission_key, 'Dashboard' AS module, 'Order Backlog Widget' AS label, 'Order Backlog Widget (dashboard.order_backlog.view)' AS description
  UNION ALL SELECT 'dashboard.top_customers_mtd.view' AS permission_key, 'Dashboard' AS module, 'Top Customers (MTD) Widget' AS label, 'Top Customers (MTD) Widget (dashboard.top_customers_mtd.view)' AS description
  UNION ALL SELECT 'dashboard.shipments_in_transit.view' AS permission_key, 'Dashboard' AS module, 'Shipments in Transit Widget' AS label, 'Shipments in Transit Widget (dashboard.shipments_in_transit.view)' AS description
  UNION ALL SELECT 'dashboard.sppb_tracker.view' AS permission_key, 'Dashboard' AS module, 'SPPB Tracker Widget' AS label, 'SPPB Tracker Widget (dashboard.sppb_tracker.view)' AS description
  UNION ALL SELECT 'dashboard.container_lookup.view' AS permission_key, 'Dashboard' AS module, 'Container Lookup Widget' AS label, 'Container Lookup Widget (dashboard.container_lookup.view)' AS description
  UNION ALL SELECT 'dashboard.at_risk_shipments.view' AS permission_key, 'Dashboard' AS module, 'At-Risk Shipments Widget' AS label, 'At-Risk Shipments Widget (dashboard.at_risk_shipments.view)' AS description
  UNION ALL SELECT 'dashboard.clearance_mode.view' AS permission_key, 'Dashboard' AS module, 'Clearance Mode Widget' AS label, 'Clearance Mode Widget (dashboard.clearance_mode.view)' AS description
  UNION ALL SELECT 'dashboard.stock_movements_today.view' AS permission_key, 'Dashboard' AS module, 'Today''s Stock Movements Widget' AS label, 'Today''s Stock Movements Widget (dashboard.stock_movements_today.view)' AS description
  UNION ALL SELECT 'dashboard.pending_receive.view' AS permission_key, 'Dashboard' AS module, 'Pending Receive Widget' AS label, 'Pending Receive Widget (dashboard.pending_receive.view)' AS description
  UNION ALL SELECT 'dashboard.pending_outbound.view' AS permission_key, 'Dashboard' AS module, 'Pending Outbound Widget' AS label, 'Pending Outbound Widget (dashboard.pending_outbound.view)' AS description
  UNION ALL SELECT 'dashboard.low_stock.view' AS permission_key, 'Dashboard' AS module, 'Low Stock Widget' AS label, 'Low Stock Widget (dashboard.low_stock.view)' AS description
  UNION ALL SELECT 'dashboard.stock_by_location.view' AS permission_key, 'Dashboard' AS module, 'Stock by Location Widget' AS label, 'Stock by Location Widget (dashboard.stock_by_location.view)' AS description
  UNION ALL SELECT 'dashboard.adjustment_approvals.view' AS permission_key, 'Dashboard' AS module, 'Adjustment Approvals Widget' AS label, 'Adjustment Approvals Widget (dashboard.adjustment_approvals.view)' AS description
  UNION ALL SELECT 'dashboard.discrepancy_flags.view' AS permission_key, 'Dashboard' AS module, 'Discrepancy Flags Widget' AS label, 'Discrepancy Flags Widget (dashboard.discrepancy_flags.view)' AS description
  UNION ALL SELECT 'dashboard.warehouse_monthly_summary.view' AS permission_key, 'Dashboard' AS module, 'Warehouse Monthly Summary Widget' AS label, 'Warehouse Monthly Summary Widget (dashboard.warehouse_monthly_summary.view)' AS description
  UNION ALL SELECT 'sales.pipeline_funnel.view' AS permission_key, 'Sales' AS module, 'View Pipeline Funnel' AS label, 'View Pipeline Funnel (sales.pipeline_funnel.view)' AS description
  UNION ALL SELECT 'sales.so_approval_queue.view' AS permission_key, 'Sales' AS module, 'View SO Approval Queue' AS label, 'View SO Approval Queue (sales.so_approval_queue.view)' AS description
  UNION ALL SELECT 'sales.so_uninvoiced.view' AS permission_key, 'Sales' AS module, 'View Uninvoiced SO' AS label, 'View Uninvoiced SO (sales.so_uninvoiced.view)' AS description
  UNION ALL SELECT 'sales.deliveries_due.view' AS permission_key, 'Sales' AS module, 'View Deliveries Due' AS label, 'View Deliveries Due (sales.deliveries_due.view)' AS description
  UNION ALL SELECT 'sales.overdue_invoices.view' AS permission_key, 'Sales' AS module, 'View Overdue Invoices' AS label, 'View Overdue Invoices (sales.overdue_invoices.view)' AS description
  UNION ALL SELECT 'sales.order_backlog_value.view' AS permission_key, 'Sales' AS module, 'View Order Backlog Value' AS label, 'View Order Backlog Value (sales.order_backlog_value.view)' AS description
  UNION ALL SELECT 'sales.margin_snapshot.view' AS permission_key, 'Sales' AS module, 'View Margin Snapshot' AS label, 'View Margin Snapshot (sales.margin_snapshot.view)' AS description
  UNION ALL SELECT 'sales.top_customers.view' AS permission_key, 'Sales' AS module, 'View Top Customers' AS label, 'View Top Customers (sales.top_customers.view)' AS description
  UNION ALL SELECT 'sales.so.view' AS permission_key, 'Sales' AS module, 'View Sales Order' AS label, 'View Sales Order (sales.so.view)' AS description
  UNION ALL SELECT 'sales.so.create' AS permission_key, 'Sales' AS module, 'Create Sales Order' AS label, 'Create Sales Order (sales.so.create)' AS description
  UNION ALL SELECT 'sales.so.update' AS permission_key, 'Sales' AS module, 'Update Sales Order' AS label, 'Update Sales Order (sales.so.update)' AS description
  UNION ALL SELECT 'sales.so.delete' AS permission_key, 'Sales' AS module, 'Delete Sales Order' AS label, 'Delete Sales Order (sales.so.delete)' AS description
  UNION ALL SELECT 'sales.so.approve' AS permission_key, 'Sales' AS module, 'Approve Sales Order' AS label, 'Approve Sales Order (sales.so.approve)' AS description
  UNION ALL SELECT 'sales.so.reject' AS permission_key, 'Sales' AS module, 'Reject Sales Order' AS label, 'Reject Sales Order (sales.so.reject)' AS description
  UNION ALL SELECT 'sales.so.revise' AS permission_key, 'Sales' AS module, 'Revise Sales Order' AS label, 'Revise Sales Order (sales.so.revise)' AS description
  UNION ALL SELECT 'sales.so.export' AS permission_key, 'Sales' AS module, 'Export Sales Order' AS label, 'Export Sales Order (sales.so.export)' AS description
  UNION ALL SELECT 'sales.sppb.view' AS permission_key, 'Sales' AS module, 'View SPPB' AS label, 'View SPPB (sales.sppb.view)' AS description
  UNION ALL SELECT 'sales.sppb.create' AS permission_key, 'Sales' AS module, 'Create SPPB' AS label, 'Create SPPB (sales.sppb.create)' AS description
  UNION ALL SELECT 'sales.sppb.approve' AS permission_key, 'Sales' AS module, 'Approve SPPB' AS label, 'Approve SPPB (sales.sppb.approve)' AS description
  UNION ALL SELECT 'sales.sppb.reject' AS permission_key, 'Sales' AS module, 'Reject SPPB' AS label, 'Reject SPPB (sales.sppb.reject)' AS description
  UNION ALL SELECT 'sales.sppb.revise' AS permission_key, 'Sales' AS module, 'Revise SPPB' AS label, 'Revise SPPB (sales.sppb.revise)' AS description
  UNION ALL SELECT 'sales.sppb.export' AS permission_key, 'Sales' AS module, 'Export SPPB' AS label, 'Export SPPB (sales.sppb.export)' AS description
  UNION ALL SELECT 'sales.do.view' AS permission_key, 'Sales' AS module, 'View Delivery Order' AS label, 'View Delivery Order (sales.do.view)' AS description
  UNION ALL SELECT 'sales.do.create' AS permission_key, 'Sales' AS module, 'Create Delivery Order' AS label, 'Create Delivery Order (sales.do.create)' AS description
  UNION ALL SELECT 'sales.do.approve' AS permission_key, 'Sales' AS module, 'Approve Delivery Order' AS label, 'Approve Delivery Order (sales.do.approve)' AS description
  UNION ALL SELECT 'sales.do.reject' AS permission_key, 'Sales' AS module, 'Reject Delivery Order' AS label, 'Reject Delivery Order (sales.do.reject)' AS description
  UNION ALL SELECT 'sales.do.revise' AS permission_key, 'Sales' AS module, 'Revise Delivery Order' AS label, 'Revise Delivery Order (sales.do.revise)' AS description
  UNION ALL SELECT 'sales.do.export' AS permission_key, 'Sales' AS module, 'Export Delivery Order' AS label, 'Export Delivery Order (sales.do.export)' AS description
  UNION ALL SELECT 'sales.invoice.view' AS permission_key, 'Sales' AS module, 'View Invoice' AS label, 'View Invoice (sales.invoice.view)' AS description
  UNION ALL SELECT 'sales.invoice.create' AS permission_key, 'Sales' AS module, 'Create Invoice' AS label, 'Create Invoice (sales.invoice.create)' AS description
  UNION ALL SELECT 'sales.invoice.approve' AS permission_key, 'Sales' AS module, 'Approve Invoice' AS label, 'Approve Invoice (sales.invoice.approve)' AS description
  UNION ALL SELECT 'sales.invoice.reject' AS permission_key, 'Sales' AS module, 'Reject Invoice' AS label, 'Reject Invoice (sales.invoice.reject)' AS description
  UNION ALL SELECT 'sales.invoice.export' AS permission_key, 'Sales' AS module, 'Export Invoice' AS label, 'Export Invoice (sales.invoice.export)' AS description
  UNION ALL SELECT 'sales.profit.view' AS permission_key, 'Sales' AS module, 'View Sales Profit' AS label, 'View Sales Profit (sales.profit.view)' AS description
  UNION ALL SELECT 'sales.profit.create' AS permission_key, 'Sales' AS module, 'Create Sales Profit' AS label, 'Create Sales Profit (sales.profit.create)' AS description
  UNION ALL SELECT 'sales.profit.revise' AS permission_key, 'Sales' AS module, 'Revise Sales Profit' AS label, 'Revise Sales Profit (sales.profit.revise)' AS description
  UNION ALL SELECT 'sales.profit.approve' AS permission_key, 'Sales' AS module, 'Approve Sales Profit' AS label, 'Approve Sales Profit (sales.profit.approve)' AS description
  UNION ALL SELECT 'sales.profit.reject' AS permission_key, 'Sales' AS module, 'Reject Sales Profit' AS label, 'Reject Sales Profit (sales.profit.reject)' AS description
  UNION ALL SELECT 'sales.profit.export' AS permission_key, 'Sales' AS module, 'Export Sales Profit' AS label, 'Export Sales Profit (sales.profit.export)' AS description
  UNION ALL SELECT 'purchase.pipeline_funnel.view' AS permission_key, 'Purchase' AS module, 'View Pipeline Funnel' AS label, 'View Pipeline Funnel (purchase.pipeline_funnel.view)' AS description
  UNION ALL SELECT 'purchase.po_approval_queue.view' AS permission_key, 'Purchase' AS module, 'View PO Approval Queue' AS label, 'View PO Approval Queue (purchase.po_approval_queue.view)' AS description
  UNION ALL SELECT 'purchase.po_uninvoiced.view' AS permission_key, 'Purchase' AS module, 'View Uninvoiced PO' AS label, 'View Uninvoiced PO (purchase.po_uninvoiced.view)' AS description
  UNION ALL SELECT 'purchase.incoming_eta.view' AS permission_key, 'Purchase' AS module, 'View Incoming ETA' AS label, 'View Incoming ETA (purchase.incoming_eta.view)' AS description
  UNION ALL SELECT 'purchase.gr_pending.view' AS permission_key, 'Purchase' AS module, 'View Pending Goods Receipt' AS label, 'View Pending Goods Receipt (purchase.gr_pending.view)' AS description
  UNION ALL SELECT 'purchase.ap_due.view' AS permission_key, 'Purchase' AS module, 'View AP Due' AS label, 'View AP Due (purchase.ap_due.view)' AS description
  UNION ALL SELECT 'purchase.top_suppliers.view' AS permission_key, 'Purchase' AS module, 'View Top Suppliers' AS label, 'View Top Suppliers (purchase.top_suppliers.view)' AS description
  UNION ALL SELECT 'purchase.po_local.view' AS permission_key, 'Purchase' AS module, 'View Local PO' AS label, 'View Local PO (purchase.po_local.view)' AS description
  UNION ALL SELECT 'purchase.po_local.create' AS permission_key, 'Purchase' AS module, 'Create Local PO' AS label, 'Create Local PO (purchase.po_local.create)' AS description
  UNION ALL SELECT 'purchase.po_local.update' AS permission_key, 'Purchase' AS module, 'Update Local PO' AS label, 'Update Local PO (purchase.po_local.update)' AS description
  UNION ALL SELECT 'purchase.po_local.delete' AS permission_key, 'Purchase' AS module, 'Delete Local PO' AS label, 'Delete Local PO (purchase.po_local.delete)' AS description
  UNION ALL SELECT 'purchase.po_local.approve' AS permission_key, 'Purchase' AS module, 'Approve Local PO' AS label, 'Approve Local PO (purchase.po_local.approve)' AS description
  UNION ALL SELECT 'purchase.po_local.reject' AS permission_key, 'Purchase' AS module, 'Reject Local PO' AS label, 'Reject Local PO (purchase.po_local.reject)' AS description
  UNION ALL SELECT 'purchase.po_local.revise' AS permission_key, 'Purchase' AS module, 'Revise Local PO' AS label, 'Revise Local PO (purchase.po_local.revise)' AS description
  UNION ALL SELECT 'purchase.po_local.export' AS permission_key, 'Purchase' AS module, 'Export Local PO' AS label, 'Export Local PO (purchase.po_local.export)' AS description
  UNION ALL SELECT 'purchase.po_import.view' AS permission_key, 'Purchase' AS module, 'View Import PO' AS label, 'View Import PO (purchase.po_import.view)' AS description
  UNION ALL SELECT 'purchase.po_import.create' AS permission_key, 'Purchase' AS module, 'Create Import PO' AS label, 'Create Import PO (purchase.po_import.create)' AS description
  UNION ALL SELECT 'purchase.po_import.update' AS permission_key, 'Purchase' AS module, 'Update Import PO' AS label, 'Update Import PO (purchase.po_import.update)' AS description
  UNION ALL SELECT 'purchase.po_import.delete' AS permission_key, 'Purchase' AS module, 'Delete Import PO' AS label, 'Delete Import PO (purchase.po_import.delete)' AS description
  UNION ALL SELECT 'purchase.po_import.milestone' AS permission_key, 'Purchase' AS module, 'Update Milestone Import PO' AS label, 'Update Milestone Import PO (purchase.po_import.milestone)' AS description
  UNION ALL SELECT 'purchase.po_import.approve' AS permission_key, 'Purchase' AS module, 'Approve Import PO' AS label, 'Approve Import PO (purchase.po_import.approve)' AS description
  UNION ALL SELECT 'purchase.po_import.reject' AS permission_key, 'Purchase' AS module, 'Reject Import PO' AS label, 'Reject Import PO (purchase.po_import.reject)' AS description
  UNION ALL SELECT 'purchase.po_import.revise' AS permission_key, 'Purchase' AS module, 'Revise Import PO' AS label, 'Revise Import PO (purchase.po_import.revise)' AS description
  UNION ALL SELECT 'purchase.po_import.export' AS permission_key, 'Purchase' AS module, 'Export Import PO' AS label, 'Export Import PO (purchase.po_import.export)' AS description
  UNION ALL SELECT 'purchase.gr.view' AS permission_key, 'Purchase' AS module, 'View Goods Receipt' AS label, 'View Goods Receipt (purchase.gr.view)' AS description
  UNION ALL SELECT 'purchase.gr.create' AS permission_key, 'Purchase' AS module, 'Create Goods Receipt' AS label, 'Create Goods Receipt (purchase.gr.create)' AS description
  UNION ALL SELECT 'purchase.gr.approve' AS permission_key, 'Purchase' AS module, 'Approve Goods Receipt' AS label, 'Approve Goods Receipt (purchase.gr.approve)' AS description
  UNION ALL SELECT 'purchase.gr.reject' AS permission_key, 'Purchase' AS module, 'Reject Goods Receipt' AS label, 'Reject Goods Receipt (purchase.gr.reject)' AS description
  UNION ALL SELECT 'purchase.gr.export' AS permission_key, 'Purchase' AS module, 'Export Goods Receipt' AS label, 'Export Goods Receipt (purchase.gr.export)' AS description
  UNION ALL SELECT 'purchase.invoice.view' AS permission_key, 'Purchase' AS module, 'View Invoice' AS label, 'View Invoice (purchase.invoice.view)' AS description
  UNION ALL SELECT 'purchase.invoice.create' AS permission_key, 'Purchase' AS module, 'Create Invoice' AS label, 'Create Invoice (purchase.invoice.create)' AS description
  UNION ALL SELECT 'purchase.invoice.approve' AS permission_key, 'Purchase' AS module, 'Approve Invoice' AS label, 'Approve Invoice (purchase.invoice.approve)' AS description
  UNION ALL SELECT 'purchase.invoice.reject' AS permission_key, 'Purchase' AS module, 'Reject Invoice' AS label, 'Reject Invoice (purchase.invoice.reject)' AS description
  UNION ALL SELECT 'purchase.invoice.export' AS permission_key, 'Purchase' AS module, 'Export Invoice' AS label, 'Export Invoice (purchase.invoice.export)' AS description
  UNION ALL SELECT 'keuangan.cash_position.view' AS permission_key, 'Keuangan' AS module, 'View Cash Position' AS label, 'View Cash Position (keuangan.cash_position.view)' AS description
  UNION ALL SELECT 'keuangan.entries_today.view' AS permission_key, 'Keuangan' AS module, 'View Today''s Entries' AS label, 'View Today''s Entries (keuangan.entries_today.view)' AS description
  UNION ALL SELECT 'keuangan.payment_verification.view' AS permission_key, 'Keuangan' AS module, 'View Payment Verification' AS label, 'View Payment Verification (keuangan.payment_verification.view)' AS description
  UNION ALL SELECT 'keuangan.ar_outstanding.view' AS permission_key, 'Keuangan' AS module, 'View AR Outstanding' AS label, 'View AR Outstanding (keuangan.ar_outstanding.view)' AS description
  UNION ALL SELECT 'keuangan.ap_outstanding.view' AS permission_key, 'Keuangan' AS module, 'View AP Outstanding' AS label, 'View AP Outstanding (keuangan.ap_outstanding.view)' AS description
  UNION ALL SELECT 'keuangan.installments_due.view' AS permission_key, 'Keuangan' AS module, 'View Installments Due' AS label, 'View Installments Due (keuangan.installments_due.view)' AS description
  UNION ALL SELECT 'keuangan.pnl_snapshot.view' AS permission_key, 'Keuangan' AS module, 'View P&L Snapshot' AS label, 'View P&L Snapshot (keuangan.pnl_snapshot.view)' AS description
  UNION ALL SELECT 'keuangan.neraca_snapshot.view' AS permission_key, 'Keuangan' AS module, 'View Neraca Snapshot' AS label, 'View Neraca Snapshot (keuangan.neraca_snapshot.view)' AS description
  UNION ALL SELECT 'keuangan.hpp_snapshot.view' AS permission_key, 'Keuangan' AS module, 'View COGS Snapshot' AS label, 'View COGS Snapshot (keuangan.hpp_snapshot.view)' AS description
  UNION ALL SELECT 'keuangan.faktur_pajak_pending.view' AS permission_key, 'Keuangan' AS module, 'View Pending Faktur Pajak' AS label, 'View Pending Faktur Pajak (keuangan.faktur_pajak_pending.view)' AS description
  UNION ALL SELECT 'keuangan.ledger.view' AS permission_key, 'Keuangan' AS module, 'View General Ledger' AS label, 'View General Ledger (keuangan.ledger.view)' AS description
  UNION ALL SELECT 'keuangan.ledger.create' AS permission_key, 'Keuangan' AS module, 'Create General Ledger' AS label, 'Create General Ledger (keuangan.ledger.create)' AS description
  UNION ALL SELECT 'keuangan.ledger.reverse' AS permission_key, 'Keuangan' AS module, 'Reverse General Ledger' AS label, 'Reverse General Ledger (keuangan.ledger.reverse)' AS description
  UNION ALL SELECT 'keuangan.ledger.export' AS permission_key, 'Keuangan' AS module, 'Export General Ledger' AS label, 'Export General Ledger (keuangan.ledger.export)' AS description
  UNION ALL SELECT 'keuangan.ar.view' AS permission_key, 'Keuangan' AS module, 'View Accounts Receivable' AS label, 'View Accounts Receivable (keuangan.ar.view)' AS description
  UNION ALL SELECT 'keuangan.ar.create' AS permission_key, 'Keuangan' AS module, 'Create Accounts Receivable' AS label, 'Create Accounts Receivable (keuangan.ar.create)' AS description
  UNION ALL SELECT 'keuangan.ar.update' AS permission_key, 'Keuangan' AS module, 'Update Accounts Receivable' AS label, 'Update Accounts Receivable (keuangan.ar.update)' AS description
  UNION ALL SELECT 'keuangan.ar.approve' AS permission_key, 'Keuangan' AS module, 'Approve Accounts Receivable' AS label, 'Approve Accounts Receivable (keuangan.ar.approve)' AS description
  UNION ALL SELECT 'keuangan.ar.reject' AS permission_key, 'Keuangan' AS module, 'Reject Accounts Receivable' AS label, 'Reject Accounts Receivable (keuangan.ar.reject)' AS description
  UNION ALL SELECT 'keuangan.ar.record_payment' AS permission_key, 'Keuangan' AS module, 'Record Payment Accounts Receivable' AS label, 'Record Payment Accounts Receivable (keuangan.ar.record_payment)' AS description
  UNION ALL SELECT 'keuangan.ar.export' AS permission_key, 'Keuangan' AS module, 'Export Accounts Receivable' AS label, 'Export Accounts Receivable (keuangan.ar.export)' AS description
  UNION ALL SELECT 'keuangan.ap.view' AS permission_key, 'Keuangan' AS module, 'View Accounts Payable' AS label, 'View Accounts Payable (keuangan.ap.view)' AS description
  UNION ALL SELECT 'keuangan.ap.create' AS permission_key, 'Keuangan' AS module, 'Create Accounts Payable' AS label, 'Create Accounts Payable (keuangan.ap.create)' AS description
  UNION ALL SELECT 'keuangan.ap.update' AS permission_key, 'Keuangan' AS module, 'Update Accounts Payable' AS label, 'Update Accounts Payable (keuangan.ap.update)' AS description
  UNION ALL SELECT 'keuangan.ap.approve' AS permission_key, 'Keuangan' AS module, 'Approve Accounts Payable' AS label, 'Approve Accounts Payable (keuangan.ap.approve)' AS description
  UNION ALL SELECT 'keuangan.ap.reject' AS permission_key, 'Keuangan' AS module, 'Reject Accounts Payable' AS label, 'Reject Accounts Payable (keuangan.ap.reject)' AS description
  UNION ALL SELECT 'keuangan.ap.record_payment' AS permission_key, 'Keuangan' AS module, 'Record Payment Accounts Payable' AS label, 'Record Payment Accounts Payable (keuangan.ap.record_payment)' AS description
  UNION ALL SELECT 'keuangan.ap.export' AS permission_key, 'Keuangan' AS module, 'Export Accounts Payable' AS label, 'Export Accounts Payable (keuangan.ap.export)' AS description
  UNION ALL SELECT 'keuangan.verification.view' AS permission_key, 'Keuangan' AS module, 'View Payment Verification' AS label, 'View Payment Verification (keuangan.verification.view)' AS description
  UNION ALL SELECT 'keuangan.verification.verify' AS permission_key, 'Keuangan' AS module, 'Verify Payment Verification' AS label, 'Verify Payment Verification (keuangan.verification.verify)' AS description
  UNION ALL SELECT 'keuangan.verification.reject' AS permission_key, 'Keuangan' AS module, 'Reject Payment Verification' AS label, 'Reject Payment Verification (keuangan.verification.reject)' AS description
  UNION ALL SELECT 'keuangan.report_bukukas.view' AS permission_key, 'Keuangan' AS module, 'View Buku Kas Report' AS label, 'View Buku Kas Report (keuangan.report_bukukas.view)' AS description
  UNION ALL SELECT 'keuangan.report_bukukas.export' AS permission_key, 'Keuangan' AS module, 'Export Buku Kas Report' AS label, 'Export Buku Kas Report (keuangan.report_bukukas.export)' AS description
  UNION ALL SELECT 'keuangan.report_bukubesar.view' AS permission_key, 'Keuangan' AS module, 'View Buku Besar Report' AS label, 'View Buku Besar Report (keuangan.report_bukubesar.view)' AS description
  UNION ALL SELECT 'keuangan.report_bukubesar.export' AS permission_key, 'Keuangan' AS module, 'Export Buku Besar Report' AS label, 'Export Buku Besar Report (keuangan.report_bukubesar.export)' AS description
  UNION ALL SELECT 'keuangan.report_neraca.view' AS permission_key, 'Keuangan' AS module, 'View Neraca Report' AS label, 'View Neraca Report (keuangan.report_neraca.view)' AS description
  UNION ALL SELECT 'keuangan.report_neraca.export' AS permission_key, 'Keuangan' AS module, 'Export Neraca Report' AS label, 'Export Neraca Report (keuangan.report_neraca.export)' AS description
  UNION ALL SELECT 'keuangan.report_labarugi.view' AS permission_key, 'Keuangan' AS module, 'View Laba Rugi Report' AS label, 'View Laba Rugi Report (keuangan.report_labarugi.view)' AS description
  UNION ALL SELECT 'keuangan.report_labarugi.export' AS permission_key, 'Keuangan' AS module, 'Export Laba Rugi Report' AS label, 'Export Laba Rugi Report (keuangan.report_labarugi.export)' AS description
  UNION ALL SELECT 'keuangan.report_hpp.view' AS permission_key, 'Keuangan' AS module, 'View COGS Report' AS label, 'View COGS Report (keuangan.report_hpp.view)' AS description
  UNION ALL SELECT 'keuangan.report_hpp.export' AS permission_key, 'Keuangan' AS module, 'Export COGS Report' AS label, 'Export COGS Report (keuangan.report_hpp.export)' AS description
  UNION ALL SELECT 'keuangan.report_omset.view' AS permission_key, 'Keuangan' AS module, 'View Omset Report' AS label, 'View Omset Report (keuangan.report_omset.view)' AS description
  UNION ALL SELECT 'keuangan.report_omset.export' AS permission_key, 'Keuangan' AS module, 'Export Omset Report' AS label, 'Export Omset Report (keuangan.report_omset.export)' AS description
  UNION ALL SELECT 'keuangan.report_aging.view' AS permission_key, 'Keuangan' AS module, 'View Aging Report' AS label, 'View Aging Report (keuangan.report_aging.view)' AS description
  UNION ALL SELECT 'keuangan.report_aging.export' AS permission_key, 'Keuangan' AS module, 'Export Aging Report' AS label, 'Export Aging Report (keuangan.report_aging.export)' AS description
  UNION ALL SELECT 'keuangan.report_depreciation.view' AS permission_key, 'Keuangan' AS module, 'View Depreciation Report' AS label, 'View Depreciation Report (keuangan.report_depreciation.view)' AS description
  UNION ALL SELECT 'keuangan.report_depreciation.export' AS permission_key, 'Keuangan' AS module, 'Export Depreciation Report' AS label, 'Export Depreciation Report (keuangan.report_depreciation.export)' AS description
  UNION ALL SELECT 'keuangan.fixed_asset.view' AS permission_key, 'Keuangan' AS module, 'View Fixed Asset' AS label, 'View Fixed Asset (keuangan.fixed_asset.view)' AS description
  UNION ALL SELECT 'keuangan.fixed_asset.create' AS permission_key, 'Keuangan' AS module, 'Create Fixed Asset' AS label, 'Create Fixed Asset (keuangan.fixed_asset.create)' AS description
  UNION ALL SELECT 'keuangan.fixed_asset.update' AS permission_key, 'Keuangan' AS module, 'Update Fixed Asset' AS label, 'Update Fixed Asset (keuangan.fixed_asset.update)' AS description
  UNION ALL SELECT 'keuangan.fixed_asset.dispose' AS permission_key, 'Keuangan' AS module, 'Dispose Fixed Asset' AS label, 'Dispose Fixed Asset (keuangan.fixed_asset.dispose)' AS description
  UNION ALL SELECT 'keuangan.fx_revaluation.view' AS permission_key, 'Keuangan' AS module, 'View FX Revaluation' AS label, 'View FX Revaluation (keuangan.fx_revaluation.view)' AS description
  UNION ALL SELECT 'keuangan.fx_revaluation.run' AS permission_key, 'Keuangan' AS module, 'Run FX Revaluation' AS label, 'Run FX Revaluation (keuangan.fx_revaluation.run)' AS description
  UNION ALL SELECT 'warehouse.stock.view' AS permission_key, 'Warehouse' AS module, 'View Stock' AS label, 'View Stock (warehouse.stock.view)' AS description
  UNION ALL SELECT 'warehouse.stock_value.view' AS permission_key, 'Warehouse' AS module, 'View Stock Value' AS label, 'View Stock Value (warehouse.stock_value.view)' AS description
  UNION ALL SELECT 'warehouse.movements_today.view' AS permission_key, 'Warehouse' AS module, 'View Today''s Movements' AS label, 'View Today''s Movements (warehouse.movements_today.view)' AS description
  UNION ALL SELECT 'warehouse.pending_receive.view' AS permission_key, 'Warehouse' AS module, 'View Pending Receive' AS label, 'View Pending Receive (warehouse.pending_receive.view)' AS description
  UNION ALL SELECT 'warehouse.pending_outbound.view' AS permission_key, 'Warehouse' AS module, 'View Pending Outbound' AS label, 'View Pending Outbound (warehouse.pending_outbound.view)' AS description
  UNION ALL SELECT 'warehouse.low_stock.view' AS permission_key, 'Warehouse' AS module, 'View Low Stock' AS label, 'View Low Stock (warehouse.low_stock.view)' AS description
  UNION ALL SELECT 'warehouse.expiring_lots.view' AS permission_key, 'Warehouse' AS module, 'View Expiring Lots' AS label, 'View Expiring Lots (warehouse.expiring_lots.view)' AS description
  UNION ALL SELECT 'warehouse.adjustment_queue.view' AS permission_key, 'Warehouse' AS module, 'View Adjustment Queue' AS label, 'View Adjustment Queue (warehouse.adjustment_queue.view)' AS description
  UNION ALL SELECT 'warehouse.stock_by_location.view' AS permission_key, 'Warehouse' AS module, 'View Stock by Location' AS label, 'View Stock by Location (warehouse.stock_by_location.view)' AS description
  UNION ALL SELECT 'warehouse.discrepancy_flags.view' AS permission_key, 'Warehouse' AS module, 'View Discrepancy Flags' AS label, 'View Discrepancy Flags (warehouse.discrepancy_flags.view)' AS description
  UNION ALL SELECT 'warehouse.in.view' AS permission_key, 'Warehouse' AS module, 'View Warehouse In' AS label, 'View Warehouse In (warehouse.in.view)' AS description
  UNION ALL SELECT 'warehouse.in.create' AS permission_key, 'Warehouse' AS module, 'Create Warehouse In' AS label, 'Create Warehouse In (warehouse.in.create)' AS description
  UNION ALL SELECT 'warehouse.in.post' AS permission_key, 'Warehouse' AS module, 'Post Warehouse In' AS label, 'Post Warehouse In (warehouse.in.post)' AS description
  UNION ALL SELECT 'warehouse.out.view' AS permission_key, 'Warehouse' AS module, 'View Warehouse Out' AS label, 'View Warehouse Out (warehouse.out.view)' AS description
  UNION ALL SELECT 'warehouse.out.create' AS permission_key, 'Warehouse' AS module, 'Create Warehouse Out' AS label, 'Create Warehouse Out (warehouse.out.create)' AS description
  UNION ALL SELECT 'warehouse.out.post' AS permission_key, 'Warehouse' AS module, 'Post Warehouse Out' AS label, 'Post Warehouse Out (warehouse.out.post)' AS description
  UNION ALL SELECT 'warehouse.sample.view' AS permission_key, 'Warehouse' AS module, 'View Sample' AS label, 'View Sample (warehouse.sample.view)' AS description
  UNION ALL SELECT 'warehouse.sample.create' AS permission_key, 'Warehouse' AS module, 'Create Sample' AS label, 'Create Sample (warehouse.sample.create)' AS description
  UNION ALL SELECT 'warehouse.sample.post' AS permission_key, 'Warehouse' AS module, 'Post Sample' AS label, 'Post Sample (warehouse.sample.post)' AS description
  UNION ALL SELECT 'warehouse.adjustment.view' AS permission_key, 'Warehouse' AS module, 'View Adjustment' AS label, 'View Adjustment (warehouse.adjustment.view)' AS description
  UNION ALL SELECT 'warehouse.adjustment.create' AS permission_key, 'Warehouse' AS module, 'Create Adjustment' AS label, 'Create Adjustment (warehouse.adjustment.create)' AS description
  UNION ALL SELECT 'warehouse.adjustment.approve' AS permission_key, 'Warehouse' AS module, 'Approve Adjustment' AS label, 'Approve Adjustment (warehouse.adjustment.approve)' AS description
  UNION ALL SELECT 'warehouse.adjustment.reject' AS permission_key, 'Warehouse' AS module, 'Reject Adjustment' AS label, 'Reject Adjustment (warehouse.adjustment.reject)' AS description
  UNION ALL SELECT 'warehouse.transfer.view' AS permission_key, 'Warehouse' AS module, 'View Transfer' AS label, 'View Transfer (warehouse.transfer.view)' AS description
  UNION ALL SELECT 'warehouse.transfer.create' AS permission_key, 'Warehouse' AS module, 'Create Transfer' AS label, 'Create Transfer (warehouse.transfer.create)' AS description
  UNION ALL SELECT 'warehouse.transfer.post' AS permission_key, 'Warehouse' AS module, 'Post Transfer' AS label, 'Post Transfer (warehouse.transfer.post)' AS description
  UNION ALL SELECT 'warehouse.transfer.approve' AS permission_key, 'Warehouse' AS module, 'Approve Transfer' AS label, 'Approve Transfer (warehouse.transfer.approve)' AS description
  UNION ALL SELECT 'warehouse.opname.view' AS permission_key, 'Warehouse' AS module, 'View Stock Opname' AS label, 'View Stock Opname (warehouse.opname.view)' AS description
  UNION ALL SELECT 'warehouse.opname.create' AS permission_key, 'Warehouse' AS module, 'Create Stock Opname' AS label, 'Create Stock Opname (warehouse.opname.create)' AS description
  UNION ALL SELECT 'warehouse.opname.submit' AS permission_key, 'Warehouse' AS module, 'Submit Stock Opname' AS label, 'Submit Stock Opname (warehouse.opname.submit)' AS description
  UNION ALL SELECT 'warehouse.opname.approve' AS permission_key, 'Warehouse' AS module, 'Approve Stock Opname' AS label, 'Approve Stock Opname (warehouse.opname.approve)' AS description
  UNION ALL SELECT 'warehouse.expired_release.override' AS permission_key, 'Warehouse' AS module, 'Override Expired Release' AS label, 'Override Expired Release (warehouse.expired_release.override)' AS description
  UNION ALL SELECT 'warehouse.report_movement.view' AS permission_key, 'Warehouse' AS module, 'View Movement Report' AS label, 'View Movement Report (warehouse.report_movement.view)' AS description
  UNION ALL SELECT 'warehouse.report_byproduct.view' AS permission_key, 'Warehouse' AS module, 'View By-Product Report' AS label, 'View By-Product Report (warehouse.report_byproduct.view)' AS description
  UNION ALL SELECT 'warehouse.report_weekly.view' AS permission_key, 'Warehouse' AS module, 'View Weekly Report' AS label, 'View Weekly Report (warehouse.report_weekly.view)' AS description
  UNION ALL SELECT 'warehouse.report_movement.export' AS permission_key, 'Warehouse' AS module, 'Export Movement Report' AS label, 'Export Movement Report (warehouse.report_movement.export)' AS description
  UNION ALL SELECT 'warehouse.report_byproduct.export' AS permission_key, 'Warehouse' AS module, 'Export By-Product Report' AS label, 'Export By-Product Report (warehouse.report_byproduct.export)' AS description
  UNION ALL SELECT 'warehouse.report_weekly.export' AS permission_key, 'Warehouse' AS module, 'Export Weekly Report' AS label, 'Export Weekly Report (warehouse.report_weekly.export)' AS description
  UNION ALL SELECT 'warehouse.reorder_point.update' AS permission_key, 'Warehouse' AS module, 'Update Reorder Point' AS label, 'Update Reorder Point (warehouse.reorder_point.update)' AS description
  UNION ALL SELECT 'settings.customer.view' AS permission_key, 'Settings' AS module, 'View Customer' AS label, 'View Customer (settings.customer.view)' AS description
  UNION ALL SELECT 'settings.customer.create' AS permission_key, 'Settings' AS module, 'Create Customer' AS label, 'Create Customer (settings.customer.create)' AS description
  UNION ALL SELECT 'settings.customer.update' AS permission_key, 'Settings' AS module, 'Update Customer' AS label, 'Update Customer (settings.customer.update)' AS description
  UNION ALL SELECT 'settings.customer.delete' AS permission_key, 'Settings' AS module, 'Delete Customer' AS label, 'Delete Customer (settings.customer.delete)' AS description
  UNION ALL SELECT 'settings.supplier.view' AS permission_key, 'Settings' AS module, 'View Supplier' AS label, 'View Supplier (settings.supplier.view)' AS description
  UNION ALL SELECT 'settings.supplier.create' AS permission_key, 'Settings' AS module, 'Create Supplier' AS label, 'Create Supplier (settings.supplier.create)' AS description
  UNION ALL SELECT 'settings.supplier.update' AS permission_key, 'Settings' AS module, 'Update Supplier' AS label, 'Update Supplier (settings.supplier.update)' AS description
  UNION ALL SELECT 'settings.supplier.delete' AS permission_key, 'Settings' AS module, 'Delete Supplier' AS label, 'Delete Supplier (settings.supplier.delete)' AS description
  UNION ALL SELECT 'settings.items.view' AS permission_key, 'Settings' AS module, 'View Items' AS label, 'View Items (settings.items.view)' AS description
  UNION ALL SELECT 'settings.items.create' AS permission_key, 'Settings' AS module, 'Create Items' AS label, 'Create Items (settings.items.create)' AS description
  UNION ALL SELECT 'settings.items.update' AS permission_key, 'Settings' AS module, 'Update Items' AS label, 'Update Items (settings.items.update)' AS description
  UNION ALL SELECT 'settings.items.delete' AS permission_key, 'Settings' AS module, 'Delete Items' AS label, 'Delete Items (settings.items.delete)' AS description
  UNION ALL SELECT 'settings.uom.view' AS permission_key, 'Settings' AS module, 'View UOM' AS label, 'View UOM (settings.uom.view)' AS description
  UNION ALL SELECT 'settings.uom.create' AS permission_key, 'Settings' AS module, 'Create UOM' AS label, 'Create UOM (settings.uom.create)' AS description
  UNION ALL SELECT 'settings.uom.update' AS permission_key, 'Settings' AS module, 'Update UOM' AS label, 'Update UOM (settings.uom.update)' AS description
  UNION ALL SELECT 'settings.uom.delete' AS permission_key, 'Settings' AS module, 'Delete UOM' AS label, 'Delete UOM (settings.uom.delete)' AS description
  UNION ALL SELECT 'settings.commodity.view' AS permission_key, 'Settings' AS module, 'View Commodity' AS label, 'View Commodity (settings.commodity.view)' AS description
  UNION ALL SELECT 'settings.commodity.create' AS permission_key, 'Settings' AS module, 'Create Commodity' AS label, 'Create Commodity (settings.commodity.create)' AS description
  UNION ALL SELECT 'settings.commodity.update' AS permission_key, 'Settings' AS module, 'Update Commodity' AS label, 'Update Commodity (settings.commodity.update)' AS description
  UNION ALL SELECT 'settings.commodity.delete' AS permission_key, 'Settings' AS module, 'Delete Commodity' AS label, 'Delete Commodity (settings.commodity.delete)' AS description
  UNION ALL SELECT 'settings.currency.view' AS permission_key, 'Settings' AS module, 'View Currency' AS label, 'View Currency (settings.currency.view)' AS description
  UNION ALL SELECT 'settings.tax.view' AS permission_key, 'Settings' AS module, 'View Tax' AS label, 'View Tax (settings.tax.view)' AS description
  UNION ALL SELECT 'settings.tax.create' AS permission_key, 'Settings' AS module, 'Create Tax' AS label, 'Create Tax (settings.tax.create)' AS description
  UNION ALL SELECT 'settings.tax.update' AS permission_key, 'Settings' AS module, 'Update Tax' AS label, 'Update Tax (settings.tax.update)' AS description
  UNION ALL SELECT 'settings.tax.delete' AS permission_key, 'Settings' AS module, 'Delete Tax' AS label, 'Delete Tax (settings.tax.delete)' AS description
  UNION ALL SELECT 'settings.account_code.view' AS permission_key, 'Settings' AS module, 'View Account Code' AS label, 'View Account Code (settings.account_code.view)' AS description
  UNION ALL SELECT 'settings.account_code.create' AS permission_key, 'Settings' AS module, 'Create Account Code' AS label, 'Create Account Code (settings.account_code.create)' AS description
  UNION ALL SELECT 'settings.account_code.update' AS permission_key, 'Settings' AS module, 'Update Account Code' AS label, 'Update Account Code (settings.account_code.update)' AS description
  UNION ALL SELECT 'settings.account_code.delete' AS permission_key, 'Settings' AS module, 'Delete Account Code' AS label, 'Delete Account Code (settings.account_code.delete)' AS description
  UNION ALL SELECT 'settings.bank_account.view' AS permission_key, 'Settings' AS module, 'View Bank Account' AS label, 'View Bank Account (settings.bank_account.view)' AS description
  UNION ALL SELECT 'settings.bank_account.create' AS permission_key, 'Settings' AS module, 'Create Bank Account' AS label, 'Create Bank Account (settings.bank_account.create)' AS description
  UNION ALL SELECT 'settings.bank_account.update' AS permission_key, 'Settings' AS module, 'Update Bank Account' AS label, 'Update Bank Account (settings.bank_account.update)' AS description
  UNION ALL SELECT 'settings.bank_account.delete' AS permission_key, 'Settings' AS module, 'Delete Bank Account' AS label, 'Delete Bank Account (settings.bank_account.delete)' AS description
  UNION ALL SELECT 'settings.payment.view' AS permission_key, 'Settings' AS module, 'View Payment' AS label, 'View Payment (settings.payment.view)' AS description
  UNION ALL SELECT 'settings.payment.create' AS permission_key, 'Settings' AS module, 'Create Payment' AS label, 'Create Payment (settings.payment.create)' AS description
  UNION ALL SELECT 'settings.payment.update' AS permission_key, 'Settings' AS module, 'Update Payment' AS label, 'Update Payment (settings.payment.update)' AS description
  UNION ALL SELECT 'settings.payment.delete' AS permission_key, 'Settings' AS module, 'Delete Payment' AS label, 'Delete Payment (settings.payment.delete)' AS description
  UNION ALL SELECT 'settings.term.view' AS permission_key, 'Settings' AS module, 'View Term' AS label, 'View Term (settings.term.view)' AS description
  UNION ALL SELECT 'settings.port.view' AS permission_key, 'Settings' AS module, 'View Port' AS label, 'View Port (settings.port.view)' AS description
  UNION ALL SELECT 'settings.origin.view' AS permission_key, 'Settings' AS module, 'View Origin' AS label, 'View Origin (settings.origin.view)' AS description
  UNION ALL SELECT 'settings.shipvia.view' AS permission_key, 'Settings' AS module, 'View Ship Via' AS label, 'View Ship Via (settings.shipvia.view)' AS description
  UNION ALL SELECT 'settings.shipvia.create' AS permission_key, 'Settings' AS module, 'Create Ship Via' AS label, 'Create Ship Via (settings.shipvia.create)' AS description
  UNION ALL SELECT 'settings.shipvia.update' AS permission_key, 'Settings' AS module, 'Update Ship Via' AS label, 'Update Ship Via (settings.shipvia.update)' AS description
  UNION ALL SELECT 'settings.shipvia.delete' AS permission_key, 'Settings' AS module, 'Delete Ship Via' AS label, 'Delete Ship Via (settings.shipvia.delete)' AS description
  UNION ALL SELECT 'settings.shipment_period.view' AS permission_key, 'Settings' AS module, 'View Shipment Period' AS label, 'View Shipment Period (settings.shipment_period.view)' AS description
  UNION ALL SELECT 'settings.shipment_period.create' AS permission_key, 'Settings' AS module, 'Create Shipment Period' AS label, 'Create Shipment Period (settings.shipment_period.create)' AS description
  UNION ALL SELECT 'settings.shipment_period.update' AS permission_key, 'Settings' AS module, 'Update Shipment Period' AS label, 'Update Shipment Period (settings.shipment_period.update)' AS description
  UNION ALL SELECT 'settings.shipment_period.delete' AS permission_key, 'Settings' AS module, 'Delete Shipment Period' AS label, 'Delete Shipment Period (settings.shipment_period.delete)' AS description
  UNION ALL SELECT 'settings.warehouse_location.view' AS permission_key, 'Settings' AS module, 'View Warehouse Location' AS label, 'View Warehouse Location (settings.warehouse_location.view)' AS description
  UNION ALL SELECT 'settings.warehouse_location.create' AS permission_key, 'Settings' AS module, 'Create Warehouse Location' AS label, 'Create Warehouse Location (settings.warehouse_location.create)' AS description
  UNION ALL SELECT 'settings.warehouse_location.update' AS permission_key, 'Settings' AS module, 'Update Warehouse Location' AS label, 'Update Warehouse Location (settings.warehouse_location.update)' AS description
  UNION ALL SELECT 'settings.warehouse_location.delete' AS permission_key, 'Settings' AS module, 'Delete Warehouse Location' AS label, 'Delete Warehouse Location (settings.warehouse_location.delete)' AS description
  UNION ALL SELECT 'settings.company.view' AS permission_key, 'Settings' AS module, 'View Company' AS label, 'View Company (settings.company.view)' AS description
  UNION ALL SELECT 'settings.company.update' AS permission_key, 'Settings' AS module, 'Update Company' AS label, 'Update Company (settings.company.update)' AS description
  UNION ALL SELECT 'settings.employee.view' AS permission_key, 'Settings' AS module, 'View Employee' AS label, 'View Employee (settings.employee.view)' AS description
  UNION ALL SELECT 'settings.employee.create' AS permission_key, 'Settings' AS module, 'Create Employee' AS label, 'Create Employee (settings.employee.create)' AS description
  UNION ALL SELECT 'settings.employee.update' AS permission_key, 'Settings' AS module, 'Update Employee' AS label, 'Update Employee (settings.employee.update)' AS description
  UNION ALL SELECT 'settings.employee.delete' AS permission_key, 'Settings' AS module, 'Delete Employee' AS label, 'Delete Employee (settings.employee.delete)' AS description
  UNION ALL SELECT 'settings.users.view' AS permission_key, 'Settings' AS module, 'View Users' AS label, 'View Users (settings.users.view)' AS description
  UNION ALL SELECT 'settings.users.assign_role' AS permission_key, 'Settings' AS module, 'Assign User Role' AS label, 'Assign User Role (settings.users.assign_role)' AS description
  UNION ALL SELECT 'settings.users.set_status' AS permission_key, 'Settings' AS module, 'Set User Status' AS label, 'Set User Status (settings.users.set_status)' AS description
  UNION ALL SELECT 'settings.doc_numbering.view' AS permission_key, 'Settings' AS module, 'View Doc Numbering' AS label, 'View Doc Numbering (settings.doc_numbering.view)' AS description
  UNION ALL SELECT 'settings.doc_numbering.update' AS permission_key, 'Settings' AS module, 'Update Doc Numbering' AS label, 'Update Doc Numbering (settings.doc_numbering.update)' AS description
) t
LEFT JOIN movira_core_dev.app_permission existing
  ON existing.permission_key = t.permission_key AND existing.app_id = @app_id
WHERE existing.permission_id IS NULL;

-- ----------------------------------------------------------------------------
-- Step 3: role grants. One INSERT..SELECT per role (batched IN-list, not
-- one statement per key), scoped by ar.app_id = @app_id — idempotent via
-- anti-join on (app_role_id, permission_id). Reflects every resolved
-- decision in the source spec: Admin Sales holds profit/margin; Manager is
-- prohibited from financial statements (keuangan.report_labarugi.*); KG
-- holds warehouse.adjustment.create AND .approve; BO/Manager approve all
-- commercial documents.
--
-- VERIFIED on dev: Business Owner (273), Admin Sales (62), Admin Purchase
-- (72), Finance (63), Accounting (97) already match or exceed this spec —
-- those role blocks below are expected no-ops. Manager has 164 of 167 (a
-- 3-key gap this fills). Gudang, Kepala Gudang, and Logistic currently have
-- ZERO grants — this is the actual gap this migration exists to close.
-- ----------------------------------------------------------------------------
-- Business Owner (BO) — 233 permission keys
INSERT INTO movira_core_dev.app_role_permission (app_role_id, permission_id)
SELECT ar.app_role_id, ap.permission_id
FROM movira_core_dev.app_role ar
JOIN movira_core_dev.app_permission ap ON ap.app_id = @app_id AND ap.permission_key IN (
      'dashboard.revenue_trend.view',
      'dashboard.profit_summary.view',
      'dashboard.cash_position.view',
      'dashboard.ar_ap_summary.view',
      'dashboard.pending_approvals.view',
      'dashboard.top_partners.view',
      'dashboard.subscription_status.view',
      'dashboard.pending_users.view',
      'dashboard.exceptions.view',
      'dashboard.approval_queue.view',
      'dashboard.dept_comparison.view',
      'dashboard.activity_log.view',
      'dashboard.buku_kas_snapshot.view',
      'dashboard.bank_balances.view',
      'dashboard.payment_verification.view',
      'dashboard.ar_aging.view',
      'dashboard.ap_aging.view',
      'dashboard.tax_due.view',
      'dashboard.pnl_snapshot.view',
      'dashboard.neraca_snapshot.view',
      'dashboard.buku_besar_summary.view',
      'dashboard.open_pos.view',
      'dashboard.po_pending_approval.view',
      'dashboard.incoming_eta.view',
      'dashboard.invoice_matching.view',
      'dashboard.open_sos.view',
      'dashboard.so_pending_approval.view',
      'dashboard.delivery_status.view',
      'dashboard.sppb_pending.view',
      'dashboard.order_backlog.view',
      'dashboard.top_customers_mtd.view',
      'dashboard.shipments_in_transit.view',
      'dashboard.sppb_tracker.view',
      'dashboard.container_lookup.view',
      'dashboard.at_risk_shipments.view',
      'dashboard.clearance_mode.view',
      'dashboard.stock_movements_today.view',
      'dashboard.pending_receive.view',
      'dashboard.pending_outbound.view',
      'dashboard.low_stock.view',
      'dashboard.stock_by_location.view',
      'dashboard.adjustment_approvals.view',
      'dashboard.discrepancy_flags.view',
      'dashboard.warehouse_monthly_summary.view',
      'sales.pipeline_funnel.view',
      'sales.so_approval_queue.view',
      'sales.so_uninvoiced.view',
      'sales.deliveries_due.view',
      'sales.overdue_invoices.view',
      'sales.order_backlog_value.view',
      'sales.margin_snapshot.view',
      'sales.top_customers.view',
      'sales.so.view',
      'sales.so.approve',
      'sales.so.reject',
      'sales.so.export',
      'sales.sppb.view',
      'sales.sppb.approve',
      'sales.sppb.reject',
      'sales.sppb.export',
      'sales.do.view',
      'sales.do.approve',
      'sales.do.reject',
      'sales.do.export',
      'sales.invoice.view',
      'sales.invoice.approve',
      'sales.invoice.reject',
      'sales.invoice.export',
      'sales.profit.view',
      'sales.profit.create',
      'sales.profit.revise',
      'sales.profit.approve',
      'sales.profit.reject',
      'sales.profit.export',
      'purchase.pipeline_funnel.view',
      'purchase.po_approval_queue.view',
      'purchase.po_uninvoiced.view',
      'purchase.incoming_eta.view',
      'purchase.gr_pending.view',
      'purchase.ap_due.view',
      'purchase.top_suppliers.view',
      'purchase.po_local.view',
      'purchase.po_local.approve',
      'purchase.po_local.reject',
      'purchase.po_local.export',
      'purchase.po_import.view',
      'purchase.po_import.approve',
      'purchase.po_import.reject',
      'purchase.po_import.export',
      'purchase.gr.view',
      'purchase.gr.approve',
      'purchase.gr.reject',
      'purchase.gr.export',
      'purchase.invoice.view',
      'purchase.invoice.approve',
      'purchase.invoice.reject',
      'purchase.invoice.export',
      'keuangan.cash_position.view',
      'keuangan.entries_today.view',
      'keuangan.payment_verification.view',
      'keuangan.ar_outstanding.view',
      'keuangan.ap_outstanding.view',
      'keuangan.installments_due.view',
      'keuangan.pnl_snapshot.view',
      'keuangan.neraca_snapshot.view',
      'keuangan.hpp_snapshot.view',
      'keuangan.faktur_pajak_pending.view',
      'keuangan.ledger.view',
      'keuangan.ledger.reverse',
      'keuangan.ledger.export',
      'keuangan.ar.view',
      'keuangan.ar.approve',
      'keuangan.ar.reject',
      'keuangan.ar.export',
      'keuangan.ap.view',
      'keuangan.ap.approve',
      'keuangan.ap.reject',
      'keuangan.ap.export',
      'keuangan.verification.view',
      'keuangan.verification.verify',
      'keuangan.verification.reject',
      'keuangan.report_bukukas.view',
      'keuangan.report_bukukas.export',
      'keuangan.report_bukubesar.view',
      'keuangan.report_bukubesar.export',
      'keuangan.report_neraca.view',
      'keuangan.report_neraca.export',
      'keuangan.report_labarugi.view',
      'keuangan.report_labarugi.export',
      'keuangan.report_hpp.view',
      'keuangan.report_hpp.export',
      'keuangan.report_omset.view',
      'keuangan.report_omset.export',
      'keuangan.report_aging.view',
      'keuangan.report_aging.export',
      'keuangan.report_depreciation.view',
      'keuangan.report_depreciation.export',
      'keuangan.fixed_asset.view',
      'keuangan.fixed_asset.create',
      'keuangan.fixed_asset.update',
      'keuangan.fixed_asset.dispose',
      'keuangan.fx_revaluation.view',
      'keuangan.fx_revaluation.run',
      'warehouse.stock.view',
      'warehouse.stock_value.view',
      'warehouse.movements_today.view',
      'warehouse.pending_receive.view',
      'warehouse.pending_outbound.view',
      'warehouse.low_stock.view',
      'warehouse.expiring_lots.view',
      'warehouse.adjustment_queue.view',
      'warehouse.stock_by_location.view',
      'warehouse.discrepancy_flags.view',
      'warehouse.in.view',
      'warehouse.out.view',
      'warehouse.sample.view',
      'warehouse.adjustment.view',
      'warehouse.adjustment.approve',
      'warehouse.adjustment.reject',
      'warehouse.transfer.view',
      'warehouse.transfer.approve',
      'warehouse.opname.view',
      'warehouse.opname.approve',
      'warehouse.report_movement.view',
      'warehouse.report_byproduct.view',
      'warehouse.report_weekly.view',
      'warehouse.report_movement.export',
      'warehouse.report_byproduct.export',
      'warehouse.report_weekly.export',
      'warehouse.reorder_point.update',
      'settings.customer.view',
      'settings.customer.create',
      'settings.customer.update',
      'settings.customer.delete',
      'settings.supplier.view',
      'settings.supplier.create',
      'settings.supplier.update',
      'settings.supplier.delete',
      'settings.items.view',
      'settings.items.create',
      'settings.items.update',
      'settings.items.delete',
      'settings.uom.view',
      'settings.uom.create',
      'settings.uom.update',
      'settings.uom.delete',
      'settings.commodity.view',
      'settings.commodity.create',
      'settings.commodity.update',
      'settings.commodity.delete',
      'settings.currency.view',
      'settings.tax.view',
      'settings.tax.create',
      'settings.tax.update',
      'settings.tax.delete',
      'settings.account_code.view',
      'settings.account_code.create',
      'settings.account_code.update',
      'settings.account_code.delete',
      'settings.bank_account.view',
      'settings.bank_account.create',
      'settings.bank_account.update',
      'settings.bank_account.delete',
      'settings.payment.view',
      'settings.payment.create',
      'settings.payment.update',
      'settings.payment.delete',
      'settings.term.view',
      'settings.port.view',
      'settings.origin.view',
      'settings.shipvia.view',
      'settings.shipvia.create',
      'settings.shipvia.update',
      'settings.shipvia.delete',
      'settings.shipment_period.view',
      'settings.shipment_period.create',
      'settings.shipment_period.update',
      'settings.shipment_period.delete',
      'settings.warehouse_location.view',
      'settings.warehouse_location.create',
      'settings.warehouse_location.update',
      'settings.warehouse_location.delete',
      'settings.company.view',
      'settings.company.update',
      'settings.employee.view',
      'settings.employee.create',
      'settings.employee.update',
      'settings.employee.delete',
      'settings.users.view',
      'settings.users.assign_role',
      'settings.users.set_status',
      'settings.doc_numbering.view',
      'settings.doc_numbering.update'
)
LEFT JOIN movira_core_dev.app_role_permission existing
  ON existing.app_role_id = ar.app_role_id AND existing.permission_id = ap.permission_id
WHERE ar.role_name = 'Business Owner'
  AND ar.app_id = @app_id
  AND existing.app_role_id IS NULL;

-- Manager (MG) — 167 permission keys
INSERT INTO movira_core_dev.app_role_permission (app_role_id, permission_id)
SELECT ar.app_role_id, ap.permission_id
FROM movira_core_dev.app_role ar
JOIN movira_core_dev.app_permission ap ON ap.app_id = @app_id AND ap.permission_key IN (
      'dashboard.pending_approvals.view',
      'dashboard.exceptions.view',
      'dashboard.approval_queue.view',
      'dashboard.dept_comparison.view',
      'dashboard.activity_log.view',
      'dashboard.open_pos.view',
      'dashboard.po_pending_approval.view',
      'dashboard.incoming_eta.view',
      'dashboard.invoice_matching.view',
      'dashboard.open_sos.view',
      'dashboard.so_pending_approval.view',
      'dashboard.delivery_status.view',
      'dashboard.sppb_pending.view',
      'dashboard.order_backlog.view',
      'dashboard.top_customers_mtd.view',
      'dashboard.shipments_in_transit.view',
      'dashboard.sppb_tracker.view',
      'dashboard.container_lookup.view',
      'dashboard.at_risk_shipments.view',
      'dashboard.clearance_mode.view',
      'dashboard.stock_movements_today.view',
      'dashboard.pending_receive.view',
      'dashboard.pending_outbound.view',
      'dashboard.low_stock.view',
      'dashboard.stock_by_location.view',
      'dashboard.adjustment_approvals.view',
      'dashboard.discrepancy_flags.view',
      'dashboard.warehouse_monthly_summary.view',
      'sales.pipeline_funnel.view',
      'sales.so_approval_queue.view',
      'sales.so_uninvoiced.view',
      'sales.deliveries_due.view',
      'sales.overdue_invoices.view',
      'sales.order_backlog_value.view',
      'sales.margin_snapshot.view',
      'sales.top_customers.view',
      'sales.so.view',
      'sales.so.approve',
      'sales.so.reject',
      'sales.so.export',
      'sales.sppb.view',
      'sales.sppb.approve',
      'sales.sppb.reject',
      'sales.sppb.export',
      'sales.do.view',
      'sales.do.approve',
      'sales.do.reject',
      'sales.do.export',
      'sales.invoice.view',
      'sales.invoice.approve',
      'sales.invoice.reject',
      'sales.invoice.export',
      'sales.profit.view',
      'sales.profit.create',
      'sales.profit.revise',
      'sales.profit.approve',
      'sales.profit.reject',
      'sales.profit.export',
      'purchase.pipeline_funnel.view',
      'purchase.po_approval_queue.view',
      'purchase.po_uninvoiced.view',
      'purchase.incoming_eta.view',
      'purchase.gr_pending.view',
      'purchase.ap_due.view',
      'purchase.top_suppliers.view',
      'purchase.po_local.view',
      'purchase.po_local.approve',
      'purchase.po_local.reject',
      'purchase.po_local.export',
      'purchase.po_import.view',
      'purchase.po_import.approve',
      'purchase.po_import.reject',
      'purchase.po_import.export',
      'purchase.gr.view',
      'purchase.gr.approve',
      'purchase.gr.reject',
      'purchase.gr.export',
      'purchase.invoice.view',
      'purchase.invoice.approve',
      'purchase.invoice.reject',
      'purchase.invoice.export',
      'keuangan.cash_position.view',
      'keuangan.ar_outstanding.view',
      'keuangan.ap_outstanding.view',
      'keuangan.ar.view',
      'keuangan.ar.approve',
      'keuangan.ar.reject',
      'keuangan.ap.view',
      'keuangan.ap.approve',
      'keuangan.ap.reject',
      'keuangan.report_omset.view',
      'keuangan.report_omset.export',
      'keuangan.report_aging.view',
      'keuangan.report_aging.export',
      'warehouse.stock.view',
      'warehouse.stock_value.view',
      'warehouse.movements_today.view',
      'warehouse.pending_receive.view',
      'warehouse.pending_outbound.view',
      'warehouse.low_stock.view',
      'warehouse.expiring_lots.view',
      'warehouse.adjustment_queue.view',
      'warehouse.stock_by_location.view',
      'warehouse.discrepancy_flags.view',
      'warehouse.in.view',
      'warehouse.out.view',
      'warehouse.sample.view',
      'warehouse.adjustment.view',
      'warehouse.adjustment.approve',
      'warehouse.adjustment.reject',
      'warehouse.transfer.view',
      'warehouse.transfer.approve',
      'warehouse.opname.view',
      'warehouse.opname.approve',
      'warehouse.report_movement.view',
      'warehouse.report_byproduct.view',
      'warehouse.report_weekly.view',
      'warehouse.report_movement.export',
      'warehouse.report_byproduct.export',
      'warehouse.report_weekly.export',
      'warehouse.reorder_point.update',
      'settings.customer.view',
      'settings.customer.create',
      'settings.customer.update',
      'settings.customer.delete',
      'settings.supplier.view',
      'settings.supplier.create',
      'settings.supplier.update',
      'settings.supplier.delete',
      'settings.items.view',
      'settings.items.create',
      'settings.items.update',
      'settings.items.delete',
      'settings.uom.view',
      'settings.uom.create',
      'settings.uom.update',
      'settings.uom.delete',
      'settings.commodity.view',
      'settings.commodity.create',
      'settings.commodity.update',
      'settings.commodity.delete',
      'settings.currency.view',
      'settings.tax.view',
      'settings.payment.view',
      'settings.payment.create',
      'settings.payment.update',
      'settings.payment.delete',
      'settings.term.view',
      'settings.port.view',
      'settings.origin.view',
      'settings.shipvia.view',
      'settings.shipvia.create',
      'settings.shipvia.update',
      'settings.shipvia.delete',
      'settings.shipment_period.view',
      'settings.shipment_period.create',
      'settings.shipment_period.update',
      'settings.shipment_period.delete',
      'settings.warehouse_location.view',
      'settings.warehouse_location.create',
      'settings.warehouse_location.update',
      'settings.warehouse_location.delete',
      'settings.company.view',
      'settings.employee.view',
      'settings.employee.create',
      'settings.employee.update',
      'settings.employee.delete'
)
LEFT JOIN movira_core_dev.app_role_permission existing
  ON existing.app_role_id = ar.app_role_id AND existing.permission_id = ap.permission_id
WHERE ar.role_name = 'Manager'
  AND ar.app_id = @app_id
  AND existing.app_role_id IS NULL;

-- Admin Sales (AS) — 62 permission keys
INSERT INTO movira_core_dev.app_role_permission (app_role_id, permission_id)
SELECT ar.app_role_id, ap.permission_id
FROM movira_core_dev.app_role ar
JOIN movira_core_dev.app_permission ap ON ap.app_id = @app_id AND ap.permission_key IN (
      'dashboard.open_sos.view',
      'dashboard.so_pending_approval.view',
      'dashboard.delivery_status.view',
      'dashboard.sppb_pending.view',
      'dashboard.order_backlog.view',
      'dashboard.top_customers_mtd.view',
      'sales.pipeline_funnel.view',
      'sales.so_approval_queue.view',
      'sales.so_uninvoiced.view',
      'sales.deliveries_due.view',
      'sales.overdue_invoices.view',
      'sales.order_backlog_value.view',
      'sales.margin_snapshot.view',
      'sales.top_customers.view',
      'sales.so.view',
      'sales.so.create',
      'sales.so.update',
      'sales.so.delete',
      'sales.so.revise',
      'sales.so.export',
      'sales.sppb.view',
      'sales.sppb.create',
      'sales.sppb.revise',
      'sales.sppb.export',
      'sales.do.view',
      'sales.do.create',
      'sales.do.revise',
      'sales.do.export',
      'sales.invoice.view',
      'sales.invoice.create',
      'sales.invoice.export',
      'sales.profit.view',
      'sales.profit.create',
      'sales.profit.revise',
      'sales.profit.export',
      'keuangan.ar_outstanding.view',
      'keuangan.ar.view',
      'warehouse.stock.view',
      'warehouse.pending_outbound.view',
      'warehouse.low_stock.view',
      'warehouse.expiring_lots.view',
      'warehouse.out.view',
      'warehouse.sample.view',
      'warehouse.sample.create',
      'warehouse.report_movement.view',
      'warehouse.report_byproduct.view',
      'settings.customer.view',
      'settings.customer.create',
      'settings.customer.update',
      'settings.customer.delete',
      'settings.supplier.view',
      'settings.items.view',
      'settings.uom.view',
      'settings.commodity.view',
      'settings.currency.view',
      'settings.tax.view',
      'settings.payment.view',
      'settings.term.view',
      'settings.port.view',
      'settings.origin.view',
      'settings.shipvia.view',
      'settings.shipment_period.view'
)
LEFT JOIN movira_core_dev.app_role_permission existing
  ON existing.app_role_id = ar.app_role_id AND existing.permission_id = ap.permission_id
WHERE ar.role_name = 'Admin Sales'
  AND ar.app_id = @app_id
  AND existing.app_role_id IS NULL;

-- Admin Purchase (AP) — 72 permission keys
INSERT INTO movira_core_dev.app_role_permission (app_role_id, permission_id)
SELECT ar.app_role_id, ap.permission_id
FROM movira_core_dev.app_role ar
JOIN movira_core_dev.app_permission ap ON ap.app_id = @app_id AND ap.permission_key IN (
      'dashboard.open_pos.view',
      'dashboard.po_pending_approval.view',
      'dashboard.incoming_eta.view',
      'dashboard.invoice_matching.view',
      'dashboard.shipments_in_transit.view',
      'dashboard.container_lookup.view',
      'dashboard.at_risk_shipments.view',
      'dashboard.clearance_mode.view',
      'dashboard.low_stock.view',
      'purchase.pipeline_funnel.view',
      'purchase.po_approval_queue.view',
      'purchase.po_uninvoiced.view',
      'purchase.incoming_eta.view',
      'purchase.gr_pending.view',
      'purchase.ap_due.view',
      'purchase.top_suppliers.view',
      'purchase.po_local.view',
      'purchase.po_local.create',
      'purchase.po_local.update',
      'purchase.po_local.delete',
      'purchase.po_local.revise',
      'purchase.po_local.export',
      'purchase.po_import.view',
      'purchase.po_import.create',
      'purchase.po_import.update',
      'purchase.po_import.delete',
      'purchase.po_import.milestone',
      'purchase.po_import.revise',
      'purchase.po_import.export',
      'purchase.gr.view',
      'purchase.gr.export',
      'purchase.invoice.view',
      'purchase.invoice.create',
      'purchase.invoice.export',
      'keuangan.ap_outstanding.view',
      'keuangan.ap.view',
      'warehouse.stock.view',
      'warehouse.pending_receive.view',
      'warehouse.low_stock.view',
      'warehouse.expiring_lots.view',
      'warehouse.in.view',
      'warehouse.report_movement.view',
      'warehouse.report_byproduct.view',
      'warehouse.reorder_point.update',
      'settings.customer.view',
      'settings.supplier.view',
      'settings.supplier.create',
      'settings.supplier.update',
      'settings.supplier.delete',
      'settings.items.view',
      'settings.items.create',
      'settings.items.update',
      'settings.items.delete',
      'settings.uom.view',
      'settings.commodity.view',
      'settings.commodity.create',
      'settings.commodity.update',
      'settings.commodity.delete',
      'settings.currency.view',
      'settings.tax.view',
      'settings.payment.view',
      'settings.term.view',
      'settings.port.view',
      'settings.origin.view',
      'settings.shipvia.view',
      'settings.shipvia.create',
      'settings.shipvia.update',
      'settings.shipvia.delete',
      'settings.shipment_period.view',
      'settings.shipment_period.create',
      'settings.shipment_period.update',
      'settings.shipment_period.delete'
)
LEFT JOIN movira_core_dev.app_role_permission existing
  ON existing.app_role_id = ar.app_role_id AND existing.permission_id = ap.permission_id
WHERE ar.role_name = 'Admin Purchase'
  AND ar.app_id = @app_id
  AND existing.app_role_id IS NULL;

-- Finance (FN) — 63 permission keys
INSERT INTO movira_core_dev.app_role_permission (app_role_id, permission_id)
SELECT ar.app_role_id, ap.permission_id
FROM movira_core_dev.app_role ar
JOIN movira_core_dev.app_permission ap ON ap.app_id = @app_id AND ap.permission_key IN (
      'dashboard.buku_kas_snapshot.view',
      'dashboard.bank_balances.view',
      'dashboard.payment_verification.view',
      'dashboard.finance_entries_today.view',
      'dashboard.ar_aging.view',
      'dashboard.ap_aging.view',
      'dashboard.tax_due.view',
      'dashboard.invoice_matching.view',
      'sales.so_uninvoiced.view',
      'sales.overdue_invoices.view',
      'sales.so.view',
      'sales.invoice.view',
      'sales.invoice.create',
      'sales.invoice.export',
      'purchase.po_uninvoiced.view',
      'purchase.ap_due.view',
      'purchase.po_local.view',
      'purchase.po_import.view',
      'purchase.gr.view',
      'purchase.invoice.view',
      'purchase.invoice.create',
      'purchase.invoice.export',
      'keuangan.cash_position.view',
      'keuangan.entries_today.view',
      'keuangan.payment_verification.view',
      'keuangan.ar_outstanding.view',
      'keuangan.ap_outstanding.view',
      'keuangan.installments_due.view',
      'keuangan.faktur_pajak_pending.view',
      'keuangan.ledger.view',
      'keuangan.ledger.create',
      'keuangan.ledger.reverse',
      'keuangan.ledger.export',
      'keuangan.ar.view',
      'keuangan.ar.create',
      'keuangan.ar.update',
      'keuangan.ar.record_payment',
      'keuangan.ar.export',
      'keuangan.ap.view',
      'keuangan.ap.create',
      'keuangan.ap.update',
      'keuangan.ap.record_payment',
      'keuangan.ap.export',
      'keuangan.verification.view',
      'keuangan.verification.verify',
      'keuangan.verification.reject',
      'keuangan.report_bukukas.view',
      'keuangan.report_bukukas.export',
      'keuangan.report_aging.view',
      'keuangan.report_aging.export',
      'settings.customer.view',
      'settings.supplier.view',
      'settings.items.view',
      'settings.uom.view',
      'settings.currency.view',
      'settings.tax.view',
      'settings.account_code.view',
      'settings.bank_account.view',
      'settings.payment.view',
      'settings.payment.create',
      'settings.payment.update',
      'settings.payment.delete',
      'settings.term.view'
)
LEFT JOIN movira_core_dev.app_role_permission existing
  ON existing.app_role_id = ar.app_role_id AND existing.permission_id = ap.permission_id
WHERE ar.role_name = 'Finance'
  AND ar.app_id = @app_id
  AND existing.app_role_id IS NULL;

-- Accounting (AC) — 97 permission keys
INSERT INTO movira_core_dev.app_role_permission (app_role_id, permission_id)
SELECT ar.app_role_id, ap.permission_id
FROM movira_core_dev.app_role ar
JOIN movira_core_dev.app_permission ap ON ap.app_id = @app_id AND ap.permission_key IN (
      'dashboard.buku_kas_snapshot.view',
      'dashboard.bank_balances.view',
      'dashboard.ar_aging.view',
      'dashboard.ap_aging.view',
      'dashboard.tax_due.view',
      'dashboard.pnl_snapshot.view',
      'dashboard.neraca_snapshot.view',
      'dashboard.buku_besar_summary.view',
      'sales.so_uninvoiced.view',
      'sales.overdue_invoices.view',
      'sales.margin_snapshot.view',
      'sales.so.view',
      'sales.invoice.view',
      'sales.invoice.export',
      'sales.profit.view',
      'sales.profit.create',
      'sales.profit.revise',
      'sales.profit.export',
      'purchase.po_uninvoiced.view',
      'purchase.ap_due.view',
      'purchase.top_suppliers.view',
      'purchase.po_local.view',
      'purchase.po_import.view',
      'purchase.gr.view',
      'purchase.invoice.view',
      'purchase.invoice.export',
      'keuangan.cash_position.view',
      'keuangan.entries_today.view',
      'keuangan.ar_outstanding.view',
      'keuangan.ap_outstanding.view',
      'keuangan.installments_due.view',
      'keuangan.pnl_snapshot.view',
      'keuangan.neraca_snapshot.view',
      'keuangan.hpp_snapshot.view',
      'keuangan.faktur_pajak_pending.view',
      'keuangan.ledger.view',
      'keuangan.ledger.export',
      'keuangan.ar.view',
      'keuangan.ar.export',
      'keuangan.ap.view',
      'keuangan.ap.export',
      'keuangan.report_bukukas.view',
      'keuangan.report_bukukas.export',
      'keuangan.report_bukubesar.view',
      'keuangan.report_bukubesar.export',
      'keuangan.report_neraca.view',
      'keuangan.report_neraca.export',
      'keuangan.report_labarugi.view',
      'keuangan.report_labarugi.export',
      'keuangan.report_hpp.view',
      'keuangan.report_hpp.export',
      'keuangan.report_omset.view',
      'keuangan.report_omset.export',
      'keuangan.report_aging.view',
      'keuangan.report_aging.export',
      'keuangan.report_depreciation.view',
      'keuangan.report_depreciation.export',
      'keuangan.fixed_asset.view',
      'keuangan.fixed_asset.create',
      'keuangan.fixed_asset.update',
      'keuangan.fixed_asset.dispose',
      'keuangan.fx_revaluation.view',
      'keuangan.fx_revaluation.run',
      'warehouse.stock.view',
      'warehouse.stock_value.view',
      'warehouse.expiring_lots.view',
      'warehouse.in.view',
      'warehouse.out.view',
      'warehouse.adjustment.view',
      'warehouse.opname.view',
      'warehouse.report_movement.view',
      'warehouse.report_byproduct.view',
      'warehouse.report_weekly.view',
      'warehouse.report_movement.export',
      'warehouse.report_byproduct.export',
      'warehouse.report_weekly.export',
      'settings.customer.view',
      'settings.supplier.view',
      'settings.items.view',
      'settings.uom.view',
      'settings.currency.view',
      'settings.tax.view',
      'settings.tax.create',
      'settings.tax.update',
      'settings.tax.delete',
      'settings.account_code.view',
      'settings.account_code.create',
      'settings.account_code.update',
      'settings.account_code.delete',
      'settings.bank_account.view',
      'settings.bank_account.create',
      'settings.bank_account.update',
      'settings.bank_account.delete',
      'settings.payment.view',
      'settings.term.view',
      'settings.company.view',
      'settings.doc_numbering.view'
)
LEFT JOIN movira_core_dev.app_role_permission existing
  ON existing.app_role_id = ar.app_role_id AND existing.permission_id = ap.permission_id
WHERE ar.role_name = 'Accounting'
  AND ar.app_id = @app_id
  AND existing.app_role_id IS NULL;

-- Logistic (LG) — 51 permission keys
INSERT INTO movira_core_dev.app_role_permission (app_role_id, permission_id)
SELECT ar.app_role_id, ap.permission_id
FROM movira_core_dev.app_role ar
JOIN movira_core_dev.app_permission ap ON ap.app_id = @app_id AND ap.permission_key IN (
      'dashboard.incoming_eta.view',
      'dashboard.delivery_status.view',
      'dashboard.sppb_pending.view',
      'dashboard.shipments_in_transit.view',
      'dashboard.sppb_tracker.view',
      'dashboard.container_lookup.view',
      'dashboard.at_risk_shipments.view',
      'dashboard.clearance_mode.view',
      'dashboard.pending_receive.view',
      'dashboard.pending_outbound.view',
      'sales.pipeline_funnel.view',
      'sales.deliveries_due.view',
      'sales.so.view',
      'sales.sppb.view',
      'sales.sppb.create',
      'sales.sppb.revise',
      'sales.sppb.export',
      'sales.do.view',
      'sales.do.create',
      'sales.do.revise',
      'sales.do.export',
      'purchase.pipeline_funnel.view',
      'purchase.incoming_eta.view',
      'purchase.gr_pending.view',
      'purchase.po_local.view',
      'purchase.po_import.view',
      'purchase.po_import.milestone',
      'purchase.po_import.export',
      'purchase.gr.view',
      'warehouse.stock.view',
      'warehouse.pending_receive.view',
      'warehouse.pending_outbound.view',
      'warehouse.in.view',
      'warehouse.out.view',
      'settings.customer.view',
      'settings.supplier.view',
      'settings.items.view',
      'settings.uom.view',
      'settings.commodity.view',
      'settings.currency.view',
      'settings.term.view',
      'settings.port.view',
      'settings.origin.view',
      'settings.shipvia.view',
      'settings.shipvia.create',
      'settings.shipvia.update',
      'settings.shipvia.delete',
      'settings.shipment_period.view',
      'settings.shipment_period.create',
      'settings.shipment_period.update',
      'settings.shipment_period.delete'
)
LEFT JOIN movira_core_dev.app_role_permission existing
  ON existing.app_role_id = ar.app_role_id AND existing.permission_id = ap.permission_id
WHERE ar.role_name = 'Logistic'
  AND ar.app_id = @app_id
  AND existing.app_role_id IS NULL;

-- Gudang (GD) — 43 permission keys
INSERT INTO movira_core_dev.app_role_permission (app_role_id, permission_id)
SELECT ar.app_role_id, ap.permission_id
FROM movira_core_dev.app_role ar
JOIN movira_core_dev.app_permission ap ON ap.app_id = @app_id AND ap.permission_key IN (
      'dashboard.incoming_eta.view',
      'dashboard.stock_movements_today.view',
      'dashboard.pending_receive.view',
      'dashboard.pending_outbound.view',
      'dashboard.low_stock.view',
      'sales.deliveries_due.view',
      'sales.sppb.view',
      'sales.do.view',
      'purchase.incoming_eta.view',
      'purchase.gr_pending.view',
      'purchase.gr.view',
      'purchase.gr.create',
      'purchase.gr.export',
      'warehouse.stock.view',
      'warehouse.movements_today.view',
      'warehouse.pending_receive.view',
      'warehouse.pending_outbound.view',
      'warehouse.low_stock.view',
      'warehouse.expiring_lots.view',
      'warehouse.in.view',
      'warehouse.in.create',
      'warehouse.in.post',
      'warehouse.out.view',
      'warehouse.out.create',
      'warehouse.out.post',
      'warehouse.sample.view',
      'warehouse.sample.create',
      'warehouse.sample.post',
      'warehouse.adjustment.view',
      'warehouse.adjustment.create',
      'warehouse.transfer.view',
      'warehouse.transfer.create',
      'warehouse.transfer.post',
      'warehouse.opname.view',
      'warehouse.opname.create',
      'warehouse.opname.submit',
      'warehouse.report_movement.view',
      'warehouse.report_byproduct.view',
      'warehouse.report_weekly.view',
      'settings.items.view',
      'settings.uom.view',
      'settings.commodity.view',
      'settings.warehouse_location.view'
)
LEFT JOIN movira_core_dev.app_role_permission existing
  ON existing.app_role_id = ar.app_role_id AND existing.permission_id = ap.permission_id
WHERE ar.role_name = 'Gudang'
  AND ar.app_id = @app_id
  AND existing.app_role_id IS NULL;

-- Kepala Gudang (KG) — 72 permission keys
INSERT INTO movira_core_dev.app_role_permission (app_role_id, permission_id)
SELECT ar.app_role_id, ap.permission_id
FROM movira_core_dev.app_role ar
JOIN movira_core_dev.app_permission ap ON ap.app_id = @app_id AND ap.permission_key IN (
      'dashboard.incoming_eta.view',
      'dashboard.stock_movements_today.view',
      'dashboard.pending_receive.view',
      'dashboard.pending_outbound.view',
      'dashboard.low_stock.view',
      'dashboard.stock_by_location.view',
      'dashboard.adjustment_approvals.view',
      'dashboard.discrepancy_flags.view',
      'dashboard.warehouse_monthly_summary.view',
      'sales.pipeline_funnel.view',
      'sales.deliveries_due.view',
      'sales.sppb.view',
      'sales.do.view',
      'sales.do.export',
      'purchase.pipeline_funnel.view',
      'purchase.incoming_eta.view',
      'purchase.gr_pending.view',
      'purchase.gr.view',
      'purchase.gr.create',
      'purchase.gr.export',
      'warehouse.stock.view',
      'warehouse.stock_value.view',
      'warehouse.movements_today.view',
      'warehouse.pending_receive.view',
      'warehouse.pending_outbound.view',
      'warehouse.low_stock.view',
      'warehouse.expiring_lots.view',
      'warehouse.adjustment_queue.view',
      'warehouse.stock_by_location.view',
      'warehouse.discrepancy_flags.view',
      'warehouse.in.view',
      'warehouse.in.create',
      'warehouse.in.post',
      'warehouse.out.view',
      'warehouse.out.create',
      'warehouse.out.post',
      'warehouse.sample.view',
      'warehouse.sample.create',
      'warehouse.sample.post',
      'warehouse.adjustment.view',
      'warehouse.adjustment.create',
      'warehouse.adjustment.approve',
      'warehouse.adjustment.reject',
      'warehouse.transfer.view',
      'warehouse.transfer.create',
      'warehouse.transfer.post',
      'warehouse.transfer.approve',
      'warehouse.opname.view',
      'warehouse.opname.create',
      'warehouse.opname.submit',
      'warehouse.opname.approve',
      'warehouse.expired_release.override',
      'warehouse.report_movement.view',
      'warehouse.report_byproduct.view',
      'warehouse.report_weekly.view',
      'warehouse.report_movement.export',
      'warehouse.report_byproduct.export',
      'warehouse.report_weekly.export',
      'warehouse.reorder_point.update',
      'settings.items.view',
      'settings.items.create',
      'settings.items.update',
      'settings.items.delete',
      'settings.uom.view',
      'settings.uom.create',
      'settings.uom.update',
      'settings.uom.delete',
      'settings.commodity.view',
      'settings.warehouse_location.view',
      'settings.warehouse_location.create',
      'settings.warehouse_location.update',
      'settings.warehouse_location.delete'
)
LEFT JOIN movira_core_dev.app_role_permission existing
  ON existing.app_role_id = ar.app_role_id AND existing.permission_id = ap.permission_id
WHERE ar.role_name = 'Kepala Gudang'
  AND ar.app_id = @app_id
  AND existing.app_role_id IS NULL;

-- ----------------------------------------------------------------------------
-- Step 4: verification
-- ----------------------------------------------------------------------------
SELECT COUNT(*) AS total_permissions FROM movira_core_dev.app_permission WHERE app_id = @app_id;
-- Expect: previous count + up to 273 (fewer if some keys already existed;
-- on dev this was already 273, so expect +0 there).

SELECT ar.role_name, COUNT(*) AS granted_keys
FROM movira_core_dev.app_role_permission rp
JOIN movira_core_dev.app_role ar ON ar.app_role_id = rp.app_role_id AND ar.app_id = @app_id
JOIN movira_core_dev.app_permission ap ON ap.permission_id = rp.permission_id AND ap.app_id = @app_id
GROUP BY ar.role_name
ORDER BY granted_keys DESC;
-- Expect: Business Owner highest (~233 of the 273 keys from this seed), Gudang lowest (~43).
```

---

## PROD — NOT yet applied

**Do not run this against production without Kage's sign-off first** — same review gate as `v21`/`v23`. Mirrors the DEV block above exactly, scoped to prod schema names. Do not run until the DEV block has been executed and verified first.

```sql
-- Replace movira_core_dev → movira_core_prod below with the real prod
-- CORE_SCHEMA name from prod's .env before running. Re-run the exact DEV
-- SQL above with movira_core_dev → movira_core_prod (already done in the
-- block below for convenience — confirm it still matches prod's actual
-- CORE_SCHEMA name before executing).

-- ============================================================================
-- v24 PROD — permission catalog seed (movira_core_prod)
--
-- VERIFIED AGAINST movira_core_dev on 2026-07-23 before writing this block:
-- `app_role` is itself a shared, multi-app catalog (has its own `app_id`
-- column) — every app_role lookup below is scoped by `app_id = @app_id`,
-- never by role_name alone, to avoid touching another product's roles that
-- happen to share a name (dev has two "Business Owner" rows and two
-- "Finance" rows, one pair per app_id).
--
-- Also verified: for Aluria's app_id, "Accounting" already exists, and the
-- role the source spec calls "Logistics / Import Coordinator" already
-- exists under the name "Logistic" — Step 1 targets that existing name
-- rather than creating a differently-named duplicate. app_role_id format
-- for Aluria's own roles is confirmed 'role' + 16 lowercase hex chars.
-- ============================================================================

USE `movira_core_prod`;

SET @app_id := (SELECT app_id FROM movira_core_prod.app_permission WHERE permission_key = 'keuangan.ledger.view' LIMIT 1);

-- ----------------------------------------------------------------------------
-- Step 1: create Accounting / Logistic only if genuinely missing for this
-- app_id. No-op on dev (both already exist there) — kept for portability to
-- prod, where role_name for LG should be verified against
-- `SELECT role_name FROM movira_core_prod.app_role WHERE app_id = @app_id;` first,
-- same as dev, before assuming "Logistic" is the right existing name there too.
-- ----------------------------------------------------------------------------
INSERT INTO movira_core_prod.app_role (app_role_id, role_name, app_id)
SELECT CONCAT('role', LOWER(HEX(RANDOM_BYTES(8)))), 'Accounting', @app_id
WHERE NOT EXISTS (SELECT 1 FROM movira_core_prod.app_role WHERE role_name = 'Accounting' AND app_id = @app_id);

INSERT INTO movira_core_prod.app_role (app_role_id, role_name, app_id)
SELECT CONCAT('role', LOWER(HEX(RANDOM_BYTES(8)))), 'Logistic', @app_id
WHERE NOT EXISTS (SELECT 1 FROM movira_core_prod.app_role WHERE role_name = 'Logistic' AND app_id = @app_id);

-- ----------------------------------------------------------------------------
-- Step 2: permission catalog. 273 keys across Dashboard, Sales,
-- Purchase, Keuangan, Warehouse, Settings. Idempotent via anti-join on
-- (app_id, permission_key) — does not assume a UNIQUE constraint exists.
-- Coming-Soon keys (purchase.pr.*, RFQ, warehouse.qc.*,
-- purchase.landed_cost_variance.view, dashboard.supplier_scorecard.view,
-- e-Faktur, email-change) are deliberately NOT included.
--
-- VERIFIED on dev: all 273 keys already exist (this step is a
-- confirmed no-op there) — a prior session appears to have already run an
-- equivalent seed. Only the role grants in Step 3 have a real gap.
-- ----------------------------------------------------------------------------

INSERT INTO movira_core_prod.app_permission (permission_id, app_id, permission_key, module, label, description)
SELECT CONCAT('perm', LOWER(HEX(RANDOM_BYTES(8)))), @app_id, t.permission_key, t.module, t.label, t.description
FROM (
  SELECT 'dashboard.revenue_trend.view' AS permission_key, 'Dashboard' AS module, 'Revenue Trend Widget' AS label, 'Revenue Trend Widget (dashboard.revenue_trend.view)' AS description
  UNION ALL SELECT 'dashboard.profit_summary.view' AS permission_key, 'Dashboard' AS module, 'Profit Summary Widget' AS label, 'Profit Summary Widget (dashboard.profit_summary.view)' AS description
  UNION ALL SELECT 'dashboard.cash_position.view' AS permission_key, 'Dashboard' AS module, 'Cash Position Widget' AS label, 'Cash Position Widget (dashboard.cash_position.view)' AS description
  UNION ALL SELECT 'dashboard.ar_ap_summary.view' AS permission_key, 'Dashboard' AS module, 'AR/AP Summary Widget' AS label, 'AR/AP Summary Widget (dashboard.ar_ap_summary.view)' AS description
  UNION ALL SELECT 'dashboard.pending_approvals.view' AS permission_key, 'Dashboard' AS module, 'Pending Approvals Widget' AS label, 'Pending Approvals Widget (dashboard.pending_approvals.view)' AS description
  UNION ALL SELECT 'dashboard.top_partners.view' AS permission_key, 'Dashboard' AS module, 'Top Partners Widget' AS label, 'Top Partners Widget (dashboard.top_partners.view)' AS description
  UNION ALL SELECT 'dashboard.subscription_status.view' AS permission_key, 'Dashboard' AS module, 'Subscription Status Widget' AS label, 'Subscription Status Widget (dashboard.subscription_status.view)' AS description
  UNION ALL SELECT 'dashboard.pending_users.view' AS permission_key, 'Dashboard' AS module, 'Pending Users Widget' AS label, 'Pending Users Widget (dashboard.pending_users.view)' AS description
  UNION ALL SELECT 'dashboard.exceptions.view' AS permission_key, 'Dashboard' AS module, 'Exceptions Widget' AS label, 'Exceptions Widget (dashboard.exceptions.view)' AS description
  UNION ALL SELECT 'dashboard.approval_queue.view' AS permission_key, 'Dashboard' AS module, 'Approval Queue Widget' AS label, 'Approval Queue Widget (dashboard.approval_queue.view)' AS description
  UNION ALL SELECT 'dashboard.dept_comparison.view' AS permission_key, 'Dashboard' AS module, 'Department Comparison Widget' AS label, 'Department Comparison Widget (dashboard.dept_comparison.view)' AS description
  UNION ALL SELECT 'dashboard.activity_log.view' AS permission_key, 'Dashboard' AS module, 'Activity Log Widget' AS label, 'Activity Log Widget (dashboard.activity_log.view)' AS description
  UNION ALL SELECT 'dashboard.buku_kas_snapshot.view' AS permission_key, 'Dashboard' AS module, 'Buku Kas Snapshot Widget' AS label, 'Buku Kas Snapshot Widget (dashboard.buku_kas_snapshot.view)' AS description
  UNION ALL SELECT 'dashboard.bank_balances.view' AS permission_key, 'Dashboard' AS module, 'Bank Balances Widget' AS label, 'Bank Balances Widget (dashboard.bank_balances.view)' AS description
  UNION ALL SELECT 'dashboard.payment_verification.view' AS permission_key, 'Dashboard' AS module, 'Payment Verification Widget' AS label, 'Payment Verification Widget (dashboard.payment_verification.view)' AS description
  UNION ALL SELECT 'dashboard.finance_entries_today.view' AS permission_key, 'Dashboard' AS module, 'Today''s Finance Entries Widget' AS label, 'Today''s Finance Entries Widget (dashboard.finance_entries_today.view)' AS description
  UNION ALL SELECT 'dashboard.ar_aging.view' AS permission_key, 'Dashboard' AS module, 'AR Aging Widget' AS label, 'AR Aging Widget (dashboard.ar_aging.view)' AS description
  UNION ALL SELECT 'dashboard.ap_aging.view' AS permission_key, 'Dashboard' AS module, 'AP Aging Widget' AS label, 'AP Aging Widget (dashboard.ap_aging.view)' AS description
  UNION ALL SELECT 'dashboard.tax_due.view' AS permission_key, 'Dashboard' AS module, 'Tax Due Widget' AS label, 'Tax Due Widget (dashboard.tax_due.view)' AS description
  UNION ALL SELECT 'dashboard.pnl_snapshot.view' AS permission_key, 'Dashboard' AS module, 'P&L Snapshot Widget' AS label, 'P&L Snapshot Widget (dashboard.pnl_snapshot.view)' AS description
  UNION ALL SELECT 'dashboard.neraca_snapshot.view' AS permission_key, 'Dashboard' AS module, 'Neraca Snapshot Widget' AS label, 'Neraca Snapshot Widget (dashboard.neraca_snapshot.view)' AS description
  UNION ALL SELECT 'dashboard.buku_besar_summary.view' AS permission_key, 'Dashboard' AS module, 'Buku Besar Summary Widget' AS label, 'Buku Besar Summary Widget (dashboard.buku_besar_summary.view)' AS description
  UNION ALL SELECT 'dashboard.open_pos.view' AS permission_key, 'Dashboard' AS module, 'Open POs Widget' AS label, 'Open POs Widget (dashboard.open_pos.view)' AS description
  UNION ALL SELECT 'dashboard.po_pending_approval.view' AS permission_key, 'Dashboard' AS module, 'PO Pending Approval Widget' AS label, 'PO Pending Approval Widget (dashboard.po_pending_approval.view)' AS description
  UNION ALL SELECT 'dashboard.incoming_eta.view' AS permission_key, 'Dashboard' AS module, 'Incoming ETA Widget' AS label, 'Incoming ETA Widget (dashboard.incoming_eta.view)' AS description
  UNION ALL SELECT 'dashboard.invoice_matching.view' AS permission_key, 'Dashboard' AS module, 'Invoice Matching Widget' AS label, 'Invoice Matching Widget (dashboard.invoice_matching.view)' AS description
  UNION ALL SELECT 'dashboard.open_sos.view' AS permission_key, 'Dashboard' AS module, 'Open SOs Widget' AS label, 'Open SOs Widget (dashboard.open_sos.view)' AS description
  UNION ALL SELECT 'dashboard.so_pending_approval.view' AS permission_key, 'Dashboard' AS module, 'SO Pending Approval Widget' AS label, 'SO Pending Approval Widget (dashboard.so_pending_approval.view)' AS description
  UNION ALL SELECT 'dashboard.delivery_status.view' AS permission_key, 'Dashboard' AS module, 'Delivery Status Widget' AS label, 'Delivery Status Widget (dashboard.delivery_status.view)' AS description
  UNION ALL SELECT 'dashboard.sppb_pending.view' AS permission_key, 'Dashboard' AS module, 'Pending SPPB Widget' AS label, 'Pending SPPB Widget (dashboard.sppb_pending.view)' AS description
  UNION ALL SELECT 'dashboard.order_backlog.view' AS permission_key, 'Dashboard' AS module, 'Order Backlog Widget' AS label, 'Order Backlog Widget (dashboard.order_backlog.view)' AS description
  UNION ALL SELECT 'dashboard.top_customers_mtd.view' AS permission_key, 'Dashboard' AS module, 'Top Customers (MTD) Widget' AS label, 'Top Customers (MTD) Widget (dashboard.top_customers_mtd.view)' AS description
  UNION ALL SELECT 'dashboard.shipments_in_transit.view' AS permission_key, 'Dashboard' AS module, 'Shipments in Transit Widget' AS label, 'Shipments in Transit Widget (dashboard.shipments_in_transit.view)' AS description
  UNION ALL SELECT 'dashboard.sppb_tracker.view' AS permission_key, 'Dashboard' AS module, 'SPPB Tracker Widget' AS label, 'SPPB Tracker Widget (dashboard.sppb_tracker.view)' AS description
  UNION ALL SELECT 'dashboard.container_lookup.view' AS permission_key, 'Dashboard' AS module, 'Container Lookup Widget' AS label, 'Container Lookup Widget (dashboard.container_lookup.view)' AS description
  UNION ALL SELECT 'dashboard.at_risk_shipments.view' AS permission_key, 'Dashboard' AS module, 'At-Risk Shipments Widget' AS label, 'At-Risk Shipments Widget (dashboard.at_risk_shipments.view)' AS description
  UNION ALL SELECT 'dashboard.clearance_mode.view' AS permission_key, 'Dashboard' AS module, 'Clearance Mode Widget' AS label, 'Clearance Mode Widget (dashboard.clearance_mode.view)' AS description
  UNION ALL SELECT 'dashboard.stock_movements_today.view' AS permission_key, 'Dashboard' AS module, 'Today''s Stock Movements Widget' AS label, 'Today''s Stock Movements Widget (dashboard.stock_movements_today.view)' AS description
  UNION ALL SELECT 'dashboard.pending_receive.view' AS permission_key, 'Dashboard' AS module, 'Pending Receive Widget' AS label, 'Pending Receive Widget (dashboard.pending_receive.view)' AS description
  UNION ALL SELECT 'dashboard.pending_outbound.view' AS permission_key, 'Dashboard' AS module, 'Pending Outbound Widget' AS label, 'Pending Outbound Widget (dashboard.pending_outbound.view)' AS description
  UNION ALL SELECT 'dashboard.low_stock.view' AS permission_key, 'Dashboard' AS module, 'Low Stock Widget' AS label, 'Low Stock Widget (dashboard.low_stock.view)' AS description
  UNION ALL SELECT 'dashboard.stock_by_location.view' AS permission_key, 'Dashboard' AS module, 'Stock by Location Widget' AS label, 'Stock by Location Widget (dashboard.stock_by_location.view)' AS description
  UNION ALL SELECT 'dashboard.adjustment_approvals.view' AS permission_key, 'Dashboard' AS module, 'Adjustment Approvals Widget' AS label, 'Adjustment Approvals Widget (dashboard.adjustment_approvals.view)' AS description
  UNION ALL SELECT 'dashboard.discrepancy_flags.view' AS permission_key, 'Dashboard' AS module, 'Discrepancy Flags Widget' AS label, 'Discrepancy Flags Widget (dashboard.discrepancy_flags.view)' AS description
  UNION ALL SELECT 'dashboard.warehouse_monthly_summary.view' AS permission_key, 'Dashboard' AS module, 'Warehouse Monthly Summary Widget' AS label, 'Warehouse Monthly Summary Widget (dashboard.warehouse_monthly_summary.view)' AS description
  UNION ALL SELECT 'sales.pipeline_funnel.view' AS permission_key, 'Sales' AS module, 'View Pipeline Funnel' AS label, 'View Pipeline Funnel (sales.pipeline_funnel.view)' AS description
  UNION ALL SELECT 'sales.so_approval_queue.view' AS permission_key, 'Sales' AS module, 'View SO Approval Queue' AS label, 'View SO Approval Queue (sales.so_approval_queue.view)' AS description
  UNION ALL SELECT 'sales.so_uninvoiced.view' AS permission_key, 'Sales' AS module, 'View Uninvoiced SO' AS label, 'View Uninvoiced SO (sales.so_uninvoiced.view)' AS description
  UNION ALL SELECT 'sales.deliveries_due.view' AS permission_key, 'Sales' AS module, 'View Deliveries Due' AS label, 'View Deliveries Due (sales.deliveries_due.view)' AS description
  UNION ALL SELECT 'sales.overdue_invoices.view' AS permission_key, 'Sales' AS module, 'View Overdue Invoices' AS label, 'View Overdue Invoices (sales.overdue_invoices.view)' AS description
  UNION ALL SELECT 'sales.order_backlog_value.view' AS permission_key, 'Sales' AS module, 'View Order Backlog Value' AS label, 'View Order Backlog Value (sales.order_backlog_value.view)' AS description
  UNION ALL SELECT 'sales.margin_snapshot.view' AS permission_key, 'Sales' AS module, 'View Margin Snapshot' AS label, 'View Margin Snapshot (sales.margin_snapshot.view)' AS description
  UNION ALL SELECT 'sales.top_customers.view' AS permission_key, 'Sales' AS module, 'View Top Customers' AS label, 'View Top Customers (sales.top_customers.view)' AS description
  UNION ALL SELECT 'sales.so.view' AS permission_key, 'Sales' AS module, 'View Sales Order' AS label, 'View Sales Order (sales.so.view)' AS description
  UNION ALL SELECT 'sales.so.create' AS permission_key, 'Sales' AS module, 'Create Sales Order' AS label, 'Create Sales Order (sales.so.create)' AS description
  UNION ALL SELECT 'sales.so.update' AS permission_key, 'Sales' AS module, 'Update Sales Order' AS label, 'Update Sales Order (sales.so.update)' AS description
  UNION ALL SELECT 'sales.so.delete' AS permission_key, 'Sales' AS module, 'Delete Sales Order' AS label, 'Delete Sales Order (sales.so.delete)' AS description
  UNION ALL SELECT 'sales.so.approve' AS permission_key, 'Sales' AS module, 'Approve Sales Order' AS label, 'Approve Sales Order (sales.so.approve)' AS description
  UNION ALL SELECT 'sales.so.reject' AS permission_key, 'Sales' AS module, 'Reject Sales Order' AS label, 'Reject Sales Order (sales.so.reject)' AS description
  UNION ALL SELECT 'sales.so.revise' AS permission_key, 'Sales' AS module, 'Revise Sales Order' AS label, 'Revise Sales Order (sales.so.revise)' AS description
  UNION ALL SELECT 'sales.so.export' AS permission_key, 'Sales' AS module, 'Export Sales Order' AS label, 'Export Sales Order (sales.so.export)' AS description
  UNION ALL SELECT 'sales.sppb.view' AS permission_key, 'Sales' AS module, 'View SPPB' AS label, 'View SPPB (sales.sppb.view)' AS description
  UNION ALL SELECT 'sales.sppb.create' AS permission_key, 'Sales' AS module, 'Create SPPB' AS label, 'Create SPPB (sales.sppb.create)' AS description
  UNION ALL SELECT 'sales.sppb.approve' AS permission_key, 'Sales' AS module, 'Approve SPPB' AS label, 'Approve SPPB (sales.sppb.approve)' AS description
  UNION ALL SELECT 'sales.sppb.reject' AS permission_key, 'Sales' AS module, 'Reject SPPB' AS label, 'Reject SPPB (sales.sppb.reject)' AS description
  UNION ALL SELECT 'sales.sppb.revise' AS permission_key, 'Sales' AS module, 'Revise SPPB' AS label, 'Revise SPPB (sales.sppb.revise)' AS description
  UNION ALL SELECT 'sales.sppb.export' AS permission_key, 'Sales' AS module, 'Export SPPB' AS label, 'Export SPPB (sales.sppb.export)' AS description
  UNION ALL SELECT 'sales.do.view' AS permission_key, 'Sales' AS module, 'View Delivery Order' AS label, 'View Delivery Order (sales.do.view)' AS description
  UNION ALL SELECT 'sales.do.create' AS permission_key, 'Sales' AS module, 'Create Delivery Order' AS label, 'Create Delivery Order (sales.do.create)' AS description
  UNION ALL SELECT 'sales.do.approve' AS permission_key, 'Sales' AS module, 'Approve Delivery Order' AS label, 'Approve Delivery Order (sales.do.approve)' AS description
  UNION ALL SELECT 'sales.do.reject' AS permission_key, 'Sales' AS module, 'Reject Delivery Order' AS label, 'Reject Delivery Order (sales.do.reject)' AS description
  UNION ALL SELECT 'sales.do.revise' AS permission_key, 'Sales' AS module, 'Revise Delivery Order' AS label, 'Revise Delivery Order (sales.do.revise)' AS description
  UNION ALL SELECT 'sales.do.export' AS permission_key, 'Sales' AS module, 'Export Delivery Order' AS label, 'Export Delivery Order (sales.do.export)' AS description
  UNION ALL SELECT 'sales.invoice.view' AS permission_key, 'Sales' AS module, 'View Invoice' AS label, 'View Invoice (sales.invoice.view)' AS description
  UNION ALL SELECT 'sales.invoice.create' AS permission_key, 'Sales' AS module, 'Create Invoice' AS label, 'Create Invoice (sales.invoice.create)' AS description
  UNION ALL SELECT 'sales.invoice.approve' AS permission_key, 'Sales' AS module, 'Approve Invoice' AS label, 'Approve Invoice (sales.invoice.approve)' AS description
  UNION ALL SELECT 'sales.invoice.reject' AS permission_key, 'Sales' AS module, 'Reject Invoice' AS label, 'Reject Invoice (sales.invoice.reject)' AS description
  UNION ALL SELECT 'sales.invoice.export' AS permission_key, 'Sales' AS module, 'Export Invoice' AS label, 'Export Invoice (sales.invoice.export)' AS description
  UNION ALL SELECT 'sales.profit.view' AS permission_key, 'Sales' AS module, 'View Sales Profit' AS label, 'View Sales Profit (sales.profit.view)' AS description
  UNION ALL SELECT 'sales.profit.create' AS permission_key, 'Sales' AS module, 'Create Sales Profit' AS label, 'Create Sales Profit (sales.profit.create)' AS description
  UNION ALL SELECT 'sales.profit.revise' AS permission_key, 'Sales' AS module, 'Revise Sales Profit' AS label, 'Revise Sales Profit (sales.profit.revise)' AS description
  UNION ALL SELECT 'sales.profit.approve' AS permission_key, 'Sales' AS module, 'Approve Sales Profit' AS label, 'Approve Sales Profit (sales.profit.approve)' AS description
  UNION ALL SELECT 'sales.profit.reject' AS permission_key, 'Sales' AS module, 'Reject Sales Profit' AS label, 'Reject Sales Profit (sales.profit.reject)' AS description
  UNION ALL SELECT 'sales.profit.export' AS permission_key, 'Sales' AS module, 'Export Sales Profit' AS label, 'Export Sales Profit (sales.profit.export)' AS description
  UNION ALL SELECT 'purchase.pipeline_funnel.view' AS permission_key, 'Purchase' AS module, 'View Pipeline Funnel' AS label, 'View Pipeline Funnel (purchase.pipeline_funnel.view)' AS description
  UNION ALL SELECT 'purchase.po_approval_queue.view' AS permission_key, 'Purchase' AS module, 'View PO Approval Queue' AS label, 'View PO Approval Queue (purchase.po_approval_queue.view)' AS description
  UNION ALL SELECT 'purchase.po_uninvoiced.view' AS permission_key, 'Purchase' AS module, 'View Uninvoiced PO' AS label, 'View Uninvoiced PO (purchase.po_uninvoiced.view)' AS description
  UNION ALL SELECT 'purchase.incoming_eta.view' AS permission_key, 'Purchase' AS module, 'View Incoming ETA' AS label, 'View Incoming ETA (purchase.incoming_eta.view)' AS description
  UNION ALL SELECT 'purchase.gr_pending.view' AS permission_key, 'Purchase' AS module, 'View Pending Goods Receipt' AS label, 'View Pending Goods Receipt (purchase.gr_pending.view)' AS description
  UNION ALL SELECT 'purchase.ap_due.view' AS permission_key, 'Purchase' AS module, 'View AP Due' AS label, 'View AP Due (purchase.ap_due.view)' AS description
  UNION ALL SELECT 'purchase.top_suppliers.view' AS permission_key, 'Purchase' AS module, 'View Top Suppliers' AS label, 'View Top Suppliers (purchase.top_suppliers.view)' AS description
  UNION ALL SELECT 'purchase.po_local.view' AS permission_key, 'Purchase' AS module, 'View Local PO' AS label, 'View Local PO (purchase.po_local.view)' AS description
  UNION ALL SELECT 'purchase.po_local.create' AS permission_key, 'Purchase' AS module, 'Create Local PO' AS label, 'Create Local PO (purchase.po_local.create)' AS description
  UNION ALL SELECT 'purchase.po_local.update' AS permission_key, 'Purchase' AS module, 'Update Local PO' AS label, 'Update Local PO (purchase.po_local.update)' AS description
  UNION ALL SELECT 'purchase.po_local.delete' AS permission_key, 'Purchase' AS module, 'Delete Local PO' AS label, 'Delete Local PO (purchase.po_local.delete)' AS description
  UNION ALL SELECT 'purchase.po_local.approve' AS permission_key, 'Purchase' AS module, 'Approve Local PO' AS label, 'Approve Local PO (purchase.po_local.approve)' AS description
  UNION ALL SELECT 'purchase.po_local.reject' AS permission_key, 'Purchase' AS module, 'Reject Local PO' AS label, 'Reject Local PO (purchase.po_local.reject)' AS description
  UNION ALL SELECT 'purchase.po_local.revise' AS permission_key, 'Purchase' AS module, 'Revise Local PO' AS label, 'Revise Local PO (purchase.po_local.revise)' AS description
  UNION ALL SELECT 'purchase.po_local.export' AS permission_key, 'Purchase' AS module, 'Export Local PO' AS label, 'Export Local PO (purchase.po_local.export)' AS description
  UNION ALL SELECT 'purchase.po_import.view' AS permission_key, 'Purchase' AS module, 'View Import PO' AS label, 'View Import PO (purchase.po_import.view)' AS description
  UNION ALL SELECT 'purchase.po_import.create' AS permission_key, 'Purchase' AS module, 'Create Import PO' AS label, 'Create Import PO (purchase.po_import.create)' AS description
  UNION ALL SELECT 'purchase.po_import.update' AS permission_key, 'Purchase' AS module, 'Update Import PO' AS label, 'Update Import PO (purchase.po_import.update)' AS description
  UNION ALL SELECT 'purchase.po_import.delete' AS permission_key, 'Purchase' AS module, 'Delete Import PO' AS label, 'Delete Import PO (purchase.po_import.delete)' AS description
  UNION ALL SELECT 'purchase.po_import.milestone' AS permission_key, 'Purchase' AS module, 'Update Milestone Import PO' AS label, 'Update Milestone Import PO (purchase.po_import.milestone)' AS description
  UNION ALL SELECT 'purchase.po_import.approve' AS permission_key, 'Purchase' AS module, 'Approve Import PO' AS label, 'Approve Import PO (purchase.po_import.approve)' AS description
  UNION ALL SELECT 'purchase.po_import.reject' AS permission_key, 'Purchase' AS module, 'Reject Import PO' AS label, 'Reject Import PO (purchase.po_import.reject)' AS description
  UNION ALL SELECT 'purchase.po_import.revise' AS permission_key, 'Purchase' AS module, 'Revise Import PO' AS label, 'Revise Import PO (purchase.po_import.revise)' AS description
  UNION ALL SELECT 'purchase.po_import.export' AS permission_key, 'Purchase' AS module, 'Export Import PO' AS label, 'Export Import PO (purchase.po_import.export)' AS description
  UNION ALL SELECT 'purchase.gr.view' AS permission_key, 'Purchase' AS module, 'View Goods Receipt' AS label, 'View Goods Receipt (purchase.gr.view)' AS description
  UNION ALL SELECT 'purchase.gr.create' AS permission_key, 'Purchase' AS module, 'Create Goods Receipt' AS label, 'Create Goods Receipt (purchase.gr.create)' AS description
  UNION ALL SELECT 'purchase.gr.approve' AS permission_key, 'Purchase' AS module, 'Approve Goods Receipt' AS label, 'Approve Goods Receipt (purchase.gr.approve)' AS description
  UNION ALL SELECT 'purchase.gr.reject' AS permission_key, 'Purchase' AS module, 'Reject Goods Receipt' AS label, 'Reject Goods Receipt (purchase.gr.reject)' AS description
  UNION ALL SELECT 'purchase.gr.export' AS permission_key, 'Purchase' AS module, 'Export Goods Receipt' AS label, 'Export Goods Receipt (purchase.gr.export)' AS description
  UNION ALL SELECT 'purchase.invoice.view' AS permission_key, 'Purchase' AS module, 'View Invoice' AS label, 'View Invoice (purchase.invoice.view)' AS description
  UNION ALL SELECT 'purchase.invoice.create' AS permission_key, 'Purchase' AS module, 'Create Invoice' AS label, 'Create Invoice (purchase.invoice.create)' AS description
  UNION ALL SELECT 'purchase.invoice.approve' AS permission_key, 'Purchase' AS module, 'Approve Invoice' AS label, 'Approve Invoice (purchase.invoice.approve)' AS description
  UNION ALL SELECT 'purchase.invoice.reject' AS permission_key, 'Purchase' AS module, 'Reject Invoice' AS label, 'Reject Invoice (purchase.invoice.reject)' AS description
  UNION ALL SELECT 'purchase.invoice.export' AS permission_key, 'Purchase' AS module, 'Export Invoice' AS label, 'Export Invoice (purchase.invoice.export)' AS description
  UNION ALL SELECT 'keuangan.cash_position.view' AS permission_key, 'Keuangan' AS module, 'View Cash Position' AS label, 'View Cash Position (keuangan.cash_position.view)' AS description
  UNION ALL SELECT 'keuangan.entries_today.view' AS permission_key, 'Keuangan' AS module, 'View Today''s Entries' AS label, 'View Today''s Entries (keuangan.entries_today.view)' AS description
  UNION ALL SELECT 'keuangan.payment_verification.view' AS permission_key, 'Keuangan' AS module, 'View Payment Verification' AS label, 'View Payment Verification (keuangan.payment_verification.view)' AS description
  UNION ALL SELECT 'keuangan.ar_outstanding.view' AS permission_key, 'Keuangan' AS module, 'View AR Outstanding' AS label, 'View AR Outstanding (keuangan.ar_outstanding.view)' AS description
  UNION ALL SELECT 'keuangan.ap_outstanding.view' AS permission_key, 'Keuangan' AS module, 'View AP Outstanding' AS label, 'View AP Outstanding (keuangan.ap_outstanding.view)' AS description
  UNION ALL SELECT 'keuangan.installments_due.view' AS permission_key, 'Keuangan' AS module, 'View Installments Due' AS label, 'View Installments Due (keuangan.installments_due.view)' AS description
  UNION ALL SELECT 'keuangan.pnl_snapshot.view' AS permission_key, 'Keuangan' AS module, 'View P&L Snapshot' AS label, 'View P&L Snapshot (keuangan.pnl_snapshot.view)' AS description
  UNION ALL SELECT 'keuangan.neraca_snapshot.view' AS permission_key, 'Keuangan' AS module, 'View Neraca Snapshot' AS label, 'View Neraca Snapshot (keuangan.neraca_snapshot.view)' AS description
  UNION ALL SELECT 'keuangan.hpp_snapshot.view' AS permission_key, 'Keuangan' AS module, 'View COGS Snapshot' AS label, 'View COGS Snapshot (keuangan.hpp_snapshot.view)' AS description
  UNION ALL SELECT 'keuangan.faktur_pajak_pending.view' AS permission_key, 'Keuangan' AS module, 'View Pending Faktur Pajak' AS label, 'View Pending Faktur Pajak (keuangan.faktur_pajak_pending.view)' AS description
  UNION ALL SELECT 'keuangan.ledger.view' AS permission_key, 'Keuangan' AS module, 'View General Ledger' AS label, 'View General Ledger (keuangan.ledger.view)' AS description
  UNION ALL SELECT 'keuangan.ledger.create' AS permission_key, 'Keuangan' AS module, 'Create General Ledger' AS label, 'Create General Ledger (keuangan.ledger.create)' AS description
  UNION ALL SELECT 'keuangan.ledger.reverse' AS permission_key, 'Keuangan' AS module, 'Reverse General Ledger' AS label, 'Reverse General Ledger (keuangan.ledger.reverse)' AS description
  UNION ALL SELECT 'keuangan.ledger.export' AS permission_key, 'Keuangan' AS module, 'Export General Ledger' AS label, 'Export General Ledger (keuangan.ledger.export)' AS description
  UNION ALL SELECT 'keuangan.ar.view' AS permission_key, 'Keuangan' AS module, 'View Accounts Receivable' AS label, 'View Accounts Receivable (keuangan.ar.view)' AS description
  UNION ALL SELECT 'keuangan.ar.create' AS permission_key, 'Keuangan' AS module, 'Create Accounts Receivable' AS label, 'Create Accounts Receivable (keuangan.ar.create)' AS description
  UNION ALL SELECT 'keuangan.ar.update' AS permission_key, 'Keuangan' AS module, 'Update Accounts Receivable' AS label, 'Update Accounts Receivable (keuangan.ar.update)' AS description
  UNION ALL SELECT 'keuangan.ar.approve' AS permission_key, 'Keuangan' AS module, 'Approve Accounts Receivable' AS label, 'Approve Accounts Receivable (keuangan.ar.approve)' AS description
  UNION ALL SELECT 'keuangan.ar.reject' AS permission_key, 'Keuangan' AS module, 'Reject Accounts Receivable' AS label, 'Reject Accounts Receivable (keuangan.ar.reject)' AS description
  UNION ALL SELECT 'keuangan.ar.record_payment' AS permission_key, 'Keuangan' AS module, 'Record Payment Accounts Receivable' AS label, 'Record Payment Accounts Receivable (keuangan.ar.record_payment)' AS description
  UNION ALL SELECT 'keuangan.ar.export' AS permission_key, 'Keuangan' AS module, 'Export Accounts Receivable' AS label, 'Export Accounts Receivable (keuangan.ar.export)' AS description
  UNION ALL SELECT 'keuangan.ap.view' AS permission_key, 'Keuangan' AS module, 'View Accounts Payable' AS label, 'View Accounts Payable (keuangan.ap.view)' AS description
  UNION ALL SELECT 'keuangan.ap.create' AS permission_key, 'Keuangan' AS module, 'Create Accounts Payable' AS label, 'Create Accounts Payable (keuangan.ap.create)' AS description
  UNION ALL SELECT 'keuangan.ap.update' AS permission_key, 'Keuangan' AS module, 'Update Accounts Payable' AS label, 'Update Accounts Payable (keuangan.ap.update)' AS description
  UNION ALL SELECT 'keuangan.ap.approve' AS permission_key, 'Keuangan' AS module, 'Approve Accounts Payable' AS label, 'Approve Accounts Payable (keuangan.ap.approve)' AS description
  UNION ALL SELECT 'keuangan.ap.reject' AS permission_key, 'Keuangan' AS module, 'Reject Accounts Payable' AS label, 'Reject Accounts Payable (keuangan.ap.reject)' AS description
  UNION ALL SELECT 'keuangan.ap.record_payment' AS permission_key, 'Keuangan' AS module, 'Record Payment Accounts Payable' AS label, 'Record Payment Accounts Payable (keuangan.ap.record_payment)' AS description
  UNION ALL SELECT 'keuangan.ap.export' AS permission_key, 'Keuangan' AS module, 'Export Accounts Payable' AS label, 'Export Accounts Payable (keuangan.ap.export)' AS description
  UNION ALL SELECT 'keuangan.verification.view' AS permission_key, 'Keuangan' AS module, 'View Payment Verification' AS label, 'View Payment Verification (keuangan.verification.view)' AS description
  UNION ALL SELECT 'keuangan.verification.verify' AS permission_key, 'Keuangan' AS module, 'Verify Payment Verification' AS label, 'Verify Payment Verification (keuangan.verification.verify)' AS description
  UNION ALL SELECT 'keuangan.verification.reject' AS permission_key, 'Keuangan' AS module, 'Reject Payment Verification' AS label, 'Reject Payment Verification (keuangan.verification.reject)' AS description
  UNION ALL SELECT 'keuangan.report_bukukas.view' AS permission_key, 'Keuangan' AS module, 'View Buku Kas Report' AS label, 'View Buku Kas Report (keuangan.report_bukukas.view)' AS description
  UNION ALL SELECT 'keuangan.report_bukukas.export' AS permission_key, 'Keuangan' AS module, 'Export Buku Kas Report' AS label, 'Export Buku Kas Report (keuangan.report_bukukas.export)' AS description
  UNION ALL SELECT 'keuangan.report_bukubesar.view' AS permission_key, 'Keuangan' AS module, 'View Buku Besar Report' AS label, 'View Buku Besar Report (keuangan.report_bukubesar.view)' AS description
  UNION ALL SELECT 'keuangan.report_bukubesar.export' AS permission_key, 'Keuangan' AS module, 'Export Buku Besar Report' AS label, 'Export Buku Besar Report (keuangan.report_bukubesar.export)' AS description
  UNION ALL SELECT 'keuangan.report_neraca.view' AS permission_key, 'Keuangan' AS module, 'View Neraca Report' AS label, 'View Neraca Report (keuangan.report_neraca.view)' AS description
  UNION ALL SELECT 'keuangan.report_neraca.export' AS permission_key, 'Keuangan' AS module, 'Export Neraca Report' AS label, 'Export Neraca Report (keuangan.report_neraca.export)' AS description
  UNION ALL SELECT 'keuangan.report_labarugi.view' AS permission_key, 'Keuangan' AS module, 'View Laba Rugi Report' AS label, 'View Laba Rugi Report (keuangan.report_labarugi.view)' AS description
  UNION ALL SELECT 'keuangan.report_labarugi.export' AS permission_key, 'Keuangan' AS module, 'Export Laba Rugi Report' AS label, 'Export Laba Rugi Report (keuangan.report_labarugi.export)' AS description
  UNION ALL SELECT 'keuangan.report_hpp.view' AS permission_key, 'Keuangan' AS module, 'View COGS Report' AS label, 'View COGS Report (keuangan.report_hpp.view)' AS description
  UNION ALL SELECT 'keuangan.report_hpp.export' AS permission_key, 'Keuangan' AS module, 'Export COGS Report' AS label, 'Export COGS Report (keuangan.report_hpp.export)' AS description
  UNION ALL SELECT 'keuangan.report_omset.view' AS permission_key, 'Keuangan' AS module, 'View Omset Report' AS label, 'View Omset Report (keuangan.report_omset.view)' AS description
  UNION ALL SELECT 'keuangan.report_omset.export' AS permission_key, 'Keuangan' AS module, 'Export Omset Report' AS label, 'Export Omset Report (keuangan.report_omset.export)' AS description
  UNION ALL SELECT 'keuangan.report_aging.view' AS permission_key, 'Keuangan' AS module, 'View Aging Report' AS label, 'View Aging Report (keuangan.report_aging.view)' AS description
  UNION ALL SELECT 'keuangan.report_aging.export' AS permission_key, 'Keuangan' AS module, 'Export Aging Report' AS label, 'Export Aging Report (keuangan.report_aging.export)' AS description
  UNION ALL SELECT 'keuangan.report_depreciation.view' AS permission_key, 'Keuangan' AS module, 'View Depreciation Report' AS label, 'View Depreciation Report (keuangan.report_depreciation.view)' AS description
  UNION ALL SELECT 'keuangan.report_depreciation.export' AS permission_key, 'Keuangan' AS module, 'Export Depreciation Report' AS label, 'Export Depreciation Report (keuangan.report_depreciation.export)' AS description
  UNION ALL SELECT 'keuangan.fixed_asset.view' AS permission_key, 'Keuangan' AS module, 'View Fixed Asset' AS label, 'View Fixed Asset (keuangan.fixed_asset.view)' AS description
  UNION ALL SELECT 'keuangan.fixed_asset.create' AS permission_key, 'Keuangan' AS module, 'Create Fixed Asset' AS label, 'Create Fixed Asset (keuangan.fixed_asset.create)' AS description
  UNION ALL SELECT 'keuangan.fixed_asset.update' AS permission_key, 'Keuangan' AS module, 'Update Fixed Asset' AS label, 'Update Fixed Asset (keuangan.fixed_asset.update)' AS description
  UNION ALL SELECT 'keuangan.fixed_asset.dispose' AS permission_key, 'Keuangan' AS module, 'Dispose Fixed Asset' AS label, 'Dispose Fixed Asset (keuangan.fixed_asset.dispose)' AS description
  UNION ALL SELECT 'keuangan.fx_revaluation.view' AS permission_key, 'Keuangan' AS module, 'View FX Revaluation' AS label, 'View FX Revaluation (keuangan.fx_revaluation.view)' AS description
  UNION ALL SELECT 'keuangan.fx_revaluation.run' AS permission_key, 'Keuangan' AS module, 'Run FX Revaluation' AS label, 'Run FX Revaluation (keuangan.fx_revaluation.run)' AS description
  UNION ALL SELECT 'warehouse.stock.view' AS permission_key, 'Warehouse' AS module, 'View Stock' AS label, 'View Stock (warehouse.stock.view)' AS description
  UNION ALL SELECT 'warehouse.stock_value.view' AS permission_key, 'Warehouse' AS module, 'View Stock Value' AS label, 'View Stock Value (warehouse.stock_value.view)' AS description
  UNION ALL SELECT 'warehouse.movements_today.view' AS permission_key, 'Warehouse' AS module, 'View Today''s Movements' AS label, 'View Today''s Movements (warehouse.movements_today.view)' AS description
  UNION ALL SELECT 'warehouse.pending_receive.view' AS permission_key, 'Warehouse' AS module, 'View Pending Receive' AS label, 'View Pending Receive (warehouse.pending_receive.view)' AS description
  UNION ALL SELECT 'warehouse.pending_outbound.view' AS permission_key, 'Warehouse' AS module, 'View Pending Outbound' AS label, 'View Pending Outbound (warehouse.pending_outbound.view)' AS description
  UNION ALL SELECT 'warehouse.low_stock.view' AS permission_key, 'Warehouse' AS module, 'View Low Stock' AS label, 'View Low Stock (warehouse.low_stock.view)' AS description
  UNION ALL SELECT 'warehouse.expiring_lots.view' AS permission_key, 'Warehouse' AS module, 'View Expiring Lots' AS label, 'View Expiring Lots (warehouse.expiring_lots.view)' AS description
  UNION ALL SELECT 'warehouse.adjustment_queue.view' AS permission_key, 'Warehouse' AS module, 'View Adjustment Queue' AS label, 'View Adjustment Queue (warehouse.adjustment_queue.view)' AS description
  UNION ALL SELECT 'warehouse.stock_by_location.view' AS permission_key, 'Warehouse' AS module, 'View Stock by Location' AS label, 'View Stock by Location (warehouse.stock_by_location.view)' AS description
  UNION ALL SELECT 'warehouse.discrepancy_flags.view' AS permission_key, 'Warehouse' AS module, 'View Discrepancy Flags' AS label, 'View Discrepancy Flags (warehouse.discrepancy_flags.view)' AS description
  UNION ALL SELECT 'warehouse.in.view' AS permission_key, 'Warehouse' AS module, 'View Warehouse In' AS label, 'View Warehouse In (warehouse.in.view)' AS description
  UNION ALL SELECT 'warehouse.in.create' AS permission_key, 'Warehouse' AS module, 'Create Warehouse In' AS label, 'Create Warehouse In (warehouse.in.create)' AS description
  UNION ALL SELECT 'warehouse.in.post' AS permission_key, 'Warehouse' AS module, 'Post Warehouse In' AS label, 'Post Warehouse In (warehouse.in.post)' AS description
  UNION ALL SELECT 'warehouse.out.view' AS permission_key, 'Warehouse' AS module, 'View Warehouse Out' AS label, 'View Warehouse Out (warehouse.out.view)' AS description
  UNION ALL SELECT 'warehouse.out.create' AS permission_key, 'Warehouse' AS module, 'Create Warehouse Out' AS label, 'Create Warehouse Out (warehouse.out.create)' AS description
  UNION ALL SELECT 'warehouse.out.post' AS permission_key, 'Warehouse' AS module, 'Post Warehouse Out' AS label, 'Post Warehouse Out (warehouse.out.post)' AS description
  UNION ALL SELECT 'warehouse.sample.view' AS permission_key, 'Warehouse' AS module, 'View Sample' AS label, 'View Sample (warehouse.sample.view)' AS description
  UNION ALL SELECT 'warehouse.sample.create' AS permission_key, 'Warehouse' AS module, 'Create Sample' AS label, 'Create Sample (warehouse.sample.create)' AS description
  UNION ALL SELECT 'warehouse.sample.post' AS permission_key, 'Warehouse' AS module, 'Post Sample' AS label, 'Post Sample (warehouse.sample.post)' AS description
  UNION ALL SELECT 'warehouse.adjustment.view' AS permission_key, 'Warehouse' AS module, 'View Adjustment' AS label, 'View Adjustment (warehouse.adjustment.view)' AS description
  UNION ALL SELECT 'warehouse.adjustment.create' AS permission_key, 'Warehouse' AS module, 'Create Adjustment' AS label, 'Create Adjustment (warehouse.adjustment.create)' AS description
  UNION ALL SELECT 'warehouse.adjustment.approve' AS permission_key, 'Warehouse' AS module, 'Approve Adjustment' AS label, 'Approve Adjustment (warehouse.adjustment.approve)' AS description
  UNION ALL SELECT 'warehouse.adjustment.reject' AS permission_key, 'Warehouse' AS module, 'Reject Adjustment' AS label, 'Reject Adjustment (warehouse.adjustment.reject)' AS description
  UNION ALL SELECT 'warehouse.transfer.view' AS permission_key, 'Warehouse' AS module, 'View Transfer' AS label, 'View Transfer (warehouse.transfer.view)' AS description
  UNION ALL SELECT 'warehouse.transfer.create' AS permission_key, 'Warehouse' AS module, 'Create Transfer' AS label, 'Create Transfer (warehouse.transfer.create)' AS description
  UNION ALL SELECT 'warehouse.transfer.post' AS permission_key, 'Warehouse' AS module, 'Post Transfer' AS label, 'Post Transfer (warehouse.transfer.post)' AS description
  UNION ALL SELECT 'warehouse.transfer.approve' AS permission_key, 'Warehouse' AS module, 'Approve Transfer' AS label, 'Approve Transfer (warehouse.transfer.approve)' AS description
  UNION ALL SELECT 'warehouse.opname.view' AS permission_key, 'Warehouse' AS module, 'View Stock Opname' AS label, 'View Stock Opname (warehouse.opname.view)' AS description
  UNION ALL SELECT 'warehouse.opname.create' AS permission_key, 'Warehouse' AS module, 'Create Stock Opname' AS label, 'Create Stock Opname (warehouse.opname.create)' AS description
  UNION ALL SELECT 'warehouse.opname.submit' AS permission_key, 'Warehouse' AS module, 'Submit Stock Opname' AS label, 'Submit Stock Opname (warehouse.opname.submit)' AS description
  UNION ALL SELECT 'warehouse.opname.approve' AS permission_key, 'Warehouse' AS module, 'Approve Stock Opname' AS label, 'Approve Stock Opname (warehouse.opname.approve)' AS description
  UNION ALL SELECT 'warehouse.expired_release.override' AS permission_key, 'Warehouse' AS module, 'Override Expired Release' AS label, 'Override Expired Release (warehouse.expired_release.override)' AS description
  UNION ALL SELECT 'warehouse.report_movement.view' AS permission_key, 'Warehouse' AS module, 'View Movement Report' AS label, 'View Movement Report (warehouse.report_movement.view)' AS description
  UNION ALL SELECT 'warehouse.report_byproduct.view' AS permission_key, 'Warehouse' AS module, 'View By-Product Report' AS label, 'View By-Product Report (warehouse.report_byproduct.view)' AS description
  UNION ALL SELECT 'warehouse.report_weekly.view' AS permission_key, 'Warehouse' AS module, 'View Weekly Report' AS label, 'View Weekly Report (warehouse.report_weekly.view)' AS description
  UNION ALL SELECT 'warehouse.report_movement.export' AS permission_key, 'Warehouse' AS module, 'Export Movement Report' AS label, 'Export Movement Report (warehouse.report_movement.export)' AS description
  UNION ALL SELECT 'warehouse.report_byproduct.export' AS permission_key, 'Warehouse' AS module, 'Export By-Product Report' AS label, 'Export By-Product Report (warehouse.report_byproduct.export)' AS description
  UNION ALL SELECT 'warehouse.report_weekly.export' AS permission_key, 'Warehouse' AS module, 'Export Weekly Report' AS label, 'Export Weekly Report (warehouse.report_weekly.export)' AS description
  UNION ALL SELECT 'warehouse.reorder_point.update' AS permission_key, 'Warehouse' AS module, 'Update Reorder Point' AS label, 'Update Reorder Point (warehouse.reorder_point.update)' AS description
  UNION ALL SELECT 'settings.customer.view' AS permission_key, 'Settings' AS module, 'View Customer' AS label, 'View Customer (settings.customer.view)' AS description
  UNION ALL SELECT 'settings.customer.create' AS permission_key, 'Settings' AS module, 'Create Customer' AS label, 'Create Customer (settings.customer.create)' AS description
  UNION ALL SELECT 'settings.customer.update' AS permission_key, 'Settings' AS module, 'Update Customer' AS label, 'Update Customer (settings.customer.update)' AS description
  UNION ALL SELECT 'settings.customer.delete' AS permission_key, 'Settings' AS module, 'Delete Customer' AS label, 'Delete Customer (settings.customer.delete)' AS description
  UNION ALL SELECT 'settings.supplier.view' AS permission_key, 'Settings' AS module, 'View Supplier' AS label, 'View Supplier (settings.supplier.view)' AS description
  UNION ALL SELECT 'settings.supplier.create' AS permission_key, 'Settings' AS module, 'Create Supplier' AS label, 'Create Supplier (settings.supplier.create)' AS description
  UNION ALL SELECT 'settings.supplier.update' AS permission_key, 'Settings' AS module, 'Update Supplier' AS label, 'Update Supplier (settings.supplier.update)' AS description
  UNION ALL SELECT 'settings.supplier.delete' AS permission_key, 'Settings' AS module, 'Delete Supplier' AS label, 'Delete Supplier (settings.supplier.delete)' AS description
  UNION ALL SELECT 'settings.items.view' AS permission_key, 'Settings' AS module, 'View Items' AS label, 'View Items (settings.items.view)' AS description
  UNION ALL SELECT 'settings.items.create' AS permission_key, 'Settings' AS module, 'Create Items' AS label, 'Create Items (settings.items.create)' AS description
  UNION ALL SELECT 'settings.items.update' AS permission_key, 'Settings' AS module, 'Update Items' AS label, 'Update Items (settings.items.update)' AS description
  UNION ALL SELECT 'settings.items.delete' AS permission_key, 'Settings' AS module, 'Delete Items' AS label, 'Delete Items (settings.items.delete)' AS description
  UNION ALL SELECT 'settings.uom.view' AS permission_key, 'Settings' AS module, 'View UOM' AS label, 'View UOM (settings.uom.view)' AS description
  UNION ALL SELECT 'settings.uom.create' AS permission_key, 'Settings' AS module, 'Create UOM' AS label, 'Create UOM (settings.uom.create)' AS description
  UNION ALL SELECT 'settings.uom.update' AS permission_key, 'Settings' AS module, 'Update UOM' AS label, 'Update UOM (settings.uom.update)' AS description
  UNION ALL SELECT 'settings.uom.delete' AS permission_key, 'Settings' AS module, 'Delete UOM' AS label, 'Delete UOM (settings.uom.delete)' AS description
  UNION ALL SELECT 'settings.commodity.view' AS permission_key, 'Settings' AS module, 'View Commodity' AS label, 'View Commodity (settings.commodity.view)' AS description
  UNION ALL SELECT 'settings.commodity.create' AS permission_key, 'Settings' AS module, 'Create Commodity' AS label, 'Create Commodity (settings.commodity.create)' AS description
  UNION ALL SELECT 'settings.commodity.update' AS permission_key, 'Settings' AS module, 'Update Commodity' AS label, 'Update Commodity (settings.commodity.update)' AS description
  UNION ALL SELECT 'settings.commodity.delete' AS permission_key, 'Settings' AS module, 'Delete Commodity' AS label, 'Delete Commodity (settings.commodity.delete)' AS description
  UNION ALL SELECT 'settings.currency.view' AS permission_key, 'Settings' AS module, 'View Currency' AS label, 'View Currency (settings.currency.view)' AS description
  UNION ALL SELECT 'settings.tax.view' AS permission_key, 'Settings' AS module, 'View Tax' AS label, 'View Tax (settings.tax.view)' AS description
  UNION ALL SELECT 'settings.tax.create' AS permission_key, 'Settings' AS module, 'Create Tax' AS label, 'Create Tax (settings.tax.create)' AS description
  UNION ALL SELECT 'settings.tax.update' AS permission_key, 'Settings' AS module, 'Update Tax' AS label, 'Update Tax (settings.tax.update)' AS description
  UNION ALL SELECT 'settings.tax.delete' AS permission_key, 'Settings' AS module, 'Delete Tax' AS label, 'Delete Tax (settings.tax.delete)' AS description
  UNION ALL SELECT 'settings.account_code.view' AS permission_key, 'Settings' AS module, 'View Account Code' AS label, 'View Account Code (settings.account_code.view)' AS description
  UNION ALL SELECT 'settings.account_code.create' AS permission_key, 'Settings' AS module, 'Create Account Code' AS label, 'Create Account Code (settings.account_code.create)' AS description
  UNION ALL SELECT 'settings.account_code.update' AS permission_key, 'Settings' AS module, 'Update Account Code' AS label, 'Update Account Code (settings.account_code.update)' AS description
  UNION ALL SELECT 'settings.account_code.delete' AS permission_key, 'Settings' AS module, 'Delete Account Code' AS label, 'Delete Account Code (settings.account_code.delete)' AS description
  UNION ALL SELECT 'settings.bank_account.view' AS permission_key, 'Settings' AS module, 'View Bank Account' AS label, 'View Bank Account (settings.bank_account.view)' AS description
  UNION ALL SELECT 'settings.bank_account.create' AS permission_key, 'Settings' AS module, 'Create Bank Account' AS label, 'Create Bank Account (settings.bank_account.create)' AS description
  UNION ALL SELECT 'settings.bank_account.update' AS permission_key, 'Settings' AS module, 'Update Bank Account' AS label, 'Update Bank Account (settings.bank_account.update)' AS description
  UNION ALL SELECT 'settings.bank_account.delete' AS permission_key, 'Settings' AS module, 'Delete Bank Account' AS label, 'Delete Bank Account (settings.bank_account.delete)' AS description
  UNION ALL SELECT 'settings.payment.view' AS permission_key, 'Settings' AS module, 'View Payment' AS label, 'View Payment (settings.payment.view)' AS description
  UNION ALL SELECT 'settings.payment.create' AS permission_key, 'Settings' AS module, 'Create Payment' AS label, 'Create Payment (settings.payment.create)' AS description
  UNION ALL SELECT 'settings.payment.update' AS permission_key, 'Settings' AS module, 'Update Payment' AS label, 'Update Payment (settings.payment.update)' AS description
  UNION ALL SELECT 'settings.payment.delete' AS permission_key, 'Settings' AS module, 'Delete Payment' AS label, 'Delete Payment (settings.payment.delete)' AS description
  UNION ALL SELECT 'settings.term.view' AS permission_key, 'Settings' AS module, 'View Term' AS label, 'View Term (settings.term.view)' AS description
  UNION ALL SELECT 'settings.port.view' AS permission_key, 'Settings' AS module, 'View Port' AS label, 'View Port (settings.port.view)' AS description
  UNION ALL SELECT 'settings.origin.view' AS permission_key, 'Settings' AS module, 'View Origin' AS label, 'View Origin (settings.origin.view)' AS description
  UNION ALL SELECT 'settings.shipvia.view' AS permission_key, 'Settings' AS module, 'View Ship Via' AS label, 'View Ship Via (settings.shipvia.view)' AS description
  UNION ALL SELECT 'settings.shipvia.create' AS permission_key, 'Settings' AS module, 'Create Ship Via' AS label, 'Create Ship Via (settings.shipvia.create)' AS description
  UNION ALL SELECT 'settings.shipvia.update' AS permission_key, 'Settings' AS module, 'Update Ship Via' AS label, 'Update Ship Via (settings.shipvia.update)' AS description
  UNION ALL SELECT 'settings.shipvia.delete' AS permission_key, 'Settings' AS module, 'Delete Ship Via' AS label, 'Delete Ship Via (settings.shipvia.delete)' AS description
  UNION ALL SELECT 'settings.shipment_period.view' AS permission_key, 'Settings' AS module, 'View Shipment Period' AS label, 'View Shipment Period (settings.shipment_period.view)' AS description
  UNION ALL SELECT 'settings.shipment_period.create' AS permission_key, 'Settings' AS module, 'Create Shipment Period' AS label, 'Create Shipment Period (settings.shipment_period.create)' AS description
  UNION ALL SELECT 'settings.shipment_period.update' AS permission_key, 'Settings' AS module, 'Update Shipment Period' AS label, 'Update Shipment Period (settings.shipment_period.update)' AS description
  UNION ALL SELECT 'settings.shipment_period.delete' AS permission_key, 'Settings' AS module, 'Delete Shipment Period' AS label, 'Delete Shipment Period (settings.shipment_period.delete)' AS description
  UNION ALL SELECT 'settings.warehouse_location.view' AS permission_key, 'Settings' AS module, 'View Warehouse Location' AS label, 'View Warehouse Location (settings.warehouse_location.view)' AS description
  UNION ALL SELECT 'settings.warehouse_location.create' AS permission_key, 'Settings' AS module, 'Create Warehouse Location' AS label, 'Create Warehouse Location (settings.warehouse_location.create)' AS description
  UNION ALL SELECT 'settings.warehouse_location.update' AS permission_key, 'Settings' AS module, 'Update Warehouse Location' AS label, 'Update Warehouse Location (settings.warehouse_location.update)' AS description
  UNION ALL SELECT 'settings.warehouse_location.delete' AS permission_key, 'Settings' AS module, 'Delete Warehouse Location' AS label, 'Delete Warehouse Location (settings.warehouse_location.delete)' AS description
  UNION ALL SELECT 'settings.company.view' AS permission_key, 'Settings' AS module, 'View Company' AS label, 'View Company (settings.company.view)' AS description
  UNION ALL SELECT 'settings.company.update' AS permission_key, 'Settings' AS module, 'Update Company' AS label, 'Update Company (settings.company.update)' AS description
  UNION ALL SELECT 'settings.employee.view' AS permission_key, 'Settings' AS module, 'View Employee' AS label, 'View Employee (settings.employee.view)' AS description
  UNION ALL SELECT 'settings.employee.create' AS permission_key, 'Settings' AS module, 'Create Employee' AS label, 'Create Employee (settings.employee.create)' AS description
  UNION ALL SELECT 'settings.employee.update' AS permission_key, 'Settings' AS module, 'Update Employee' AS label, 'Update Employee (settings.employee.update)' AS description
  UNION ALL SELECT 'settings.employee.delete' AS permission_key, 'Settings' AS module, 'Delete Employee' AS label, 'Delete Employee (settings.employee.delete)' AS description
  UNION ALL SELECT 'settings.users.view' AS permission_key, 'Settings' AS module, 'View Users' AS label, 'View Users (settings.users.view)' AS description
  UNION ALL SELECT 'settings.users.assign_role' AS permission_key, 'Settings' AS module, 'Assign User Role' AS label, 'Assign User Role (settings.users.assign_role)' AS description
  UNION ALL SELECT 'settings.users.set_status' AS permission_key, 'Settings' AS module, 'Set User Status' AS label, 'Set User Status (settings.users.set_status)' AS description
  UNION ALL SELECT 'settings.doc_numbering.view' AS permission_key, 'Settings' AS module, 'View Doc Numbering' AS label, 'View Doc Numbering (settings.doc_numbering.view)' AS description
  UNION ALL SELECT 'settings.doc_numbering.update' AS permission_key, 'Settings' AS module, 'Update Doc Numbering' AS label, 'Update Doc Numbering (settings.doc_numbering.update)' AS description
) t
LEFT JOIN movira_core_prod.app_permission existing
  ON existing.permission_key = t.permission_key AND existing.app_id = @app_id
WHERE existing.permission_id IS NULL;

-- ----------------------------------------------------------------------------
-- Step 3: role grants. One INSERT..SELECT per role (batched IN-list, not
-- one statement per key), scoped by ar.app_id = @app_id — idempotent via
-- anti-join on (app_role_id, permission_id). Reflects every resolved
-- decision in the source spec: Admin Sales holds profit/margin; Manager is
-- prohibited from financial statements (keuangan.report_labarugi.*); KG
-- holds warehouse.adjustment.create AND .approve; BO/Manager approve all
-- commercial documents.
--
-- VERIFIED on dev: Business Owner (273), Admin Sales (62), Admin Purchase
-- (72), Finance (63), Accounting (97) already match or exceed this spec —
-- those role blocks below are expected no-ops. Manager has 164 of 167 (a
-- 3-key gap this fills). Gudang, Kepala Gudang, and Logistic currently have
-- ZERO grants — this is the actual gap this migration exists to close.
-- ----------------------------------------------------------------------------
-- Business Owner (BO) — 233 permission keys
INSERT INTO movira_core_prod.app_role_permission (app_role_id, permission_id)
SELECT ar.app_role_id, ap.permission_id
FROM movira_core_prod.app_role ar
JOIN movira_core_prod.app_permission ap ON ap.app_id = @app_id AND ap.permission_key IN (
      'dashboard.revenue_trend.view',
      'dashboard.profit_summary.view',
      'dashboard.cash_position.view',
      'dashboard.ar_ap_summary.view',
      'dashboard.pending_approvals.view',
      'dashboard.top_partners.view',
      'dashboard.subscription_status.view',
      'dashboard.pending_users.view',
      'dashboard.exceptions.view',
      'dashboard.approval_queue.view',
      'dashboard.dept_comparison.view',
      'dashboard.activity_log.view',
      'dashboard.buku_kas_snapshot.view',
      'dashboard.bank_balances.view',
      'dashboard.payment_verification.view',
      'dashboard.ar_aging.view',
      'dashboard.ap_aging.view',
      'dashboard.tax_due.view',
      'dashboard.pnl_snapshot.view',
      'dashboard.neraca_snapshot.view',
      'dashboard.buku_besar_summary.view',
      'dashboard.open_pos.view',
      'dashboard.po_pending_approval.view',
      'dashboard.incoming_eta.view',
      'dashboard.invoice_matching.view',
      'dashboard.open_sos.view',
      'dashboard.so_pending_approval.view',
      'dashboard.delivery_status.view',
      'dashboard.sppb_pending.view',
      'dashboard.order_backlog.view',
      'dashboard.top_customers_mtd.view',
      'dashboard.shipments_in_transit.view',
      'dashboard.sppb_tracker.view',
      'dashboard.container_lookup.view',
      'dashboard.at_risk_shipments.view',
      'dashboard.clearance_mode.view',
      'dashboard.stock_movements_today.view',
      'dashboard.pending_receive.view',
      'dashboard.pending_outbound.view',
      'dashboard.low_stock.view',
      'dashboard.stock_by_location.view',
      'dashboard.adjustment_approvals.view',
      'dashboard.discrepancy_flags.view',
      'dashboard.warehouse_monthly_summary.view',
      'sales.pipeline_funnel.view',
      'sales.so_approval_queue.view',
      'sales.so_uninvoiced.view',
      'sales.deliveries_due.view',
      'sales.overdue_invoices.view',
      'sales.order_backlog_value.view',
      'sales.margin_snapshot.view',
      'sales.top_customers.view',
      'sales.so.view',
      'sales.so.approve',
      'sales.so.reject',
      'sales.so.export',
      'sales.sppb.view',
      'sales.sppb.approve',
      'sales.sppb.reject',
      'sales.sppb.export',
      'sales.do.view',
      'sales.do.approve',
      'sales.do.reject',
      'sales.do.export',
      'sales.invoice.view',
      'sales.invoice.approve',
      'sales.invoice.reject',
      'sales.invoice.export',
      'sales.profit.view',
      'sales.profit.create',
      'sales.profit.revise',
      'sales.profit.approve',
      'sales.profit.reject',
      'sales.profit.export',
      'purchase.pipeline_funnel.view',
      'purchase.po_approval_queue.view',
      'purchase.po_uninvoiced.view',
      'purchase.incoming_eta.view',
      'purchase.gr_pending.view',
      'purchase.ap_due.view',
      'purchase.top_suppliers.view',
      'purchase.po_local.view',
      'purchase.po_local.approve',
      'purchase.po_local.reject',
      'purchase.po_local.export',
      'purchase.po_import.view',
      'purchase.po_import.approve',
      'purchase.po_import.reject',
      'purchase.po_import.export',
      'purchase.gr.view',
      'purchase.gr.approve',
      'purchase.gr.reject',
      'purchase.gr.export',
      'purchase.invoice.view',
      'purchase.invoice.approve',
      'purchase.invoice.reject',
      'purchase.invoice.export',
      'keuangan.cash_position.view',
      'keuangan.entries_today.view',
      'keuangan.payment_verification.view',
      'keuangan.ar_outstanding.view',
      'keuangan.ap_outstanding.view',
      'keuangan.installments_due.view',
      'keuangan.pnl_snapshot.view',
      'keuangan.neraca_snapshot.view',
      'keuangan.hpp_snapshot.view',
      'keuangan.faktur_pajak_pending.view',
      'keuangan.ledger.view',
      'keuangan.ledger.reverse',
      'keuangan.ledger.export',
      'keuangan.ar.view',
      'keuangan.ar.approve',
      'keuangan.ar.reject',
      'keuangan.ar.export',
      'keuangan.ap.view',
      'keuangan.ap.approve',
      'keuangan.ap.reject',
      'keuangan.ap.export',
      'keuangan.verification.view',
      'keuangan.verification.verify',
      'keuangan.verification.reject',
      'keuangan.report_bukukas.view',
      'keuangan.report_bukukas.export',
      'keuangan.report_bukubesar.view',
      'keuangan.report_bukubesar.export',
      'keuangan.report_neraca.view',
      'keuangan.report_neraca.export',
      'keuangan.report_labarugi.view',
      'keuangan.report_labarugi.export',
      'keuangan.report_hpp.view',
      'keuangan.report_hpp.export',
      'keuangan.report_omset.view',
      'keuangan.report_omset.export',
      'keuangan.report_aging.view',
      'keuangan.report_aging.export',
      'keuangan.report_depreciation.view',
      'keuangan.report_depreciation.export',
      'keuangan.fixed_asset.view',
      'keuangan.fixed_asset.create',
      'keuangan.fixed_asset.update',
      'keuangan.fixed_asset.dispose',
      'keuangan.fx_revaluation.view',
      'keuangan.fx_revaluation.run',
      'warehouse.stock.view',
      'warehouse.stock_value.view',
      'warehouse.movements_today.view',
      'warehouse.pending_receive.view',
      'warehouse.pending_outbound.view',
      'warehouse.low_stock.view',
      'warehouse.expiring_lots.view',
      'warehouse.adjustment_queue.view',
      'warehouse.stock_by_location.view',
      'warehouse.discrepancy_flags.view',
      'warehouse.in.view',
      'warehouse.out.view',
      'warehouse.sample.view',
      'warehouse.adjustment.view',
      'warehouse.adjustment.approve',
      'warehouse.adjustment.reject',
      'warehouse.transfer.view',
      'warehouse.transfer.approve',
      'warehouse.opname.view',
      'warehouse.opname.approve',
      'warehouse.report_movement.view',
      'warehouse.report_byproduct.view',
      'warehouse.report_weekly.view',
      'warehouse.report_movement.export',
      'warehouse.report_byproduct.export',
      'warehouse.report_weekly.export',
      'warehouse.reorder_point.update',
      'settings.customer.view',
      'settings.customer.create',
      'settings.customer.update',
      'settings.customer.delete',
      'settings.supplier.view',
      'settings.supplier.create',
      'settings.supplier.update',
      'settings.supplier.delete',
      'settings.items.view',
      'settings.items.create',
      'settings.items.update',
      'settings.items.delete',
      'settings.uom.view',
      'settings.uom.create',
      'settings.uom.update',
      'settings.uom.delete',
      'settings.commodity.view',
      'settings.commodity.create',
      'settings.commodity.update',
      'settings.commodity.delete',
      'settings.currency.view',
      'settings.tax.view',
      'settings.tax.create',
      'settings.tax.update',
      'settings.tax.delete',
      'settings.account_code.view',
      'settings.account_code.create',
      'settings.account_code.update',
      'settings.account_code.delete',
      'settings.bank_account.view',
      'settings.bank_account.create',
      'settings.bank_account.update',
      'settings.bank_account.delete',
      'settings.payment.view',
      'settings.payment.create',
      'settings.payment.update',
      'settings.payment.delete',
      'settings.term.view',
      'settings.port.view',
      'settings.origin.view',
      'settings.shipvia.view',
      'settings.shipvia.create',
      'settings.shipvia.update',
      'settings.shipvia.delete',
      'settings.shipment_period.view',
      'settings.shipment_period.create',
      'settings.shipment_period.update',
      'settings.shipment_period.delete',
      'settings.warehouse_location.view',
      'settings.warehouse_location.create',
      'settings.warehouse_location.update',
      'settings.warehouse_location.delete',
      'settings.company.view',
      'settings.company.update',
      'settings.employee.view',
      'settings.employee.create',
      'settings.employee.update',
      'settings.employee.delete',
      'settings.users.view',
      'settings.users.assign_role',
      'settings.users.set_status',
      'settings.doc_numbering.view',
      'settings.doc_numbering.update'
)
LEFT JOIN movira_core_prod.app_role_permission existing
  ON existing.app_role_id = ar.app_role_id AND existing.permission_id = ap.permission_id
WHERE ar.role_name = 'Business Owner'
  AND ar.app_id = @app_id
  AND existing.app_role_id IS NULL;

-- Manager (MG) — 167 permission keys
INSERT INTO movira_core_prod.app_role_permission (app_role_id, permission_id)
SELECT ar.app_role_id, ap.permission_id
FROM movira_core_prod.app_role ar
JOIN movira_core_prod.app_permission ap ON ap.app_id = @app_id AND ap.permission_key IN (
      'dashboard.pending_approvals.view',
      'dashboard.exceptions.view',
      'dashboard.approval_queue.view',
      'dashboard.dept_comparison.view',
      'dashboard.activity_log.view',
      'dashboard.open_pos.view',
      'dashboard.po_pending_approval.view',
      'dashboard.incoming_eta.view',
      'dashboard.invoice_matching.view',
      'dashboard.open_sos.view',
      'dashboard.so_pending_approval.view',
      'dashboard.delivery_status.view',
      'dashboard.sppb_pending.view',
      'dashboard.order_backlog.view',
      'dashboard.top_customers_mtd.view',
      'dashboard.shipments_in_transit.view',
      'dashboard.sppb_tracker.view',
      'dashboard.container_lookup.view',
      'dashboard.at_risk_shipments.view',
      'dashboard.clearance_mode.view',
      'dashboard.stock_movements_today.view',
      'dashboard.pending_receive.view',
      'dashboard.pending_outbound.view',
      'dashboard.low_stock.view',
      'dashboard.stock_by_location.view',
      'dashboard.adjustment_approvals.view',
      'dashboard.discrepancy_flags.view',
      'dashboard.warehouse_monthly_summary.view',
      'sales.pipeline_funnel.view',
      'sales.so_approval_queue.view',
      'sales.so_uninvoiced.view',
      'sales.deliveries_due.view',
      'sales.overdue_invoices.view',
      'sales.order_backlog_value.view',
      'sales.margin_snapshot.view',
      'sales.top_customers.view',
      'sales.so.view',
      'sales.so.approve',
      'sales.so.reject',
      'sales.so.export',
      'sales.sppb.view',
      'sales.sppb.approve',
      'sales.sppb.reject',
      'sales.sppb.export',
      'sales.do.view',
      'sales.do.approve',
      'sales.do.reject',
      'sales.do.export',
      'sales.invoice.view',
      'sales.invoice.approve',
      'sales.invoice.reject',
      'sales.invoice.export',
      'sales.profit.view',
      'sales.profit.create',
      'sales.profit.revise',
      'sales.profit.approve',
      'sales.profit.reject',
      'sales.profit.export',
      'purchase.pipeline_funnel.view',
      'purchase.po_approval_queue.view',
      'purchase.po_uninvoiced.view',
      'purchase.incoming_eta.view',
      'purchase.gr_pending.view',
      'purchase.ap_due.view',
      'purchase.top_suppliers.view',
      'purchase.po_local.view',
      'purchase.po_local.approve',
      'purchase.po_local.reject',
      'purchase.po_local.export',
      'purchase.po_import.view',
      'purchase.po_import.approve',
      'purchase.po_import.reject',
      'purchase.po_import.export',
      'purchase.gr.view',
      'purchase.gr.approve',
      'purchase.gr.reject',
      'purchase.gr.export',
      'purchase.invoice.view',
      'purchase.invoice.approve',
      'purchase.invoice.reject',
      'purchase.invoice.export',
      'keuangan.cash_position.view',
      'keuangan.ar_outstanding.view',
      'keuangan.ap_outstanding.view',
      'keuangan.ar.view',
      'keuangan.ar.approve',
      'keuangan.ar.reject',
      'keuangan.ap.view',
      'keuangan.ap.approve',
      'keuangan.ap.reject',
      'keuangan.report_omset.view',
      'keuangan.report_omset.export',
      'keuangan.report_aging.view',
      'keuangan.report_aging.export',
      'warehouse.stock.view',
      'warehouse.stock_value.view',
      'warehouse.movements_today.view',
      'warehouse.pending_receive.view',
      'warehouse.pending_outbound.view',
      'warehouse.low_stock.view',
      'warehouse.expiring_lots.view',
      'warehouse.adjustment_queue.view',
      'warehouse.stock_by_location.view',
      'warehouse.discrepancy_flags.view',
      'warehouse.in.view',
      'warehouse.out.view',
      'warehouse.sample.view',
      'warehouse.adjustment.view',
      'warehouse.adjustment.approve',
      'warehouse.adjustment.reject',
      'warehouse.transfer.view',
      'warehouse.transfer.approve',
      'warehouse.opname.view',
      'warehouse.opname.approve',
      'warehouse.report_movement.view',
      'warehouse.report_byproduct.view',
      'warehouse.report_weekly.view',
      'warehouse.report_movement.export',
      'warehouse.report_byproduct.export',
      'warehouse.report_weekly.export',
      'warehouse.reorder_point.update',
      'settings.customer.view',
      'settings.customer.create',
      'settings.customer.update',
      'settings.customer.delete',
      'settings.supplier.view',
      'settings.supplier.create',
      'settings.supplier.update',
      'settings.supplier.delete',
      'settings.items.view',
      'settings.items.create',
      'settings.items.update',
      'settings.items.delete',
      'settings.uom.view',
      'settings.uom.create',
      'settings.uom.update',
      'settings.uom.delete',
      'settings.commodity.view',
      'settings.commodity.create',
      'settings.commodity.update',
      'settings.commodity.delete',
      'settings.currency.view',
      'settings.tax.view',
      'settings.payment.view',
      'settings.payment.create',
      'settings.payment.update',
      'settings.payment.delete',
      'settings.term.view',
      'settings.port.view',
      'settings.origin.view',
      'settings.shipvia.view',
      'settings.shipvia.create',
      'settings.shipvia.update',
      'settings.shipvia.delete',
      'settings.shipment_period.view',
      'settings.shipment_period.create',
      'settings.shipment_period.update',
      'settings.shipment_period.delete',
      'settings.warehouse_location.view',
      'settings.warehouse_location.create',
      'settings.warehouse_location.update',
      'settings.warehouse_location.delete',
      'settings.company.view',
      'settings.employee.view',
      'settings.employee.create',
      'settings.employee.update',
      'settings.employee.delete'
)
LEFT JOIN movira_core_prod.app_role_permission existing
  ON existing.app_role_id = ar.app_role_id AND existing.permission_id = ap.permission_id
WHERE ar.role_name = 'Manager'
  AND ar.app_id = @app_id
  AND existing.app_role_id IS NULL;

-- Admin Sales (AS) — 62 permission keys
INSERT INTO movira_core_prod.app_role_permission (app_role_id, permission_id)
SELECT ar.app_role_id, ap.permission_id
FROM movira_core_prod.app_role ar
JOIN movira_core_prod.app_permission ap ON ap.app_id = @app_id AND ap.permission_key IN (
      'dashboard.open_sos.view',
      'dashboard.so_pending_approval.view',
      'dashboard.delivery_status.view',
      'dashboard.sppb_pending.view',
      'dashboard.order_backlog.view',
      'dashboard.top_customers_mtd.view',
      'sales.pipeline_funnel.view',
      'sales.so_approval_queue.view',
      'sales.so_uninvoiced.view',
      'sales.deliveries_due.view',
      'sales.overdue_invoices.view',
      'sales.order_backlog_value.view',
      'sales.margin_snapshot.view',
      'sales.top_customers.view',
      'sales.so.view',
      'sales.so.create',
      'sales.so.update',
      'sales.so.delete',
      'sales.so.revise',
      'sales.so.export',
      'sales.sppb.view',
      'sales.sppb.create',
      'sales.sppb.revise',
      'sales.sppb.export',
      'sales.do.view',
      'sales.do.create',
      'sales.do.revise',
      'sales.do.export',
      'sales.invoice.view',
      'sales.invoice.create',
      'sales.invoice.export',
      'sales.profit.view',
      'sales.profit.create',
      'sales.profit.revise',
      'sales.profit.export',
      'keuangan.ar_outstanding.view',
      'keuangan.ar.view',
      'warehouse.stock.view',
      'warehouse.pending_outbound.view',
      'warehouse.low_stock.view',
      'warehouse.expiring_lots.view',
      'warehouse.out.view',
      'warehouse.sample.view',
      'warehouse.sample.create',
      'warehouse.report_movement.view',
      'warehouse.report_byproduct.view',
      'settings.customer.view',
      'settings.customer.create',
      'settings.customer.update',
      'settings.customer.delete',
      'settings.supplier.view',
      'settings.items.view',
      'settings.uom.view',
      'settings.commodity.view',
      'settings.currency.view',
      'settings.tax.view',
      'settings.payment.view',
      'settings.term.view',
      'settings.port.view',
      'settings.origin.view',
      'settings.shipvia.view',
      'settings.shipment_period.view'
)
LEFT JOIN movira_core_prod.app_role_permission existing
  ON existing.app_role_id = ar.app_role_id AND existing.permission_id = ap.permission_id
WHERE ar.role_name = 'Admin Sales'
  AND ar.app_id = @app_id
  AND existing.app_role_id IS NULL;

-- Admin Purchase (AP) — 72 permission keys
INSERT INTO movira_core_prod.app_role_permission (app_role_id, permission_id)
SELECT ar.app_role_id, ap.permission_id
FROM movira_core_prod.app_role ar
JOIN movira_core_prod.app_permission ap ON ap.app_id = @app_id AND ap.permission_key IN (
      'dashboard.open_pos.view',
      'dashboard.po_pending_approval.view',
      'dashboard.incoming_eta.view',
      'dashboard.invoice_matching.view',
      'dashboard.shipments_in_transit.view',
      'dashboard.container_lookup.view',
      'dashboard.at_risk_shipments.view',
      'dashboard.clearance_mode.view',
      'dashboard.low_stock.view',
      'purchase.pipeline_funnel.view',
      'purchase.po_approval_queue.view',
      'purchase.po_uninvoiced.view',
      'purchase.incoming_eta.view',
      'purchase.gr_pending.view',
      'purchase.ap_due.view',
      'purchase.top_suppliers.view',
      'purchase.po_local.view',
      'purchase.po_local.create',
      'purchase.po_local.update',
      'purchase.po_local.delete',
      'purchase.po_local.revise',
      'purchase.po_local.export',
      'purchase.po_import.view',
      'purchase.po_import.create',
      'purchase.po_import.update',
      'purchase.po_import.delete',
      'purchase.po_import.milestone',
      'purchase.po_import.revise',
      'purchase.po_import.export',
      'purchase.gr.view',
      'purchase.gr.export',
      'purchase.invoice.view',
      'purchase.invoice.create',
      'purchase.invoice.export',
      'keuangan.ap_outstanding.view',
      'keuangan.ap.view',
      'warehouse.stock.view',
      'warehouse.pending_receive.view',
      'warehouse.low_stock.view',
      'warehouse.expiring_lots.view',
      'warehouse.in.view',
      'warehouse.report_movement.view',
      'warehouse.report_byproduct.view',
      'warehouse.reorder_point.update',
      'settings.customer.view',
      'settings.supplier.view',
      'settings.supplier.create',
      'settings.supplier.update',
      'settings.supplier.delete',
      'settings.items.view',
      'settings.items.create',
      'settings.items.update',
      'settings.items.delete',
      'settings.uom.view',
      'settings.commodity.view',
      'settings.commodity.create',
      'settings.commodity.update',
      'settings.commodity.delete',
      'settings.currency.view',
      'settings.tax.view',
      'settings.payment.view',
      'settings.term.view',
      'settings.port.view',
      'settings.origin.view',
      'settings.shipvia.view',
      'settings.shipvia.create',
      'settings.shipvia.update',
      'settings.shipvia.delete',
      'settings.shipment_period.view',
      'settings.shipment_period.create',
      'settings.shipment_period.update',
      'settings.shipment_period.delete'
)
LEFT JOIN movira_core_prod.app_role_permission existing
  ON existing.app_role_id = ar.app_role_id AND existing.permission_id = ap.permission_id
WHERE ar.role_name = 'Admin Purchase'
  AND ar.app_id = @app_id
  AND existing.app_role_id IS NULL;

-- Finance (FN) — 63 permission keys
INSERT INTO movira_core_prod.app_role_permission (app_role_id, permission_id)
SELECT ar.app_role_id, ap.permission_id
FROM movira_core_prod.app_role ar
JOIN movira_core_prod.app_permission ap ON ap.app_id = @app_id AND ap.permission_key IN (
      'dashboard.buku_kas_snapshot.view',
      'dashboard.bank_balances.view',
      'dashboard.payment_verification.view',
      'dashboard.finance_entries_today.view',
      'dashboard.ar_aging.view',
      'dashboard.ap_aging.view',
      'dashboard.tax_due.view',
      'dashboard.invoice_matching.view',
      'sales.so_uninvoiced.view',
      'sales.overdue_invoices.view',
      'sales.so.view',
      'sales.invoice.view',
      'sales.invoice.create',
      'sales.invoice.export',
      'purchase.po_uninvoiced.view',
      'purchase.ap_due.view',
      'purchase.po_local.view',
      'purchase.po_import.view',
      'purchase.gr.view',
      'purchase.invoice.view',
      'purchase.invoice.create',
      'purchase.invoice.export',
      'keuangan.cash_position.view',
      'keuangan.entries_today.view',
      'keuangan.payment_verification.view',
      'keuangan.ar_outstanding.view',
      'keuangan.ap_outstanding.view',
      'keuangan.installments_due.view',
      'keuangan.faktur_pajak_pending.view',
      'keuangan.ledger.view',
      'keuangan.ledger.create',
      'keuangan.ledger.reverse',
      'keuangan.ledger.export',
      'keuangan.ar.view',
      'keuangan.ar.create',
      'keuangan.ar.update',
      'keuangan.ar.record_payment',
      'keuangan.ar.export',
      'keuangan.ap.view',
      'keuangan.ap.create',
      'keuangan.ap.update',
      'keuangan.ap.record_payment',
      'keuangan.ap.export',
      'keuangan.verification.view',
      'keuangan.verification.verify',
      'keuangan.verification.reject',
      'keuangan.report_bukukas.view',
      'keuangan.report_bukukas.export',
      'keuangan.report_aging.view',
      'keuangan.report_aging.export',
      'settings.customer.view',
      'settings.supplier.view',
      'settings.items.view',
      'settings.uom.view',
      'settings.currency.view',
      'settings.tax.view',
      'settings.account_code.view',
      'settings.bank_account.view',
      'settings.payment.view',
      'settings.payment.create',
      'settings.payment.update',
      'settings.payment.delete',
      'settings.term.view'
)
LEFT JOIN movira_core_prod.app_role_permission existing
  ON existing.app_role_id = ar.app_role_id AND existing.permission_id = ap.permission_id
WHERE ar.role_name = 'Finance'
  AND ar.app_id = @app_id
  AND existing.app_role_id IS NULL;

-- Accounting (AC) — 97 permission keys
INSERT INTO movira_core_prod.app_role_permission (app_role_id, permission_id)
SELECT ar.app_role_id, ap.permission_id
FROM movira_core_prod.app_role ar
JOIN movira_core_prod.app_permission ap ON ap.app_id = @app_id AND ap.permission_key IN (
      'dashboard.buku_kas_snapshot.view',
      'dashboard.bank_balances.view',
      'dashboard.ar_aging.view',
      'dashboard.ap_aging.view',
      'dashboard.tax_due.view',
      'dashboard.pnl_snapshot.view',
      'dashboard.neraca_snapshot.view',
      'dashboard.buku_besar_summary.view',
      'sales.so_uninvoiced.view',
      'sales.overdue_invoices.view',
      'sales.margin_snapshot.view',
      'sales.so.view',
      'sales.invoice.view',
      'sales.invoice.export',
      'sales.profit.view',
      'sales.profit.create',
      'sales.profit.revise',
      'sales.profit.export',
      'purchase.po_uninvoiced.view',
      'purchase.ap_due.view',
      'purchase.top_suppliers.view',
      'purchase.po_local.view',
      'purchase.po_import.view',
      'purchase.gr.view',
      'purchase.invoice.view',
      'purchase.invoice.export',
      'keuangan.cash_position.view',
      'keuangan.entries_today.view',
      'keuangan.ar_outstanding.view',
      'keuangan.ap_outstanding.view',
      'keuangan.installments_due.view',
      'keuangan.pnl_snapshot.view',
      'keuangan.neraca_snapshot.view',
      'keuangan.hpp_snapshot.view',
      'keuangan.faktur_pajak_pending.view',
      'keuangan.ledger.view',
      'keuangan.ledger.export',
      'keuangan.ar.view',
      'keuangan.ar.export',
      'keuangan.ap.view',
      'keuangan.ap.export',
      'keuangan.report_bukukas.view',
      'keuangan.report_bukukas.export',
      'keuangan.report_bukubesar.view',
      'keuangan.report_bukubesar.export',
      'keuangan.report_neraca.view',
      'keuangan.report_neraca.export',
      'keuangan.report_labarugi.view',
      'keuangan.report_labarugi.export',
      'keuangan.report_hpp.view',
      'keuangan.report_hpp.export',
      'keuangan.report_omset.view',
      'keuangan.report_omset.export',
      'keuangan.report_aging.view',
      'keuangan.report_aging.export',
      'keuangan.report_depreciation.view',
      'keuangan.report_depreciation.export',
      'keuangan.fixed_asset.view',
      'keuangan.fixed_asset.create',
      'keuangan.fixed_asset.update',
      'keuangan.fixed_asset.dispose',
      'keuangan.fx_revaluation.view',
      'keuangan.fx_revaluation.run',
      'warehouse.stock.view',
      'warehouse.stock_value.view',
      'warehouse.expiring_lots.view',
      'warehouse.in.view',
      'warehouse.out.view',
      'warehouse.adjustment.view',
      'warehouse.opname.view',
      'warehouse.report_movement.view',
      'warehouse.report_byproduct.view',
      'warehouse.report_weekly.view',
      'warehouse.report_movement.export',
      'warehouse.report_byproduct.export',
      'warehouse.report_weekly.export',
      'settings.customer.view',
      'settings.supplier.view',
      'settings.items.view',
      'settings.uom.view',
      'settings.currency.view',
      'settings.tax.view',
      'settings.tax.create',
      'settings.tax.update',
      'settings.tax.delete',
      'settings.account_code.view',
      'settings.account_code.create',
      'settings.account_code.update',
      'settings.account_code.delete',
      'settings.bank_account.view',
      'settings.bank_account.create',
      'settings.bank_account.update',
      'settings.bank_account.delete',
      'settings.payment.view',
      'settings.term.view',
      'settings.company.view',
      'settings.doc_numbering.view'
)
LEFT JOIN movira_core_prod.app_role_permission existing
  ON existing.app_role_id = ar.app_role_id AND existing.permission_id = ap.permission_id
WHERE ar.role_name = 'Accounting'
  AND ar.app_id = @app_id
  AND existing.app_role_id IS NULL;

-- Logistic (LG) — 51 permission keys
INSERT INTO movira_core_prod.app_role_permission (app_role_id, permission_id)
SELECT ar.app_role_id, ap.permission_id
FROM movira_core_prod.app_role ar
JOIN movira_core_prod.app_permission ap ON ap.app_id = @app_id AND ap.permission_key IN (
      'dashboard.incoming_eta.view',
      'dashboard.delivery_status.view',
      'dashboard.sppb_pending.view',
      'dashboard.shipments_in_transit.view',
      'dashboard.sppb_tracker.view',
      'dashboard.container_lookup.view',
      'dashboard.at_risk_shipments.view',
      'dashboard.clearance_mode.view',
      'dashboard.pending_receive.view',
      'dashboard.pending_outbound.view',
      'sales.pipeline_funnel.view',
      'sales.deliveries_due.view',
      'sales.so.view',
      'sales.sppb.view',
      'sales.sppb.create',
      'sales.sppb.revise',
      'sales.sppb.export',
      'sales.do.view',
      'sales.do.create',
      'sales.do.revise',
      'sales.do.export',
      'purchase.pipeline_funnel.view',
      'purchase.incoming_eta.view',
      'purchase.gr_pending.view',
      'purchase.po_local.view',
      'purchase.po_import.view',
      'purchase.po_import.milestone',
      'purchase.po_import.export',
      'purchase.gr.view',
      'warehouse.stock.view',
      'warehouse.pending_receive.view',
      'warehouse.pending_outbound.view',
      'warehouse.in.view',
      'warehouse.out.view',
      'settings.customer.view',
      'settings.supplier.view',
      'settings.items.view',
      'settings.uom.view',
      'settings.commodity.view',
      'settings.currency.view',
      'settings.term.view',
      'settings.port.view',
      'settings.origin.view',
      'settings.shipvia.view',
      'settings.shipvia.create',
      'settings.shipvia.update',
      'settings.shipvia.delete',
      'settings.shipment_period.view',
      'settings.shipment_period.create',
      'settings.shipment_period.update',
      'settings.shipment_period.delete'
)
LEFT JOIN movira_core_prod.app_role_permission existing
  ON existing.app_role_id = ar.app_role_id AND existing.permission_id = ap.permission_id
WHERE ar.role_name = 'Logistic'
  AND ar.app_id = @app_id
  AND existing.app_role_id IS NULL;

-- Gudang (GD) — 43 permission keys
INSERT INTO movira_core_prod.app_role_permission (app_role_id, permission_id)
SELECT ar.app_role_id, ap.permission_id
FROM movira_core_prod.app_role ar
JOIN movira_core_prod.app_permission ap ON ap.app_id = @app_id AND ap.permission_key IN (
      'dashboard.incoming_eta.view',
      'dashboard.stock_movements_today.view',
      'dashboard.pending_receive.view',
      'dashboard.pending_outbound.view',
      'dashboard.low_stock.view',
      'sales.deliveries_due.view',
      'sales.sppb.view',
      'sales.do.view',
      'purchase.incoming_eta.view',
      'purchase.gr_pending.view',
      'purchase.gr.view',
      'purchase.gr.create',
      'purchase.gr.export',
      'warehouse.stock.view',
      'warehouse.movements_today.view',
      'warehouse.pending_receive.view',
      'warehouse.pending_outbound.view',
      'warehouse.low_stock.view',
      'warehouse.expiring_lots.view',
      'warehouse.in.view',
      'warehouse.in.create',
      'warehouse.in.post',
      'warehouse.out.view',
      'warehouse.out.create',
      'warehouse.out.post',
      'warehouse.sample.view',
      'warehouse.sample.create',
      'warehouse.sample.post',
      'warehouse.adjustment.view',
      'warehouse.adjustment.create',
      'warehouse.transfer.view',
      'warehouse.transfer.create',
      'warehouse.transfer.post',
      'warehouse.opname.view',
      'warehouse.opname.create',
      'warehouse.opname.submit',
      'warehouse.report_movement.view',
      'warehouse.report_byproduct.view',
      'warehouse.report_weekly.view',
      'settings.items.view',
      'settings.uom.view',
      'settings.commodity.view',
      'settings.warehouse_location.view'
)
LEFT JOIN movira_core_prod.app_role_permission existing
  ON existing.app_role_id = ar.app_role_id AND existing.permission_id = ap.permission_id
WHERE ar.role_name = 'Gudang'
  AND ar.app_id = @app_id
  AND existing.app_role_id IS NULL;

-- Kepala Gudang (KG) — 72 permission keys
INSERT INTO movira_core_prod.app_role_permission (app_role_id, permission_id)
SELECT ar.app_role_id, ap.permission_id
FROM movira_core_prod.app_role ar
JOIN movira_core_prod.app_permission ap ON ap.app_id = @app_id AND ap.permission_key IN (
      'dashboard.incoming_eta.view',
      'dashboard.stock_movements_today.view',
      'dashboard.pending_receive.view',
      'dashboard.pending_outbound.view',
      'dashboard.low_stock.view',
      'dashboard.stock_by_location.view',
      'dashboard.adjustment_approvals.view',
      'dashboard.discrepancy_flags.view',
      'dashboard.warehouse_monthly_summary.view',
      'sales.pipeline_funnel.view',
      'sales.deliveries_due.view',
      'sales.sppb.view',
      'sales.do.view',
      'sales.do.export',
      'purchase.pipeline_funnel.view',
      'purchase.incoming_eta.view',
      'purchase.gr_pending.view',
      'purchase.gr.view',
      'purchase.gr.create',
      'purchase.gr.export',
      'warehouse.stock.view',
      'warehouse.stock_value.view',
      'warehouse.movements_today.view',
      'warehouse.pending_receive.view',
      'warehouse.pending_outbound.view',
      'warehouse.low_stock.view',
      'warehouse.expiring_lots.view',
      'warehouse.adjustment_queue.view',
      'warehouse.stock_by_location.view',
      'warehouse.discrepancy_flags.view',
      'warehouse.in.view',
      'warehouse.in.create',
      'warehouse.in.post',
      'warehouse.out.view',
      'warehouse.out.create',
      'warehouse.out.post',
      'warehouse.sample.view',
      'warehouse.sample.create',
      'warehouse.sample.post',
      'warehouse.adjustment.view',
      'warehouse.adjustment.create',
      'warehouse.adjustment.approve',
      'warehouse.adjustment.reject',
      'warehouse.transfer.view',
      'warehouse.transfer.create',
      'warehouse.transfer.post',
      'warehouse.transfer.approve',
      'warehouse.opname.view',
      'warehouse.opname.create',
      'warehouse.opname.submit',
      'warehouse.opname.approve',
      'warehouse.expired_release.override',
      'warehouse.report_movement.view',
      'warehouse.report_byproduct.view',
      'warehouse.report_weekly.view',
      'warehouse.report_movement.export',
      'warehouse.report_byproduct.export',
      'warehouse.report_weekly.export',
      'warehouse.reorder_point.update',
      'settings.items.view',
      'settings.items.create',
      'settings.items.update',
      'settings.items.delete',
      'settings.uom.view',
      'settings.uom.create',
      'settings.uom.update',
      'settings.uom.delete',
      'settings.commodity.view',
      'settings.warehouse_location.view',
      'settings.warehouse_location.create',
      'settings.warehouse_location.update',
      'settings.warehouse_location.delete'
)
LEFT JOIN movira_core_prod.app_role_permission existing
  ON existing.app_role_id = ar.app_role_id AND existing.permission_id = ap.permission_id
WHERE ar.role_name = 'Kepala Gudang'
  AND ar.app_id = @app_id
  AND existing.app_role_id IS NULL;

-- ----------------------------------------------------------------------------
-- Step 4: verification
-- ----------------------------------------------------------------------------
SELECT COUNT(*) AS total_permissions FROM movira_core_prod.app_permission WHERE app_id = @app_id;
-- Expect: previous count + up to 273 (fewer if some keys already existed;
-- on dev this was already 273, so expect +0 there).

SELECT ar.role_name, COUNT(*) AS granted_keys
FROM movira_core_prod.app_role_permission rp
JOIN movira_core_prod.app_role ar ON ar.app_role_id = rp.app_role_id AND ar.app_id = @app_id
JOIN movira_core_prod.app_permission ap ON ap.permission_id = rp.permission_id AND ap.app_id = @app_id
GROUP BY ar.role_name
ORDER BY granted_keys DESC;
-- Expect: Business Owner highest (~233 of the 273 keys from this seed), Gudang lowest (~43).
```

---

## Post-migration checklist

- [ ] **Before running on PROD**, verify the same two facts confirmed on dev, since they are not guaranteed to hold there: (1) whether "Logistics / Import Coordinator" already exists under a different name (dev's is "Logistic") — `SELECT role_name FROM {CORE_SCHEMA}.app_role WHERE app_id = @app_id;` — and adjust `ROLE_NAMES["LG"]`-equivalent target name in the PROD block if it differs; (2) whether the permission catalog (Step 2) is already seeded there too, the way it turned out to be on dev.
- [ ] **No permission-gate is wired into module dispatch code yet.** This addendum only seeds the catalog and grants — as of this writing, `userHasPermission()` (`v2/helpers/permission.php`) is called only from `v2/helpers/dual_approval.php`. Every module's `getAll*`/list endpoint still gates solely on `requireAuth()`, not on any of these 273 keys. Seeding the catalog/grants does not, by itself, restrict any API response — wiring `userHasPermission($conn, $authUser['app_role_id'], '<key>')` checks into each module's dispatch block is separate follow-up work.
- [ ] **Labels/descriptions for any newly-inserted permission rows are Claude-generated from the permission_key** (not needed on dev, since Step 2 was a no-op there) — reasonable defaults (e.g. `sales.so.create` → "Create Sales Order"), but review the generated `label` column before this reaches any admin-facing "manage roles" screen copy, in case Step 2 does insert real rows on prod.
- [ ] Confirm prod's `app_permission`/`app_role` `APP_ID` anchor (`keuangan.ledger.view`) still resolves to the same `app_id` as dev before running the PROD block — same portability caveat as v21/v23.
- [ ] Investigate how the permission catalog came to already be fully seeded on dev (all 273 keys, plus grants for 6 of 9 roles) without a migration doc recording it — either an earlier session ran this and didn't leave a doc behind, or it was seeded another way. Worth a note in this repo's history so the next person doesn't repeat this same discovery from scratch.
