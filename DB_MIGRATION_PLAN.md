# DB Migration Plan — Legacy Single-Tenant → v2 Multi-Tenant

> Status: draft — domain order and table-naming decisions below are open questions, not final calls.
> Owner: backend team. Last updated: 2026-07-05.

## 1. Why this exists

The legacy API (`sales/`, `purchase/`, `warehouse/`, `finance/`, `master/`) reads and writes tables with **no `company_id` column at all** — every query is global. `v2/` introduces real multi-tenancy (`company_code` registration, JWT-scoped `company_id`, per-role permissions), but so far v2 only covers auth and the dashboard skeleton. The business tables still live in the old schema.

This plan moves each business domain, one at a time, into the tenant-scoped model without a big-bang rewrite and without breaking the legacy endpoints while the migration is in progress.

## 2. Guiding principles

1. **Additive first, destructive last.** Every schema change starts as `ADD COLUMN` with a backfill, never `ALTER`/`DROP` on day one. Legacy endpoints keep working untouched until the new v2 module is verified.
2. **One domain fully done before starting the next.** Don't have two domains half-migrated at once — it doubles the surface area for bugs with no compounding benefit yet.
3. **`company_id` is never optional once a domain is "migrated."** Every v2 query for that domain filters `WHERE company_id = '$company_id'` from the JWT, per `CODE_STANDARDS.md`. There is no code path that reads across companies.
4. **Permissions gate the endpoint, not just the dashboard widget.** Today only `dashboard.{domain}.view` exists. Each migrated module needs its own CRUD permission keys (see §6) enforced in the module itself — the dashboard widget is a side effect of migration, not the goal.
5. **Legacy stays read-only truth until cutover.** During a domain's migration window, the legacy endpoint is the system of record. The new v2 endpoint is verified against it before the frontend switches over.

## 3. Current table inventory (from legacy source, not guessed)

| Domain | Legacy tables (as referenced in code) |
|---|---|
| **Sales** | `salesOrder`, `salesOrderItem`, `salesOrderHistory`, `salesInvoice`, `salesInvoiceItem`, `salesDelivery`, `salesDeliveryItem`, `salesSPPB`, `salesSPPBItem`, `salesProfit`, `salesProfitItem`, `salesStatus`, `salesPPNType` |
| **Purchase** | `purchaseOrder`, `purchaseOrderItem`, `purchaseOrderHistory`, `purchaseInvoice`, `purchaseInvoiceItem`, `purchaseRecieve`, `purchaseReceiveItem`, `purchaseStatus`, `purchaseType` |
| **Warehouse** | `warehouse`, `warehouseTransaction`, `warehouseCategory` |
| **Finance** | `financeItem`, `financeLog`, `financeTransaction`, `finance_category`, `account_code`, `bank_account`, `beginning_balance`, `fixed_assets`, `currency`, `payment`, `term` |
| **Shared master data** | `customer`, `supplier`, `product`, `unitOfMeasure`, `shipVia`, `region`, `origin`, `gender`, `settingMenu`, `settingDetail` |

Finance's own queries (`financestastics.php`, `financeItem` joins) reach into `salesInvoice` and `purchaseInvoice` — confirming Finance must migrate **after** Sales and Purchase, not before.

## 4. Recommended domain order

```
1. Master data (customer, supplier, product, unitOfMeasure, shipVia, ...)
2. Sales
3. Purchase
4. Warehouse
5. Finance   ← depends on Sales + Purchase being done
```

**Rationale:**
- Master data goes first because Sales, Purchase, and Warehouse all foreign-key into `customer`/`supplier`/`product`. Migrating it first means every later domain migrates against already-tenant-scoped reference tables instead of a moving target.
- Sales and Purchase are peers in complexity (both have draft → approve → reject → revise workflows) — either could go second. Sales is listed first only because it's the domain most visible to the most roles (Admin Sales, Business Owner, Manager all care about it) and because Finance's outstanding-balance logic depends on `salesInvoice` before `purchaseInvoice`.
- Warehouse was the lowest-risk candidate discussed earlier (simple, mostly read/count queries, maps directly to Gudang + Kepala Gudang) but is sequenced after Sales/Purchase here because a Sales delivery order and a Purchase receipt both write warehouse stock movements — migrating Warehouse before its writers exist in v2 means half its inserts would still come from the legacy path.
- Finance is last because it's a read/aggregation layer over the other three.

