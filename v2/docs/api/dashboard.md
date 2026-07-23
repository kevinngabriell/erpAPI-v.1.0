# Dashboard API

> **Last updated:** 2026-07-23 22:30:00 WIB
> **Base URL:** `/api/v2/dashboard`
> **Auth:** Requires `Authorization: Bearer <access_token>`

A single `GET /api/v2/dashboard` endpoint that returns only the widgets the caller's **role** is permitted to see. Widget visibility is driven entirely by `dashboard.*` permission keys held by the caller's `app_role_id` (via `app_role_permission` / `app_permission` in `movira_core_dev`) — never by role name. A custom/generalist role sees the union of whatever `dashboard.*` permissions it holds; there is no hardcoded "if role is X, show Y."

This replaces the previous multi-endpoint dashboard (`/dashboard/overview`, `/dashboard/purchase-overview`, `/dashboard/top-*`, `/dashboard/outstanding`) entirely. See "Breaking changes" in the changelog.

---

## `GET /api/v2/dashboard`

#### Response `200 OK`

```json
{
  "status_code": 200,
  "status_message": "Dashboard found",
  "data": {
    "widgets": {
      "revenue_trend": [ { "month": "2026-02", "revenue": 120000000 } ],
      "pending_approvals": { "purchase_orders": 3, "sales_orders": 5 }
    }
  }
}
```

`data.widgets` only contains keys for `dashboard.*` permissions the caller's role actually holds. A role with none of these permissions gets `"widgets": {}` — this is a normal `200`, not an error.

#### Failure responses

| Code | `status_message` | When |
|---|---|---|
| 400 | `company_id is required` | JWT has no `company_id` |
| 400 | `No role assigned to this account yet.` | JWT has no `app_role_id` |
| 401 | — | Missing/invalid/expired Bearer token |
| 405 | `Method Not Allowed` | Any method other than `GET` |
| 500 | `Internal Server Error` | Server error |

---

## Widget catalog

Widget key (in `data.widgets`) ← `permission_key` ← what it returns.

### Business Owner

| Widget key | permission_key | Returns |
|---|---|---|
| `revenue_trend` | `dashboard.revenue_trend.view` | Array of `{ month, revenue }` for the last 6 months, from `sales_invoice`/`sales_invoice_item`. |
| `profit_summary` | `dashboard.profit_summary.view` | `{ this_month, last_month }`, each `{ profit, revenue, margin_percent }`, from `sales_profit_item` (`price - landed_cost`). |
| `cash_position` | `dashboard.cash_position.view` | `{ total_cash, accounts: [{ bank_account_id, bank_name, bank_number, balance }] }`. `balance` is `SUM(finance_transaction.amount)` per bank account — assumes `amount` is signed (positive = inflow, negative = outflow); flag if that's wrong. |
| `ar_ap_summary` | `dashboard.ar_ap_summary.view` | `{ receivables, payables }`, each bucketed `{ current, "30", "60", "90", "90+" }` by days overdue. |
| `pending_approvals` | `dashboard.pending_approvals.view` | `{ purchase_orders, sales_orders }` counts where `approved_by IS NULL`. Only these two tables have an approval column today — `sales_invoice`/`sales_delivery`/`sales_sppb` have none. |
| `top_partners` | `dashboard.top_partners.view` | `{ top_customers, top_suppliers }`, top 5 each by invoiced value this month. |
| `subscription_status` | `dashboard.subscription_status.view` | `{ days_remaining }` — read straight from the JWT claim, no query. |
| `pending_users` | `dashboard.pending_users.view` | `{ total_pending }` — `app_user` count where `account_status = 'pending'`. |

### Manager

| Widget key | permission_key | Returns |
|---|---|---|
| `exceptions` | `dashboard.exceptions.view` | `{ overdue_purchase_orders, delayed_sales_deliveries }` — POs past `eta_date` with no receive yet, deliveries past `eta_date`. |
| `approval_queue` | `dashboard.approval_queue.view` | Same shape as `pending_approvals`. Company-wide — no department scoping exists in the schema (open question, see below). |
| `activity_log` | `dashboard.activity_log.view` | Last 20 rows from `audit_log` for the company (`module, reference_id, action, action_by, action_at`). |

### Finance

| Widget key | permission_key | Returns |
|---|---|---|
| `buku_kas_snapshot` | `dashboard.buku_kas_snapshot.view` | `{ today_net, mtd_net }` — `SUM(finance_transaction.amount)` today / month-to-date. |
| `bank_balances` | `dashboard.bank_balances.view` | Same `accounts` array as `cash_position`. |
| `finance_entries_today` | `dashboard.finance_entries_today.view` | `{ total_entries, data }` — today's `finance_transaction` rows. |

### Accounting

| Widget key | permission_key | Returns |
|---|---|---|
| `pnl_snapshot` | `dashboard.pnl_snapshot.view` | `{ revenue, expense, profit }` this month, from `finance_transaction_detail.amount` grouped by `account_code.account_type` (`revenue`/`expense`). |
| `neraca_snapshot` | `dashboard.neraca_snapshot.view` | `{ asset, liability, equity }` — all-time cumulative `finance_transaction_detail.amount` by `account_type`. |
| `buku_besar_summary` | `dashboard.buku_besar_summary.view` | Array of `{ account_code_id, account_code, account_code_name, total }` — ledger rollup per account code. |
| `ar_aging` | `dashboard.ar_aging.view` | Drillable list — same fields as the old `outstanding?type=piutang`, plus a `bucket` field. |
| `ap_aging` | `dashboard.ap_aging.view` | Drillable list — same as `outstanding?type=hutang`, plus `bucket`. |
| `tax_due` | `dashboard.tax_due.view` | `{ sales_invoices_pending_faktur, purchase_invoices_pending_faktur }` — invoices where `tax_invoice_number IS NULL`. |

