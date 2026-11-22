# Aluria Schema Addendum — v47: seed permission keys referenced by code but missing from the catalog

**Purpose.** The backend (`userHasPermission(...)`) and the FE (`settings.*.view` gates) reference permission keys that did not exist in `app_permission`, so writes on several master endpoints returned 403 for everyone and the Settings → Master Data tabs (PPN, Ship Via, Purchase Status/Type, Sales Status, Sales Target) were hidden even for Business Owner. Also adds `settings.region.view` for the new Region settings tile.

All rows live in `CORE_SCHEMA` only. Idempotent (anti-join on `app_id` + `permission_key`). Every new key is granted to **Business Owner** only.

## DEV (`movira_core_dev`) — applied 2026-10-19

```sql
USE `movira_core_dev`;
SET @app_id  := 'appe16592dc7b88eab9';
SET @bo_role := (SELECT app_role_id FROM app_role WHERE app_id = @app_id AND role_name = 'Business Owner' LIMIT 1);

CREATE TEMPORARY TABLE tmp_new_perm (permission_key VARCHAR(100), module VARCHAR(60), label VARCHAR(150));
INSERT INTO tmp_new_perm VALUES
 ('master.document_center.manage','Master','Manage Document Center Master Data'),
 ('master.finance_category.manage','Master','Manage Finance Category Master Data'),
 ('master.payment_method.manage','Master','Manage Payment Method Master Data'),
 ('master.payment_term.manage','Master','Manage Payment Term Master Data'),
 ('master.product.create','Master','Create Product'),
 ('master.product.update','Master','Update Product'),
 ('master.product.delete','Master','Delete Product'),
 ('master.supplier.create','Master','Create Supplier'),
 ('master.supplier.update','Master','Update Supplier'),
 ('master.supplier.delete','Master','Delete Supplier'),
 ('master.sales_target.manage','Master','Manage Sales Target Master Data'),
 ('master.ship_via.manage','Master','Manage Ship Via Master Data'),
 ('master.shipment_period.manage','Master','Manage Shipment Period Master Data'),
 ('master.warehouse_location.manage','Master','Manage Warehouse Location Master Data'),
 ('reports.ar_ap_report.view','Reports','View AR/AP Report'),
 ('reports.balance_sheet.view','Reports','View Balance Sheet'),
 ('reports.cash_book.view','Reports','View Cash Book'),
 ('reports.cogs_report.view','Reports','View COGS Report'),
 ('reports.general_ledger.view','Reports','View General Ledger'),
 ('reports.profit_loss.view','Reports','View Profit & Loss'),
 ('reports.sales_report.view','Reports','View Sales Report'),
 ('sales_delivery.approve','Sales','Approve Sales Delivery'),
 ('sales_invoice.approve','Sales','Approve Sales Invoice'),
 ('sales_order.approve','Sales','Approve Sales Order'),
 ('sales_profit.approve','Sales','Approve Sales Profit'),
 ('sales_sppb.approve','Sales','Approve Sales SPPB'),
 ('settings.ppn_type.view','Settings','View Tax Type (PPN)'),
 ('settings.purchase_status.view','Settings','View Purchase Status'),
 ('settings.purchase_type.view','Settings','View Purchase Type'),
 ('settings.sales_status.view','Settings','View Sales Status'),
 ('settings.sales_target.view','Settings','View Sales Target'),
 ('settings.ship_via.view','Settings','View Ship Via'),
 ('settings.region.view','Settings','View Region');

INSERT INTO app_permission (permission_id, app_id, permission_key, module, label, description)
SELECT UUID(), @app_id, n.permission_key, n.module, n.label, ''
FROM tmp_new_perm n
WHERE NOT EXISTS (SELECT 1 FROM app_permission p WHERE p.app_id = @app_id AND p.permission_key = n.permission_key);

INSERT INTO app_role_permission (app_role_id, permission_id)
SELECT @bo_role, p.permission_id
FROM app_permission p
WHERE p.app_id = @app_id
  AND NOT EXISTS (SELECT 1 FROM app_role_permission rp WHERE rp.app_role_id = @bo_role AND rp.permission_id = p.permission_id);

DROP TEMPORARY TABLE tmp_new_perm;
```

## PROD (`movira_core`) — NOT yet applied
Same statements with `USE movira_core;` and `@app_id` set to Aluria's prod app_id.