**Open question for you:** if Warehouse's lower complexity matters more right now than this dependency ordering (e.g. you want a visible dashboard win fast), it can be pulled forward — its writes would just stay legacy-sourced (both v1 and v2 write paths hitting the same now-tenant-scoped table) until Sales/Purchase catch up. Flag if you want that instead.

## 5. Per-domain migration playbook (repeat for each domain)

### Step 1 — Schema (additive)
```sql
ALTER TABLE {legacy_table} ADD COLUMN company_id VARCHAR(50) NULL AFTER {id_column};
UPDATE {legacy_table} SET company_id = '{the-one-existing-company-id}' WHERE company_id IS NULL;
ALTER TABLE {legacy_table} ADD INDEX idx_{legacy_table}_company (company_id);
```
Leave `company_id` nullable for now — legacy inserts that don't set it (old code paths) must not start failing. Tighten to `NOT NULL` only after Step 5 (cutover).

### Step 2 — Decide table naming
Existing v2 tables in `APP_SCHEMA` already follow `aluria_{snake_case}` (e.g. `aluria_positions`). Two options per table:
- **(a) Rename during migration** — e.g. `salesOrder` → `aluria_sales_order`. Clean, matches `CODE_STANDARDS.md` naming rules, but requires a `RENAME TABLE` + updating every legacy query that touches it (they keep running against the old name until their own retirement, so either keep a view/alias or accept legacy code freezes on that table the day you rename).
- **(b) Keep legacy name, just add `company_id`** — zero risk to legacy code, but the new v2 module inherits camelCase table names that don't match the rest of the codebase's SQL conventions.

**Recommendation:** (b) for now, (a) as a cleanup pass after all domains are migrated and legacy endpoints are fully retired — renaming mid-migration adds risk for a cosmetic win.

### Step 3 — Build the v2 module
- `v2/{domain}/index.php` following the standard module pattern (`requireAuth()`, `$company_id` from JWT, `try/catch`, `jsonResponse`/`authResponse` shape).
- Every function takes `$company_id` and every query's `$where` starts with `"company_id = '$company_id'"` — no exceptions, per `CODE_STANDARDS.md` §16.
- Add a `requirePermission()` guard (new helper, see §6) at the top of dispatch, e.g. `requirePermission($permissions, 'sales.orders.view')`.

### Step 4 — Verify against legacy
Run the same query filtered by the one real `company_id` against both the legacy endpoint and the new v2 endpoint; diff the results. This is the only correctness check available without a staging dataset with multiple companies.

### Step 5 — Wire the dashboard widget
- Add `dashboard.{domain}.view` (already scaffolded) to real widget data in `v2/dashboard/index.php`.
- Add the domain's CRUD permission keys to `app_permission` and assign to roles in the Movira admin panel.

### Step 6 — Cutover
- Point frontend at the v2 endpoint.
- Mark the legacy file(s) with a deprecation notice (comment banner) rather than deleting immediately.
- Once frontend has been on v2 for a full billing cycle with no rollback need, delete the legacy file and tighten `company_id` to `NOT NULL`.

## 6. Permission model beyond the dashboard

Right now `app_permission` likely only needs to grow with `dashboard.*.view` keys. As each domain migrates, add the module's real CRUD keys, e.g.:

```
sales.orders.view
sales.orders.create
sales.orders.approve
sales.invoices.view
purchase.orders.view
purchase.orders.approve
warehouse.stock.view
warehouse.transactions.create
finance.outstanding.view
```

Add one shared guard to `v2/general.php` (or a new `v2/helpers/require_permission.php`) so every migrated module enforces it the same way:

```php
function requirePermission(array $permissions, string $permission_key): void {
    if (!isset($permissions[$permission_key])) {
        authResponse(403, 'You do not have permission to perform this action.', 'PERM_001');
        exit;
    }
}
```

This is what actually delivers "protect sensitive information only for several roles" — the dashboard widget gating is a preview of this, not a substitute for it.

## 7. Rollback

Every step in §5 up through Step 5 is non-destructive: the added `company_id` column can be dropped, the v2 module can be un-routed in `v2/index.php`, and the legacy endpoint never stopped running. Step 6 (cutover) is the only point of no return, and only after the deprecation window.

## 8. Open decisions

- [ ] Confirm domain order (§4) — proceed as recommended, or pull Warehouse forward?
- [ ] Table naming (§2) — keep legacy names for now, or rename immediately?
- [ ] Who/what assigns the new `dashboard.*.view` and CRUD permission keys to the 7 existing roles once they're added to `app_permission`?
- [ ] Confirm there is in fact only one company in production today (this plan's backfill step assumes a single `company_id` to backfill against).