### Admin Purchase

| Widget key | permission_key | Returns |
|---|---|---|
| `open_pos` | `dashboard.open_pos.view` | `{ total_open, top_by_value }` — approved POs not yet invoiced. |
| `po_pending_approval` | `dashboard.po_pending_approval.view` | `{ total_pending }`. |
| `incoming_eta` | `dashboard.incoming_eta.view` | POs with `eta_date` in the next 7 days. |
| `invoice_matching` | `dashboard.invoice_matching.view` | `{ received_not_invoiced }` — POs received but not yet invoiced. Simplified from the spec's full 3-way-match idea: `purchase_invoice`/`purchase_receive` both link to `purchase_order` but not to each other, so item-level quantity mismatches aren't reconcilable from the schema as-is. |

### Admin Sales

| Widget key | permission_key | Returns |
|---|---|---|
| `open_sos` | `dashboard.open_sos.view` | `{ total_open }` — approved sales orders. |
| `so_pending_approval` | `dashboard.so_pending_approval.view` | `{ total_pending }`. |
| `delivery_status` | `dashboard.delivery_status.view` | `{ not_yet_delivered, delivered }` — approved SOs bucketed by whether a `sales_delivery` row exists yet. `sales_delivery` has no status column of its own. |
| `sppb_pending` | `dashboard.sppb_pending.view` | `{ total_pending }` — approved SOs with no `sales_sppb` yet. `sales_sppb` has no status column either. |
| `order_backlog` | `dashboard.order_backlog.view` | `{ backlog_value }` — value of approved, undelivered sales orders. |
| `top_customers_mtd` | `dashboard.top_customers_mtd.view` | Same list as `top_partners.top_customers` (reused, per spec). |

### Logistics / Import Coordinator

| Widget key | permission_key | Returns |
|---|---|---|
| `shipments_in_transit` | `dashboard.shipments_in_transit.view` | `{ inbound, outbound }` — POs/deliveries with vessel/container info, not yet received/closed. |
| `sppb_tracker` | `dashboard.sppb_tracker.view` | Latest 10 `sales_sppb` records. |
| `container_lookup` | `dashboard.container_lookup.view` | `{ inbound, outbound }` — all POs/deliveries that have a `container_number`. Returned as a list rather than a true search endpoint. |
| `at_risk_shipments` | `dashboard.at_risk_shipments.view` | POs with `eta_date` within 3 days, not yet received. |

### Gudang (Warehouse Staff)

| Widget key | permission_key | Returns |
|---|---|---|
| `stock_movements_today` | `dashboard.stock_movements_today.view` | `{ stock_in, stock_out, adjustment, transfer }` counts for today. |
| `pending_receive` | `dashboard.pending_receive.view` | Approved POs with no `purchase_receive` yet. |
| `pending_outbound` | `dashboard.pending_outbound.view` | Approved SOs with no `sales_delivery` yet. |

All Gudang widgets are **company-wide**, not location-scoped — `app_user` has no location/warehouse assignment column today, so there's no way to automatically restrict a Gudang user to "their" location.

### Kepala Gudang (Warehouse Supervisor)

| Widget key | permission_key | Returns |
|---|---|---|
| `stock_by_location` | `dashboard.stock_by_location.view` | Array of `{ location_id, location_name, total_stock }` from `warehouse_lot.end_balance` grouped by `warehouse_location`. |
| `warehouse_monthly_summary` | `dashboard.warehouse_monthly_summary.view` | Transaction counts this month, grouped by location and `transaction_type`. |

---

## Unavailable widgets (schema gaps)

These `permission_key`s from the original spec are **not implemented** — granting them to a role has no effect, since the widget key simply won't appear in `data.widgets`. Nothing fabricated; these need real schema work first:

| permission_key | Why it's blocked |
|---|---|
| `dashboard.dept_comparison.view` | No department concept exists anywhere in the schema — Manager is currently company-wide only. |
| `dashboard.payment_verification.view` | No manual bank-transfer-proof review queue table exists. |
| `dashboard.supplier_scorecard.view` | Spec itself marks this "nice-to-have, not MVP." |
| `dashboard.clearance_mode.view` | No broker-vs-in-house field exists on `purchase_order` or `sales_delivery`. |
| `dashboard.low_stock.view` | `product` has no reorder-point/minimum-stock column. |
| `dashboard.adjustment_approvals.view` | `warehouse_transaction` has no approval/sign-off workflow — every transaction commits immediately. |
| `dashboard.discrepancy_flags.view` | No physical-count / stock-opname table exists to compare against system stock. |

---

## Notes

- Every widget is company-scoped automatically via the JWT's `company_id` — never accept a `company_id` parameter from the client.
- Widget composition is entirely `dashboard.*`-permission-driven (see `GET /api/v2/account/my-permissions`). A generalist/custom role (e.g. a real client's "Admin Operasional" bundle) simply sees the union of whatever `dashboard.*` keys it holds — the endpoint never checks role name.
- Purchase-order "open"/"in transit"/"invoiced" filtering uses the same `status_id` UUID literals already relied on by the purchase-order approval flow (draft/approved/received/invoiced) — these are seeded, fixed system values, not company-configurable.
- Open questions carried over from the original spec that still need an answer from Kage: is Manager department-scoped or company-wide; does Kepala Gudang need a formal adjustment-approval workflow built; should Gudang users get a location assignment field on `app_user`.
